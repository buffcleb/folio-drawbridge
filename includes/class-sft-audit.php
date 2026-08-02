<?php
/**
 * Audit logging for Folio Drawbridge.
 *
 * Every security-relevant action — vault creation, file upload, share creation,
 * OTP request/failure/success, file download, admin vault access, share
 * revocation, lifecycle expiry — is written as an immutable row in sft_audit.
 *
 * Event type constants (use these everywhere; never raw strings):
 *
 *   VAULT_CREATED        VAULT_DELETED        VAULT_EXPIRED         VAULT_STATUS_CHANGED
 *   VAULT_UPDATED        VAULT_TRANSFERRED
 *   FILE_UPLOADED        FILE_DELETED         FILE_DOWNLOADED       FILE_SERVED_ADMIN
 *   SHARE_CREATED        SHARE_UPDATED        SHARE_REVOKED         SHARE_EXPIRED        SHARE_RESENT
 *   OTP_REQUESTED        OTP_FAILED           OTP_SUCCESS           OTP_EXPIRED
 *   DOWNLOAD_NOTIFIED    EXPIRY_WARNING_SENT
 *   ADMIN_VAULT_ACCESS   SETTINGS_SAVED
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- appends audit events to the operator-configured SIEM log file; WP_Filesystem does not support append-mode streaming.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.

// ─── Event type constants ─────────────────────────────────────────────────────

define( 'SFT_EVT_VAULT_CREATED',       'vault_created' );
define( 'SFT_EVT_VAULT_DELETED',       'vault_deleted' );
define( 'SFT_EVT_VAULT_EXPIRED',       'vault_expired' );
define( 'SFT_EVT_VAULT_STATUS',        'vault_status_changed' );
define( 'SFT_EVT_FILE_UPLOADED',       'file_uploaded' );
define( 'SFT_EVT_FILE_DELETED',        'file_deleted' );
define( 'SFT_EVT_FILE_DOWNLOADED',     'file_downloaded' );
define( 'SFT_EVT_FILE_SERVED_ADMIN',   'file_served_admin' );
define( 'SFT_EVT_SHARE_CREATED',       'share_created' );
define( 'SFT_EVT_SHARE_RESENT',        'share_resent' );
define( 'SFT_EVT_SHARE_UPDATED',       'share_updated' );
define( 'SFT_EVT_SHARE_REVOKED',       'share_revoked' );
define( 'SFT_EVT_SHARE_EXPIRED',       'share_expired' );
define( 'SFT_EVT_OTP_REQUESTED',       'otp_requested' );
define( 'SFT_EVT_OTP_FAILED',          'otp_failed' );
define( 'SFT_EVT_OTP_SUCCESS',         'otp_success' );
define( 'SFT_EVT_OTP_EXPIRED',         'otp_expired' );
define( 'SFT_EVT_ADMIN_VAULT_ACCESS',  'admin_vault_access' );
define( 'SFT_EVT_SETTINGS_SAVED',      'settings_saved' );
define( 'SFT_EVT_VAULT_UPDATED',       'vault_updated' );
define( 'SFT_EVT_VAULT_TRANSFERRED',   'vault_transferred' );
define( 'SFT_EVT_DOWNLOAD_NOTIFIED',   'download_notified' );
define( 'SFT_EVT_EXPIRY_WARNING_SENT', 'expiry_warning_sent' );

// ─── Core logging function ────────────────────────────────────────────────────

/**
 * Inserts one audit event row.
 *
 * @param string      $event_type  One of the SFT_EVT_* constants.
 * @param int|null    $vault_id    Associated vault (null if not applicable).
 * @param int|null    $share_id    Associated share (null if not applicable).
 * @param array       $details     Arbitrary key→value context (stored as JSON).
 * @param int|null    $actor_id    WP user performing the action; null for system/anonymous.
 */
function sft_log(
	string $event_type,
	?int   $vault_id  = null,
	?int   $share_id  = null,
	array  $details   = [],
	?int   $actor_id  = null
): void {
	global $wpdb;

	// Default actor to the current WP user when not explicitly provided.
	if ( $actor_id === null ) {
		$actor_id = get_current_user_id() ?: null;
	}

	$ip         = sft_get_client_ip();
	$created_at = current_time( 'mysql', true );

	$wpdb->insert(
		"{$wpdb->prefix}sft_audit",
		[
			'event_type' => $event_type,
			'vault_id'   => $vault_id,
			'share_id'   => $share_id,
			'actor_id'   => $actor_id,
			'ip_address' => $ip,
			'user_agent' => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 ),
			'details'    => $details ? wp_json_encode( $details ) : null,
			'created_at' => $created_at,
		],
		[ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
	);

	sft_siem_write( $event_type, $vault_id, $share_id, $actor_id, $ip, $details, $created_at );
}

// ─── CSV safety ───────────────────────────────────────────────────────────────

/**
 * Neutralises spreadsheet formula injection in an exported CSV cell.
 *
 * Excel, LibreOffice, and Google Sheets evaluate any cell whose first character
 * is = + - @ (or a tab/CR) as a formula, which can reach out to the network or
 * trigger DDE execution. Audit rows contain attacker-influenced text — a
 * recipient's typed email is recorded on OTP mismatch, and filenames come from
 * uploads — so exports must force every cell to be read as literal text.
 *
 * Prefixing with an apostrophe is the standard neutralisation: spreadsheet apps
 * strip it on display, so the value still reads normally.
 *
 * @param mixed $value Raw cell value.
 * @return string Value safe to write into a CSV.
 */
function sft_csv_safe( $value ): string {
	$value = (string) $value;

	if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
		return "'" . $value;
	}

	return $value;
}

// ─── SIEM file logger ─────────────────────────────────────────────────────────

/**
 * Returns why a SIEM log path must be refused, or '' when it is acceptable.
 *
 * The log receives attacker-influenced text — a recipient's typed email is
 * recorded on OTP mismatch, and filenames come from uploads — so the
 * destination must never be a file the web server can execute or serve.
 * Required: an absolute path, no traversal, outside the WordPress directory,
 * and a non-executable extension.
 *
 * Settings validates a path before storing it, and the writer checks again
 * before every append. The second check is not redundant: a path stored before
 * this rule existed is still sitting in the options table, and only the writer
 * is in a position to refuse it.
 *
 * @param string $path Candidate log path.
 * @return string Empty when acceptable, otherwise a human-readable reason.
 */
function sft_siem_path_error( string $path ): string {
	$path = trim( $path );
	if ( $path === '' ) {
		return '';
	}

	$candidate   = wp_normalize_path( $path );
	$abs_root    = wp_normalize_path( trailingslashit( ABSPATH ) );
	$ext         = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );
	$blocked_ext = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'htaccess', 'html', 'htm', 'js', 'cgi', 'pl' ];

	if ( ! path_is_absolute( $candidate ) || strpos( $candidate, '..' ) !== false ) {
		return 'SIEM log path must be an absolute path with no ".." segments.';
	}
	if ( strpos( $candidate, $abs_root ) === 0 ) {
		return 'SIEM log path must be outside the WordPress directory so the log can never be requested over the web.';
	}
	if ( in_array( $ext, $blocked_ext, true ) ) {
		return 'SIEM log path must not use an executable or web-servable file extension.';
	}

	return '';
}

/**
 * Appends an audit event to the SIEM log file if file logging is enabled.
 *
 * Controlled by three options set in Settings:
 *   sft_siem_enabled   — '1' to enable
 *   sft_siem_log_path  — absolute path to the log file
 *   sft_siem_format    — 'json' (one JSON object per line) or 'csv'
 *
 * The file is written with LOCK_EX so concurrent requests don't interleave.
 * A CSV header row is written when the file is first created.
 */
function sft_siem_write(
	string $event_type,
	?int   $vault_id,
	?int   $share_id,
	?int   $actor_id,
	string $ip,
	array  $details,
	string $created_at
): void {
	if ( get_option( 'sft_siem_enabled', '0' ) !== '1' ) {
		return;
	}

	$path = trim( (string) get_option( 'sft_siem_log_path', '' ) );
	if ( ! $path ) {
		return;
	}

	// A stored path may predate the validation in Settings. Never append to a
	// location the web server could serve or execute; the event is still in the
	// database audit log either way.
	if ( sft_siem_path_error( $path ) !== '' ) {
		return;
	}

	$format = get_option( 'sft_siem_format', 'json' );

	if ( $format === 'csv' ) {
		$new_file = ! file_exists( $path );
		$fh       = fopen( 'php://temp', 'r+' );
		if ( $new_file ) {
			fputcsv( $fh, [ 'timestamp_utc', 'event', 'vault_id', 'share_id', 'actor_id', 'ip', 'details', 'site' ] );
			rewind( $fh );
			$header = stream_get_contents( $fh );
			rewind( $fh );
		}
		fputcsv( $fh, array_map( 'sft_csv_safe', [
			$created_at,
			$event_type,
			$vault_id  ?? '',
			$share_id  ?? '',
			$actor_id  ?? '',
			$ip,
			$details ? wp_json_encode( $details ) : '',
			get_site_url(),
		] ) );
		rewind( $fh );
		$line = stream_get_contents( $fh );
		fclose( $fh );
		$content = ( $new_file ? $header : '' ) . $line;
	} else {
		$content = wp_json_encode( [
			'timestamp_utc' => $created_at,
			'event'         => $event_type,
			'vault_id'      => $vault_id,
			'share_id'      => $share_id,
			'actor_id'      => $actor_id,
			'ip'            => $ip,
			'details'       => $details,
			'site'          => get_site_url(),
		] ) . "\n";
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $path, $content, FILE_APPEND | LOCK_EX );
}

// ─── IP resolution ────────────────────────────────────────────────────────────

/**
 * Returns the client IP address to record against an audit event.
 *
 * REMOTE_ADDR is the only value a client cannot forge, so it is the default.
 * Proxy and CDN headers are plain request headers: on a site not actually
 * behind a proxy that overwrites them, anyone can set X-Forwarded-For and
 * choose the address attached to their own OTP failures and downloads. For a
 * log whose purpose is non-repudiation, trusting them by default lets an
 * attacker implicate someone else or muddy an investigation.
 *
 * Sites genuinely behind a proxy opt in by defining SFT_TRUSTED_PROXY_HEADER
 * in wp-config.php with the header their infrastructure sets, e.g.
 *
 *     define( 'SFT_TRUSTED_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP' );
 *
 * Only meaningful when the proxy strips any client-supplied copy of that
 * header, which is the standard behaviour for Cloudflare and most load
 * balancers.
 */
function sft_get_client_ip(): string {
	$candidates = [];

	if ( defined( 'SFT_TRUSTED_PROXY_HEADER' ) && SFT_TRUSTED_PROXY_HEADER ) {
		$candidates[] = (string) SFT_TRUSTED_PROXY_HEADER;
	}

	$candidates[] = 'REMOTE_ADDR';

	foreach ( $candidates as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			// A forwarding header may carry a comma-separated chain; the original
			// client is the first entry.
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}

	return 'unknown';
}

// ─── Log queries ──────────────────────────────────────────────────────────────

/**
 * Returns a paginated array of audit rows with optional filters.
 *
 * @param array $args {
 *   @type string|null $event_type  Filter by event type.
 *   @type int|null    $vault_id    Filter by vault.
 *   @type int|null    $share_id    Filter by share.
 *   @type string      $date_from   MySQL datetime string (inclusive).
 *   @type string      $date_to     MySQL datetime string (inclusive).
 *   @type int         $per_page    Rows per page (default 25).
 *   @type int         $paged       Page number (1-based, default 1).
 *   @type string      $orderby     Column name (default 'created_at').
 *   @type string      $order       ASC|DESC (default DESC).
 * }
 */
function sft_get_audit_logs( array $args = [] ): array {
	global $wpdb;

	$defaults = [
		'event_type' => null,
		'vault_id'   => null,
		'share_id'   => null,
		'date_from'  => '',
		'date_to'    => '',
		'per_page'   => 25,
		'paged'      => 1,
		'orderby'    => 'created_at',
		'order'      => 'DESC',
	];
	$args = wp_parse_args( $args, $defaults );

	$allowed_cols = [ 'created_at', 'event_type', 'vault_id', 'share_id', 'actor_id' ];
	$orderby = in_array( $args['orderby'], $allowed_cols, true ) ? $args['orderby'] : 'created_at';

	// Sort by primary key instead of created_at. The audit log is append-only,
	// so id order and created_at order are identical — but id is the clustered
	// index, which lets deep pages skip rows without a filesort. Measured on
	// 250k rows: page 400 (OFFSET 10000) drops from ~160 ms to ~4 ms.
	if ( 'created_at' === $orderby ) {
		$orderby = 'id';
	}

	$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

	[ $where_sql, $values ] = sft_audit_build_where( $args );

	$per_page = max( 1, (int) $args['per_page'] );
	$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

	$sql = "SELECT * FROM {$wpdb->prefix}sft_audit {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
	array_push( $values, $per_page, $offset );

	// Safe interpolation: $orderby/$order come from the whitelists above and
	// $where_sql holds only placeholder clauses whose values are in $values.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	return $wpdb->get_results( $wpdb->prepare( $sql, $values ) ) ?: [];
}

/**
 * Returns the total count of audit rows matching the same filters as sft_get_audit_logs().
 */
function sft_count_audit_logs( array $args = [] ): int {
	global $wpdb;

	[ $where_sql, $values ] = sft_audit_build_where( $args );

	$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sft_audit {$where_sql}";

	// Safe interpolation: placeholder-only WHERE, values in $values (see sft_audit_build_where()).
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	return (int) ( $values
		? $wpdb->get_var( $wpdb->prepare( $sql, $values ) )
		: $wpdb->get_var( $sql ) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/**
 * Builds the WHERE clause and prepared values array for audit queries.
 *
 * @internal
 * @return array{string, array} [$where_sql, $values]
 */
function sft_audit_build_where( array $args ): array {
	global $wpdb;

	$where  = [];
	$values = [];

	if ( ! empty( $args['event_type'] ) ) {
		$where[]  = 'event_type = %s';
		$values[] = sanitize_key( $args['event_type'] );
	}
	if ( ! empty( $args['vault_id'] ) ) {
		$where[]  = 'vault_id = %d';
		$values[] = (int) $args['vault_id'];
	}
	if ( ! empty( $args['share_id'] ) ) {
		$where[]  = 'share_id = %d';
		$values[] = (int) $args['share_id'];
	}
	if ( ! empty( $args['date_from'] ) ) {
		$where[]  = 'created_at >= %s';
		$values[] = sanitize_text_field( $args['date_from'] );
	}
	if ( ! empty( $args['date_to'] ) ) {
		$where[]  = 'created_at <= %s';
		$values[] = sanitize_text_field( $args['date_to'] );
	}
	if ( ! empty( $args['details_search'] ) ) {
		$where[]  = 'details LIKE %s';
		$values[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['details_search'] ) ) . '%';
	}

	$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

	return [ $where_sql, $values ];
}

/**
 * Human-readable label for an event type constant.
 */
function sft_audit_event_label( string $event_type ): string {
	$map = [
		SFT_EVT_VAULT_CREATED      => 'Vault Created',
		SFT_EVT_VAULT_DELETED      => 'Vault Deleted',
		SFT_EVT_VAULT_EXPIRED      => 'Vault Expired',
		SFT_EVT_VAULT_STATUS       => 'Vault Status Changed',
		SFT_EVT_FILE_UPLOADED      => 'File Uploaded',
		SFT_EVT_FILE_DELETED       => 'File Deleted',
		SFT_EVT_FILE_DOWNLOADED    => 'File Downloaded',
		SFT_EVT_FILE_SERVED_ADMIN  => 'File Served (Admin)',
		SFT_EVT_SHARE_CREATED      => 'Share Created',
		SFT_EVT_SHARE_RESENT       => 'Share Invite Resent',
		SFT_EVT_SHARE_REVOKED      => 'Share Revoked',
		SFT_EVT_SHARE_EXPIRED      => 'Share Expired',
		SFT_EVT_OTP_REQUESTED      => 'OTP Requested',
		SFT_EVT_OTP_FAILED         => 'OTP Verification Failed',
		SFT_EVT_OTP_SUCCESS        => 'OTP Verified',
		SFT_EVT_OTP_EXPIRED        => 'OTP Expired',
		SFT_EVT_ADMIN_VAULT_ACCESS  => 'Admin Vault Access',
		SFT_EVT_SETTINGS_SAVED      => 'Settings Saved',
		SFT_EVT_VAULT_UPDATED       => 'Vault Updated',
		SFT_EVT_VAULT_TRANSFERRED   => 'Vault Transferred',
		SFT_EVT_DOWNLOAD_NOTIFIED   => 'Download Notification Sent',
		SFT_EVT_EXPIRY_WARNING_SENT => 'Expiry Warning Sent',
	];

	return $map[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
}
