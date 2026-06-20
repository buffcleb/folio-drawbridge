<?php
/**
 * Lifecycle management for Folio Drawbridge.
 *
 * A WP-Cron event ('sft_lifecycle_cron') runs hourly and:
 *   1. Marks vaults past their expires_at as 'expired'.
 *   2. Marks shares past their expires_at as 'expired'.
 *   3. Deletes OTP records older than 24 hours (used or expired).
 *   4. Optionally auto-prunes audit log entries beyond the retention window.
 *
 * All expiry actions write audit events so the record is complete.
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Cron registration ────────────────────────────────────────────────────────

add_action( 'sft_lifecycle_cron', 'sft_run_lifecycle' );

function sft_schedule_lifecycle_cron(): void {
	if ( ! wp_next_scheduled( 'sft_lifecycle_cron' ) ) {
		wp_schedule_event( time(), 'hourly', 'sft_lifecycle_cron' );
	}
}

// ─── Main lifecycle callback ──────────────────────────────────────────────────

/**
 * Orchestrates all periodic cleanup tasks.
 * Called by WP-Cron hourly via the 'sft_lifecycle_cron' hook.
 */
function sft_run_lifecycle(): void {
	sft_expire_vaults();
	sft_expire_shares();
	sft_send_expiry_warnings();
	sft_cleanup_otps();
	sft_auto_prune_audit();
	sft_cleanup_orphaned_chunks();
}

// ─── Vault expiry ─────────────────────────────────────────────────────────────

/**
 * Finds active vaults whose expires_at is in the past and marks them 'expired'.
 * Logs one audit event per vault expired.
 */
function sft_expire_vaults(): int {
	global $wpdb;

	$expired = $wpdb->get_results(
		"SELECT id, name FROM {$wpdb->prefix}sft_vaults
		 WHERE status = 'active'
		   AND expires_at IS NOT NULL
		   AND expires_at < UTC_TIMESTAMP()"
	) ?: [];

	$count = 0;
	foreach ( $expired as $vault ) {
		$wpdb->update(
			"{$wpdb->prefix}sft_vaults",
			[ 'status' => 'expired', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $vault->id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		sft_log( SFT_EVT_VAULT_EXPIRED, (int) $vault->id, null,
			[ 'name' => $vault->name ], null );

		$count++;
	}

	return $count;
}

// ─── Share expiry ─────────────────────────────────────────────────────────────

/**
 * Finds active/pending shares whose expires_at is in the past and marks them 'expired'.
 * Logs one audit event per share expired.
 */
function sft_expire_shares(): int {
	global $wpdb;

	$expired = $wpdb->get_results(
		"SELECT id, vault_id, recipient_email FROM {$wpdb->prefix}sft_shares
		 WHERE status IN ('pending','active')
		   AND expires_at IS NOT NULL
		   AND expires_at < UTC_TIMESTAMP()"
	) ?: [];

	$count = 0;
	foreach ( $expired as $share ) {
		$wpdb->update(
			"{$wpdb->prefix}sft_shares",
			[ 'status' => 'expired' ],
			[ 'id' => $share->id ],
			[ '%s' ],
			[ '%d' ]
		);

		sft_log( SFT_EVT_SHARE_EXPIRED, (int) $share->vault_id, (int) $share->id,
			[ 'recipient' => $share->recipient_email ], null );

		$count++;
	}

	return $count;
}

// ─── OTP cleanup ──────────────────────────────────────────────────────────────

/**
 * Deletes OTP records that are either used or more than 24 hours old.
 * Returns the number of rows deleted.
 */
function sft_cleanup_otps(): int {
	global $wpdb;

	$result = $wpdb->query(
		"DELETE FROM {$wpdb->prefix}sft_otps
		 WHERE used_at IS NOT NULL
		    OR created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
	);

	return $result === false ? 0 : (int) $result;
}

// ─── Audit log auto-prune ─────────────────────────────────────────────────────

/**
 * Prunes audit entries older than the configured retention window.
 * Only runs if 'sft_audit_prune_enabled' is '1' in wp_options.
 */
function sft_auto_prune_audit(): int {
	if ( get_option( 'sft_audit_prune_enabled', '0' ) !== '1' ) {
		return 0;
	}

	$days = (int) get_option( 'sft_audit_prune_days', 365 );
	if ( $days < 1 ) {
		return 0;
	}

	return sft_prune_audit_log( $days );
}

// ─── Share expiry warnings ────────────────────────────────────────────────────

/**
 * Emails vault owners for share links expiring within the configured warning window.
 * Only runs when sft_expiry_warning_days > 0 (0 = disabled).
 * Sets expiry_warning_sent = 1 on each share after the email is sent.
 *
 * @return int Number of warnings sent.
 */
function sft_send_expiry_warnings(): int {
	global $wpdb;

	$warning_days = (int) get_option( 'sft_expiry_warning_days', 0 );
	if ( $warning_days < 1 ) {
		return 0;
	}

	$shares = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sft_shares
			 WHERE status IN ('pending','active')
			   AND expires_at IS NOT NULL
			   AND expires_at > UTC_TIMESTAMP()
			   AND expires_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   AND expiry_warning_sent = 0",
			$warning_days
		)
	) ?: [];

	foreach ( $shares as $share ) {
		sft_send_expiry_warning( $share );
	}

	return count( $shares );
}
