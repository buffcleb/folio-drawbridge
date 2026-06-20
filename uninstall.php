<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress calls this file directly during plugin deletion (not deactivation).
 * Only runs when 'sft_delete_on_uninstall' option is '1', giving admins a
 * safety gate before any data is permanently removed.
 *
 * When enabled, this deletes:
 *   - All five database tables (vaults, files, shares, otps, audit)
 *   - All encrypted files in SFT_VAULT_DIR
 *   - The encrypted master key stored in wp_options (sft_master_key)
 *   - All other plugin options
 *
 * When disabled (default), deactivation/deletion leaves all data intact so
 * it survives a reinstall.
 *
 * @package FolioDrawbridge
 */

// WordPress requires this guard — uninstall.php must only be called by WP.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$delete = get_option( 'sft_delete_on_uninstall', '0' );

if ( $delete !== '1' ) {
	return; // Data preserved — nothing to do.
}

// ─── Delete encrypted vault files and chunk staging area from disk ───────────

$vault_dir = WP_CONTENT_DIR . '/uploads/sft-vaults/';

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
$chunks_dir = WP_CONTENT_DIR . '/uploads/sft-chunks/';
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
	"{$wpdb->prefix}sft_audit",
	"{$wpdb->prefix}sft_otps",
	"{$wpdb->prefix}sft_shares",
	"{$wpdb->prefix}sft_files",
	"{$wpdb->prefix}sft_vaults",
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// ─── Remove all plugin options ────────────────────────────────────────────────

$options = [
	// Encryption.
	'sft_master_key',
	'sft_db_version',
	// Two-factor OTP.
	'sft_otp_ttl_minutes',
	'sft_otp_max_attempts',
	'sft_otp_cooldown_seconds',
	// File uploads.
	'sft_max_file_mb',
	'sft_allowed_file_extensions',
	'sft_storage_quota_mb',
	// Download limits.
	'sft_allow_unlimited_downloads',
	'sft_default_max_downloads',
	'sft_max_download_limit',
	// Link expiration.
	'sft_allow_no_expiry',
	'sft_default_expiry_days',
	'sft_max_expiry_days',
	// Notifications.
	'sft_notify_on_download',
	'sft_expiry_warning_days',
	// Audit log retention.
	'sft_audit_prune_enabled',
	'sft_audit_prune_days',
	// SIEM logging.
	'sft_siem_enabled',
	'sft_siem_log_path',
	'sft_siem_format',
	// Email templates.
	'sft_email_invite_subject',
	'sft_email_invite_body',
	'sft_email_otp_subject',
	'sft_email_otp_body',
	'sft_email_download_notification_subject',
	'sft_email_download_notification_body',
	'sft_email_expiry_warning_subject',
	'sft_email_expiry_warning_body',
	// Data & privacy.
	'sft_delete_on_uninstall',
];

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// ─── Remove any leftover transients ──────────────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sft_dl_%' OR option_name LIKE '_transient_timeout_sft_dl_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
