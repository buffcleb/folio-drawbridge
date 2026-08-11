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
 *   - All encrypted files in the plugin's uploads storage directory
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

/**
 * Removes every trace of this plugin from the database and disk.
 *
 * The work is wrapped in a function so its variables stay out of the global
 * scope — uninstall.php is included at global scope, so bare variables here
 * would become globals and could collide with anything else WordPress has
 * loaded.
 */
function folio_drawbridge_uninstall(): void {
	global $wpdb;

	if ( get_option( 'folio_drawbridge_delete_on_uninstall', '0' ) !== '1' ) {
		return; // Data preserved — nothing to do.
	}

	// ─── Delete this plugin's storage directory from disk ────────────────────────

	// The main plugin file is not loaded during uninstall, so the storage path is
	// resolved here rather than through folio_drawbridge_storage_dir(). The logic is
	// deliberately kept identical to that function: same option, same default, same
	// sanitising — a divergence would leave encrypted files behind.
	$folder = (string) get_option( 'folio_drawbridge_storage_dir', 'folio-drawbridge' );
	$folder = str_replace( [ '/', '\\', '.' ], '', sanitize_file_name( trim( $folder ) ) );
	if ( $folder === '' ) {
		$folder = 'folio-drawbridge';
	}

	$uploads     = wp_get_upload_dir();
	$storage_dir = trailingslashit( $uploads['basedir'] ) . $folder . '/';

	if ( is_dir( $storage_dir ) ) {
		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $storage_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry->isFile() || $entry->isLink() ) {
				unlink( $entry->getPathname() );
			} elseif ( $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			}
		}

		rmdir( $storage_dir );
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
		// %i quotes the table name as an identifier, so nothing is concatenated
		// into the statement even though these names are plugin constants.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
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
		// Storage.
		'folio_drawbridge_storage_dir',
		// Data & privacy.
		'folio_drawbridge_delete_on_uninstall',
	];

	foreach ( $options as $opt ) {
		delete_option( $opt );
	}

	// ─── Remove any leftover transients ──────────────────────────────────────────

	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_folio_drawbridge_dl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_folio_drawbridge_dl_' ) . '%'
	) );
}

folio_drawbridge_uninstall();
