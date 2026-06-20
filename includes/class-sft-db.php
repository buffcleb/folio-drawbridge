<?php
/**
 * Database setup and plugin lifecycle hooks.
 *
 * Creates all required tables on activation via dbDelta (idempotent upgrades),
 * schedules WP-Cron events, and tears them down on deactivation.
 *
 * Tables:
 *   sft_vaults      — encrypted file vaults owned by authenticated users
 *   sft_files       — individual encrypted files within vaults
 *   sft_shares      — time-limited share records linking vaults to recipients
 *   sft_otps        — 2FA one-time passwords for share access verification
 *   sft_audit       — immutable audit log for all plugin events
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Activation ───────────────────────────────────────────────────────────────

register_activation_hook( SFT_DIR . 'folio-drawbridge.php', 'sft_activate' );

function sft_activate() {
	sft_create_tables();
	sft_ensure_vault_dir();
	sft_schedule_lifecycle_cron();
	flush_rewrite_rules();
}

// ─── Deactivation ─────────────────────────────────────────────────────────────

register_deactivation_hook( SFT_DIR . 'folio-drawbridge.php', 'sft_deactivate' );

function sft_deactivate() {
	wp_clear_scheduled_hook( 'sft_lifecycle_cron' );
	flush_rewrite_rules();
}

// ─── Table creation ───────────────────────────────────────────────────────────

function sft_create_tables() {
	global $wpdb;

	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// Vaults: the top-level container owned by a WordPress user.
	$sql_vaults = "CREATE TABLE {$wpdb->prefix}sft_vaults (
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
	$sql_files = "CREATE TABLE {$wpdb->prefix}sft_files (
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
	$sql_shares = "CREATE TABLE {$wpdb->prefix}sft_shares (
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
	$sql_otps = "CREATE TABLE {$wpdb->prefix}sft_otps (
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
	$sql_audit = "CREATE TABLE {$wpdb->prefix}sft_audit (
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
		KEY event_type (event_type),
		KEY vault_id (vault_id),
		KEY share_id (share_id),
		KEY created_at (created_at)
	) $charset;";

	dbDelta( [ $sql_vaults, $sql_files, $sql_shares, $sql_otps, $sql_audit ] );
}

// ─── Vault storage directory ──────────────────────────────────────────────────

function sft_ensure_vault_dir() {
	if ( ! is_dir( SFT_VAULT_DIR ) ) {
		wp_mkdir_p( SFT_VAULT_DIR );
	}

	// Block all direct HTTP access — encrypted files must only be served by PHP.
	$htaccess = SFT_VAULT_DIR . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}

	// Prevent directory listing.
	$index = SFT_VAULT_DIR . 'index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" );
	}
}

/**
 * Ensures the per-vault subdirectory exists and returns its path.
 * Encrypted files are stored in SFT_VAULT_DIR/{vault_id}/ for isolation.
 */
function sft_ensure_vault_subdir( int $vault_id ): string {
	sft_ensure_vault_dir();
	$dir = SFT_VAULT_DIR . $vault_id . '/';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

/**
 * Returns the absolute path to an encrypted file on disk.
 * Single source of truth for file path construction.
 */
function sft_vault_file_path( int $vault_id, string $stored_name ): string {
	return SFT_VAULT_DIR . $vault_id . '/' . $stored_name;
}

// ─── DB version migration ─────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'sft_maybe_upgrade_db' );

/**
 * Runs dbDelta() when the stored DB version is behind SFT_DB_VERSION.
 * Safe to call on every page load — dbDelta does nothing when the schema matches.
 */
function sft_maybe_upgrade_db(): void {
	if ( get_option( 'sft_db_version' ) !== SFT_DB_VERSION ) {
		sft_create_tables();
		update_option( 'sft_db_version', SFT_DB_VERSION );
	}
}

// ─── Audit log pruning ────────────────────────────────────────────────────────

/**
 * Deletes audit entries older than $days days. Returns the count deleted.
 */
function sft_prune_audit_log( int $days ): int {
	global $wpdb;

	$result = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}sft_audit WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		)
	);

	return $result === false ? 0 : (int) $result;
}
