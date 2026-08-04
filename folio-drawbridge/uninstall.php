<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress calls this file directly during plugin deletion (not deactivation).
 * Only runs when 'folio_drawbridge_delete_on_uninstall' option is '1', giving admins a
 * safety gate before any data is permanently removed.
 *
 * When enabled, this deletes:
 *   - All five database tables (vaults, files, shares, otps, audit)
 *   - All encrypted files in FOLIO_DRAWBRIDGE_VAULT_DIR
 *   - The encrypted master key stored in wp_options (folio_drawbridge_master_key)
 *   - All other plugin options
 *
 * When disabled (default), deactivation/deletion leaves all data intact so
 * it survives a reinstall.
 *
 * @package Folio_Drawbridge
 */

// WordPress requires this guard — uninstall.php must only be called by WP.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery -- uninstall removes this plugin's own tables, options, and protected storage directory; WP_Filesystem credentials are unavailable in the uninstall context.

global $wpdb;

$delete = get_option( 'folio_drawbridge_delete_on_uninstall', '0' );

if ( $delete !== '1' ) {
	return; // Data preserved — nothing to do.
}

// ─── Delete encrypted vault files and chunk staging area from disk ───────────

$vault_dir = WP_CONTENT_DIR . '/uploads/folio-drawbridge-vaults/';

if ( is_dir( $vault_dir ) ) {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vault_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $files as $entry ) {
		if ( $entry->isFile() ) {
			unlink( $entry->getPathname() );
		} elseif ( $entry->isDir() ) {
			rmdir( $entry->getPathname() );
		}
	}

	rmdir( $vault_dir );
}

// Delete orphaned chunk upload staging area.
$chunks_dir = WP_CONTENT_DIR . '/uploads/folio-drawbridge-chunks/';
if ( is_dir( $chunks_dir ) ) {
	$chunk_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $chunks_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $chunk_files as $entry ) {
		if ( $entry->isFile() ) {
			unlink( $entry->getPathname() );
		} elseif ( $entry->isDir() ) {
			rmdir( $entry->getPathname() );
		}
	}
	rmdir( $chunks_dir );
}

// ─── Drop all plugin database tables ─────────────────────────────────────────

$tables = [
	"{$wpdb->prefix}folio_drawbridge_audit",
	"{$wpdb->prefix}folio_drawbridge_otps",
	"{$wpdb->prefix}folio_drawbridge_shares",
	"{$wpdb->prefix}folio_drawbridge_files",
	"{$wpdb->prefix}folio_drawbridge_vaults",
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// ─── Remove all plugin options ────────────────────────────────────────────────

$options = [
	// Encryption.
	'folio_drawbridge_master_key',
	'folio_drawbridge_db_version',
	// Two-factor OTP.
	'folio_drawbridge_otp_ttl_minutes',
	'folio_drawbridge_otp_max_attempts',
	'folio_drawbridge_otp_cooldown_seconds',
	// File uploads.
	'folio_drawbridge_max_file_mb',
	'folio_drawbridge_allowed_file_extensions',
	'folio_drawbridge_storage_quota_mb',
	// Download limits.
	'folio_drawbridge_allow_unlimited_downloads',
	'folio_drawbridge_default_max_downloads',
	'folio_drawbridge_max_download_limit',
	// Link expiration.
	'folio_drawbridge_allow_no_expiry',
	'folio_drawbridge_default_expiry_days',
	'folio_drawbridge_max_expiry_days',
	// Notifications.
	'folio_drawbridge_notify_on_download',
	'folio_drawbridge_expiry_warning_days',
	// Audit log retention.
	'folio_drawbridge_audit_prune_enabled',
	'folio_drawbridge_audit_prune_days',
	// SIEM logging.
	'folio_drawbridge_siem_enabled',
	'folio_drawbridge_siem_log_path',
	'folio_drawbridge_siem_format',
	// Email templates.
	'folio_drawbridge_email_invite_subject',
	'folio_drawbridge_email_invite_body',
	'folio_drawbridge_email_otp_subject',
	'folio_drawbridge_email_otp_body',
	'folio_drawbridge_email_download_notification_subject',
	'folio_drawbridge_email_download_notification_body',
	'folio_drawbridge_email_expiry_warning_subject',
	'folio_drawbridge_email_expiry_warning_body',
	// Data & privacy.
	'folio_drawbridge_delete_on_uninstall',
];

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// ─── Remove any leftover transients ──────────────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_folio_drawbridge_dl_%' OR option_name LIKE '_transient_timeout_folio_drawbridge_dl_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
