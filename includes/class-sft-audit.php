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
 *   VAULT_TRANSFERRED
 *   FILE_UPLOADED        FILE_DELETED         FILE_DOWNLOADED       FILE_SERVED_ADMIN
 *   SHARE_CREATED        SHARE_REVOKED        SHARE_EXPIRED         SHARE_RESENT
 *   OTP_REQUESTED        OTP_FAILED           OTP_SUCCESS           OTP_EXPIRED
 *   DOWNLOAD_NOTIFIED    EXPIRY_WARNING_SENT
 *   ADMIN_VAULT_ACCESS   SETTINGS_SAVED
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			'user_agent' => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ),
			'details'    => $details ? wp_json_encode( $details ) : null,
			'created_at' => $created_at,
		],
		[ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
	);

	sft_siem_write( $event_type, $vault_id, $share_id, $actor_id, $ip, $details, $created_at );
}

// ─── SIEM file logger ─────────────────────────────────────────────────────────

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
		fputcsv( $fh, [
			$created_at,
			$event_type,
			$vault_id  ?? '',
			$share_id  ?? '',
			$actor_id  ?? '',
			$ip,
			$details ? wp_json_encode( $details ) : '',
			get_site_url(),
		] );
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
 * Returns the most likely real client IP address.
 *
 * Checks common proxy/CDN headers in order of trust before falling back
 * to REMOTE_ADDR. Stored for forensic purposes only — not used for access
 * control decisions.
 */
function sft_get_client_ip(): string {
	$candidates = [
		'HTTP_CF_CONNECTING_IP', // Cloudflare
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
		'REMOTE_ADDR',
	];

	foreach ( $candidates as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			// X-Forwarded-For may be a comma-separated list; take the first.
			$ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
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
	$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

	[ $where_sql, $values ] = sft_audit_build_where( $args );

	$per_page = max( 1, (int) $args['per_page'] );
	$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

	$sql = "SELECT * FROM {$wpdb->prefix}sft_audit {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
	array_push( $values, $per_page, $offset );

	return $wpdb->get_results( $wpdb->prepare( $sql, $values ) ) ?: [];
}

/**
 * Returns the total count of audit rows matching the same filters as sft_get_audit_logs().
 */
function sft_count_audit_logs( array $args = [] ): int {
	global $wpdb;

	[ $where_sql, $values ] = sft_audit_build_where( $args );

	$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sft_audit {$where_sql}";

	return (int) ( $values
		? $wpdb->get_var( $wpdb->prepare( $sql, $values ) )
		: $wpdb->get_var( $sql ) );
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
