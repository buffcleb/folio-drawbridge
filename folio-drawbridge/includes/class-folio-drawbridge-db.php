<?php
/**
 * Database setup and plugin lifecycle hooks.
 *
 * Creates all required tables on activation via dbDelta (idempotent upgrades),
 * schedules WP-Cron events, and tears them down on deactivation.
 *
 * Tables:
 *   folio_drawbridge_vaults      — encrypted file vaults owned by authenticated users
 *   folio_drawbridge_files       — individual encrypted files within vaults
 *   folio_drawbridge_shares      — time-limited share records linking vaults to recipients
 *   folio_drawbridge_otps        — 2FA one-time passwords for share access verification
 *   folio_drawbridge_audit       — immutable audit log for all plugin events
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams encrypted files in fixed-size chunks and manages its own protected storage directory; WP_Filesystem cannot stream and buffers whole files in memory.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.

// ─── Activation ───────────────────────────────────────────────────────────────

register_activation_hook( FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'folio-drawbridge.php', 'folio_drawbridge_activate' );

function folio_drawbridge_activate() {
	folio_drawbridge_create_tables();
	folio_drawbridge_ensure_vault_dir();
	folio_drawbridge_schedule_lifecycle_cron();
	flush_rewrite_rules();
}

// ─── Deactivation ─────────────────────────────────────────────────────────────

register_deactivation_hook( FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'folio-drawbridge.php', 'folio_drawbridge_deactivate' );

function folio_drawbridge_deactivate() {
	wp_clear_scheduled_hook( 'folio_drawbridge_lifecycle_sweep' );
	flush_rewrite_rules();
}

// ─── Table creation ───────────────────────────────────────────────────────────

function folio_drawbridge_create_tables() {
	global $wpdb;

	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Vaults: the top-level container owned by a WordPress user.
	$sql_vaults = "CREATE TABLE {$wpdb->prefix}folio_drawbridge_vaults (
		id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		owner_id    bigint(20) unsigned NOT NULL,
		name        varchar(255)        NOT NULL,
		description text,
		vault_salt  varchar(64)         NOT NULL,
		status      varchar(20)         NOT NULL DEFAULT 'active',
		expires_at  datetime            DEFAULT NULL,
		created_at  datetime            NOT NULL,
		updated_at  datetime            NOT NULL,
		PRIMARY KEY (id),
		KEY owner_id (owner_id),
		KEY status (status)
	) $charset;";

	// Files: individual AES-256-CBC encrypted files within a vault.
	$sql_files = "CREATE TABLE {$wpdb->prefix}folio_drawbridge_files (
		id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		vault_id      bigint(20) unsigned NOT NULL,
		original_name varchar(255)        NOT NULL,
		stored_name   varchar(64)         NOT NULL,
		mime_type     varchar(100)        NOT NULL DEFAULT 'application/octet-stream',
		file_size     bigint(20) unsigned NOT NULL DEFAULT 0,
		iv            varchar(32)         NOT NULL,
		uploaded_by   bigint(20) unsigned NOT NULL,
		uploaded_at   datetime            NOT NULL,
		PRIMARY KEY (id),
		KEY vault_id (vault_id)
	) $charset;";

	// Shares: a record granting a specific email address access to a vault.
	$sql_shares = "CREATE TABLE {$wpdb->prefix}folio_drawbridge_shares (
		id                    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		vault_id              bigint(20) unsigned NOT NULL,
		created_by            bigint(20) unsigned NOT NULL,
		recipient_email       varchar(255)        NOT NULL,
		share_token           varchar(64)         NOT NULL,
		status                varchar(20)         NOT NULL DEFAULT 'pending',
		max_downloads         int(11)             NOT NULL DEFAULT 0,
		download_count        int(11)             NOT NULL DEFAULT 0,
		expires_at            datetime            DEFAULT NULL,
		expiry_warning_sent   tinyint(1)          NOT NULL DEFAULT 0,
		created_at            datetime            NOT NULL,
		last_accessed         datetime            DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY share_token (share_token),
		KEY vault_id (vault_id),
		KEY status (status)
	) $charset;";

	// OTPs: hashed one-time passwords for 2FA share verification.
	$sql_otps = "CREATE TABLE {$wpdb->prefix}folio_drawbridge_otps (
		id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		share_id   bigint(20) unsigned NOT NULL,
		email      varchar(255)        NOT NULL,
		otp_hash   varchar(255)        NOT NULL,
		expires_at datetime            NOT NULL,
		used_at    datetime            DEFAULT NULL,
		attempts   tinyint(3) unsigned NOT NULL DEFAULT 0,
		created_at datetime            NOT NULL,
		PRIMARY KEY (id),
		KEY share_id (share_id)
	) $charset;";

	// Audit: append-only event log — never updated after insert.
	$sql_audit = "CREATE TABLE {$wpdb->prefix}folio_drawbridge_audit (
		id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_type  varchar(60)         NOT NULL,
		vault_id    bigint(20) unsigned DEFAULT NULL,
		share_id    bigint(20) unsigned DEFAULT NULL,
		actor_id    bigint(20) unsigned DEFAULT NULL,
		ip_address  varchar(45)         NOT NULL DEFAULT '',
		user_agent  varchar(500)        NOT NULL DEFAULT '',
		details     text,
		created_at  datetime            NOT NULL,
		PRIMARY KEY (id),
		KEY event_created (event_type, created_at),
		KEY vault_id (vault_id),
		KEY share_id (share_id),
		KEY created_at (created_at)
	) $charset;";

	dbDelta( [ $sql_vaults, $sql_files, $sql_shares, $sql_otps, $sql_audit ] );
}

// ─── Storage directories ──────────────────────────────────────────────────────

/** Folder name used when the site owner has not chosen one. */
const FOLIO_DRAWBRIDGE_DEFAULT_STORAGE_DIR = 'folio-drawbridge';

/**
 * Returns the folder name, directly under the uploads directory, that holds
 * everything this plugin writes.
 *
 * Site owners may change it, so the value is sanitised on the way out as well as
 * on the way in: a stored value predating validation, or one edited directly in
 * the database, must not be able to introduce path separators and escape the
 * uploads directory.
 */
function folio_drawbridge_storage_dir_name(): string {
	$name = (string) get_option( 'folio_drawbridge_storage_dir', FOLIO_DRAWBRIDGE_DEFAULT_STORAGE_DIR );
	$name = sanitize_file_name( trim( $name ) );

	// sanitize_file_name() strips slashes, but be explicit — this is the only
	// thing standing between an option value and an arbitrary write location.
	$name = str_replace( [ '/', '\\', '.' ], '', $name );

	return $name !== '' ? $name : FOLIO_DRAWBRIDGE_DEFAULT_STORAGE_DIR;
}

/**
 * Absolute path to this plugin's storage root, with a trailing slash.
 *
 * Resolved at runtime through wp_get_upload_dir() rather than built from
 * WP_CONTENT_DIR, because the uploads location is not fixed: it moves with the
 * UPLOADS constant, with a custom upload_path option, and per-site on multisite
 * (uploads/sites/N/). wp_get_upload_dir() is the non-creating variant, so
 * simply asking for the path has no side effects.
 */
function folio_drawbridge_storage_dir(): string {
	$uploads = wp_get_upload_dir();

	return trailingslashit( $uploads['basedir'] ) . folio_drawbridge_storage_dir_name() . '/';
}

/** Absolute path to the encrypted-file store, with a trailing slash. */
function folio_drawbridge_vault_dir(): string {
	return folio_drawbridge_storage_dir() . 'vaults/';
}

/** Absolute path to the chunked-upload staging area, with a trailing slash. */
function folio_drawbridge_chunks_dir(): string {
	return folio_drawbridge_storage_dir() . 'chunks/';
}

/** Absolute path to the SIEM log directory, with a trailing slash. */
function folio_drawbridge_logs_dir(): string {
	return folio_drawbridge_storage_dir() . 'logs/';
}

/**
 * Creates a storage directory and blocks direct HTTP access to it.
 *
 * Both server syntaxes are written: `Require all denied` is Apache 2.4, while
 * `Deny from all` covers 2.2. Sending only the latter — as this plugin did
 * previously — is silently ineffective on any modern Apache. Neither helps on
 * nginx, so the index.php stub and the fact that filenames are unguessable
 * random tokens remain the backstop there.
 *
 * @param string $dir Absolute directory path, trailing slash included.
 */
function folio_drawbridge_ensure_protected_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$htaccess = $dir . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents(
			$htaccess,
			"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
		);
	}

	// Prevent directory listing where .htaccess is not honoured.
	$index = $dir . 'index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" );
	}
}

/**
 * Creates the storage root and the vault directory, both protected.
 *
 * The root is guarded too, so a server that ignores the nested .htaccess still
 * refuses the parent.
 */
function folio_drawbridge_ensure_vault_dir() {
	folio_drawbridge_ensure_protected_dir( folio_drawbridge_storage_dir() );
	folio_drawbridge_ensure_protected_dir( folio_drawbridge_vault_dir() );
}

/**
 * Ensures the per-vault subdirectory exists and returns its path.
 * Encrypted files are stored in <vaults>/{vault_id}/ for isolation.
 */
function folio_drawbridge_ensure_vault_subdir( int $vault_id ): string {
	folio_drawbridge_ensure_vault_dir();
	$dir = folio_drawbridge_vault_dir() . $vault_id . '/';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

/**
 * Returns the absolute path to an encrypted file on disk.
 * Single source of truth for file path construction.
 */
function folio_drawbridge_vault_file_path( int $vault_id, string $stored_name ): string {
	return folio_drawbridge_vault_dir() . $vault_id . '/' . $stored_name;
}

// ─── DB version migration ─────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'folio_drawbridge_maybe_upgrade_db' );

/**
 * Runs dbDelta() when the stored DB version is behind FOLIO_DRAWBRIDGE_DB_VERSION.
 * Safe to call on every page load — dbDelta does nothing when the schema matches.
 */
function folio_drawbridge_maybe_upgrade_db(): void {
	if ( get_option( 'folio_drawbridge_db_version' ) === FOLIO_DRAWBRIDGE_DB_VERSION ) {
		return;
	}

	folio_drawbridge_create_tables();
	folio_drawbridge_drop_superseded_indexes();

	update_option( 'folio_drawbridge_db_version', FOLIO_DRAWBRIDGE_DB_VERSION );
}

/**
 * Removes indexes that a newer composite index has made redundant.
 *
 * dbDelta only ever adds keys, so a single-column index left behind after its
 * columns become the leftmost prefix of a composite one would keep costing
 * write time and space for nothing.
 *
 * folio_drawbridge_audit.event_type is covered by event_created (event_type, created_at).
 */
function folio_drawbridge_drop_superseded_indexes(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'folio_drawbridge_audit';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- $table is $wpdb->prefix plus a literal; index names are literals. Schema changes are the point of this function, and it runs once per version bump.
	$has_composite = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'event_created' ) );
	$has_old       = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'event_type' ) );

	if ( $has_composite && $has_old ) {
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX event_type" );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// ─── Audit log pruning ────────────────────────────────────────────────────────

/**
 * Deletes audit entries older than $days days. Returns the count deleted.
 */
function folio_drawbridge_prune_audit_log( int $days ): int {
	global $wpdb;

	$result = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}folio_drawbridge_audit WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		)
	);

	return $result === false ? 0 : (int) $result;
}
