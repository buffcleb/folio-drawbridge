<?php
/**
 * Share management and two-factor access flow for WP Secure File Transfer Pro.
 *
 * Two-factor share flow:
 *   1. Authenticated user calls sft_create_share() → unique URL token generated,
 *      share record inserted, invite email sent to recipient.
 *   2. Recipient opens /?sft_share=TOKEN → enters their email address.
 *   3. sft_send_otp() generates a 6-digit OTP, hashes it, stores it in sft_otps,
 *      and emails the plaintext OTP to the recipient.
 *   4. Recipient submits the OTP → sft_verify_otp_for_share() checks the hash,
 *      enforces the attempt limit (5), and marks the OTP used on success.
 *   5. sft_create_download_session() issues a 32-byte random download token
 *      stored in a WordPress transient (30 min TTL). The token is returned to
 *      the browser and appended to each file download URL.
 *   6. Download requests pass the token via ?dt=TOKEN. sft_get_download_session()
 *      validates the transient, checks the share is still active, and the
 *      download count is within the allowed limit before sft_serve_file() streams
 *      the decrypted file to the browser.
 *
 * Share statuses: pending | active | expired | revoked
 *   pending  — invite sent, recipient has never completed OTP verification.
 *   active   — at least one successful OTP verification has occurred.
 *   expired  — past expires_at; set by the lifecycle cron.
 *   revoked  — manually revoked by the vault owner or an admin.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Share CRUD ───────────────────────────────────────────────────────────────

/**
 * Creates a share record and sends an invite email to the recipient.
 *
 * @param int    $vault_id         Vault being shared.
 * @param int    $created_by       WP user ID of the person sharing.
 * @param string $recipient_email  Recipient's email address.
 * @param int    $max_downloads    0 = unlimited; >0 = hard cap.
 * @param string $expires_at       MySQL datetime, or empty string for no expiry.
 * @return int|WP_Error Share ID on success, WP_Error on failure.
 */
function sft_create_share(
	int    $vault_id,
	int    $created_by,
	string $recipient_email,
	int    $max_downloads = 0,
	string $expires_at    = ''
) {
	global $wpdb;

	$vault = sft_get_vault( $vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		return new WP_Error( 'invalid_vault', 'Vault not found or not active.' );
	}

	$recipient_email = sanitize_email( $recipient_email );
	if ( ! is_email( $recipient_email ) ) {
		return new WP_Error( 'invalid_email', 'Recipient email address is invalid.' );
	}

	// Enforce global share limits unless the creator is an admin.
	$is_admin = sft_is_admin( $created_by );

	if ( ! $is_admin ) {
		// Download limit.
		if ( $max_downloads === 0 && get_option( 'sft_allow_unlimited_downloads', '1' ) === '0' ) {
			$max_downloads = (int) get_option( 'sft_default_max_downloads', 10 );
			if ( $max_downloads === 0 ) {
				$max_downloads = 1; // guard against misconfigured default
			}
		}
		// Only cap positive values — never override an explicitly-unlimited (0) share
		// when unlimited downloads are permitted.
		$ceiling = (int) get_option( 'sft_max_download_limit', 0 );
		if ( $ceiling > 0 && $max_downloads > $ceiling ) {
			$max_downloads = $ceiling;
		}

		// Expiry.
		$max_days = (int) get_option( 'sft_max_expiry_days', 0 );
		if ( ! $expires_at && get_option( 'sft_allow_no_expiry', '1' ) === '0' ) {
			$default_days = (int) get_option( 'sft_default_expiry_days', 30 );
			$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( "+{$default_days} days" ) );
		}
		if ( $expires_at && $max_days > 0 ) {
			$max_ts = strtotime( "+{$max_days} days" );
			if ( strtotime( $expires_at ) > $max_ts ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', $max_ts );
			}
		}
	}

	$token = sft_generate_token( 32 ); // 64 hex chars
	$now   = current_time( 'mysql', true );

	$result = $wpdb->insert(
		"{$wpdb->prefix}sft_shares",
		[
			'vault_id'        => $vault_id,
			'created_by'      => $created_by,
			'recipient_email' => $recipient_email,
			'share_token'     => $token,
			'status'          => 'pending',
			'max_downloads'   => $max_downloads,
			'download_count'  => 0,
			'expires_at'      => $expires_at ?: null,
			'created_at'      => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
	);

	if ( ! $result ) {
		return new WP_Error( 'db_error', 'Could not create share record.' );
	}

	$share_id = (int) $wpdb->insert_id;

	sft_log(
		SFT_EVT_SHARE_CREATED,
		$vault_id,
		$share_id,
		[
			'recipient'     => $recipient_email,
			'max_downloads' => $max_downloads,
			'expires_at'    => $expires_at ?: 'never',
		],
		$created_by
	);

	sft_send_share_invite( $share_id, $vault, $recipient_email, $created_by );

	return $share_id;
}

/**
 * Returns a share row by its database ID, or null.
 */
function sft_get_share( int $share_id ): ?object {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sft_shares WHERE id = %d", $share_id )
	);

	return $row ?: null;
}

/**
 * Returns a share row by its URL token, or null.
 */
function sft_get_share_by_token( string $token ): ?object {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sft_shares WHERE share_token = %s", $token )
	);

	return $row ?: null;
}

/**
 * Returns all share records for a vault.
 */
function sft_get_vault_shares( int $vault_id ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sft_shares WHERE vault_id = %d ORDER BY created_at DESC",
			$vault_id
		)
	) ?: [];
}

/**
 * Revokes a share, blocking all future OTP verification and downloads.
 */
function sft_revoke_share( int $share_id, int $actor_id ): bool {
	global $wpdb;

	$share = sft_get_share( $share_id );
	if ( ! $share ) {
		return false;
	}

	$result = $wpdb->update(
		"{$wpdb->prefix}sft_shares",
		[ 'status' => 'revoked' ],
		[ 'id' => $share_id ],
		[ '%s' ],
		[ '%d' ]
	);

	if ( $result !== false ) {
		sft_log( SFT_EVT_SHARE_REVOKED, (int) $share->vault_id, $share_id, [], $actor_id );
	}

	return $result !== false;
}

/**
 * Updates the download limit and/or expiry date of an existing active/pending share.
 *
 * @param int    $share_id      Share to update.
 * @param int    $max_downloads New download cap (0 = unlimited).
 * @param string $expires_at    New expiry as MySQL datetime, or '' to clear.
 * @param int    $actor_id      WP user ID performing the update (for audit log).
 * @return bool True on success.
 */
function sft_update_share( int $share_id, int $max_downloads, string $expires_at, int $actor_id ): bool {
	global $wpdb;

	$share = sft_get_share( $share_id );
	if ( ! $share ) {
		return false;
	}

	$result = $wpdb->update(
		"{$wpdb->prefix}sft_shares",
		[
			'max_downloads' => $max_downloads,
			'expires_at'    => $expires_at ?: null,
		],
		[ 'id' => $share_id ],
		[ '%d', '%s' ],
		[ '%d' ]
	);

	if ( $result !== false ) {
		sft_log(
			SFT_EVT_SHARE_CREATED,
			(int) $share->vault_id,
			$share_id,
			[
				'action'        => 'updated',
				'max_downloads' => $max_downloads,
				'expires_at'    => $expires_at ?: 'never',
			],
			$actor_id
		);
	}

	return $result !== false;
}

// ─── Invite email ─────────────────────────────────────────────────────────────

/**
 * Sends the initial share invite email to the recipient.
 * The link takes the recipient to the OTP request page — not directly to files.
 */
function sft_send_share_invite( int $share_id, object $vault, string $recipient_email, int $sender_id ): void {
	$share = sft_get_share( $share_id );
	if ( ! $share ) {
		return;
	}

	$sender      = get_userdata( $sender_id );
	$sender_name = $sender ? $sender->display_name : get_bloginfo( 'name' );
	$site_name   = get_bloginfo( 'name' );
	$share_url   = add_query_arg( 'sft_share', $share->share_token, home_url( '/' ) );

	$expires_note = $share->expires_at
		? 'This link expires on ' . gmdate( 'F j, Y \a\t g:i A T', strtotime( $share->expires_at ) ) . '.'
		: 'This link does not expire.';

	$subject = "[{$site_name}] {$sender_name} has shared a secure file vault with you";

	$body = "Hello,\n\n"
		. "{$sender_name} has shared a secure file vault with you on {$site_name}.\n\n"
		. "Vault: {$vault->name}\n\n"
		. "To access the files, click the link below. You will be asked to verify\n"
		. "your email address with a one-time code before downloading.\n\n"
		. "Access Link:\n{$share_url}\n\n"
		. "{$expires_note}\n\n"
		. "If you were not expecting this, you can safely ignore this email.\n\n"
		. "— {$site_name}";

	wp_mail(
		$recipient_email,
		$subject,
		$body,
		[ 'Content-Type: text/plain; charset=UTF-8' ]
	);
}

// ─── OTP flow ─────────────────────────────────────────────────────────────────

/**
 * Generates a new OTP for a share, stores the hash, and emails the code to the recipient.
 *
 * Validates that $email matches the share's recipient_email before sending.
 *
 * @return true|WP_Error
 */
function sft_send_otp( int $share_id, string $email ) {
	global $wpdb;

	$share = sft_get_share( $share_id );
	if ( ! $share ) {
		return new WP_Error( 'not_found', 'Share not found.' );
	}

	if ( ! in_array( $share->status, [ 'pending', 'active' ], true ) ) {
		return new WP_Error( 'share_inactive', 'This share link is no longer active.' );
	}

	if ( $share->expires_at && strtotime( $share->expires_at ) < time() ) {
		return new WP_Error( 'share_expired', 'This share link has expired.' );
	}

	// Email must match the intended recipient — case-insensitive.
	if ( strtolower( trim( $email ) ) !== strtolower( $share->recipient_email ) ) {
		sft_log( SFT_EVT_OTP_FAILED, (int) $share->vault_id, $share_id,
			[ 'reason' => 'email_mismatch', 'provided' => $email ] );
		// Return a generic error to avoid leaking the recipient address.
		return new WP_Error( 'email_mismatch', 'The email address you entered does not match our records for this share.' );
	}

	// Expire any previous unused OTPs for this share.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}sft_otps SET used_at = %s WHERE share_id = %d AND used_at IS NULL",
			current_time( 'mysql', true ),
			$share_id
		)
	);

	$otp     = sft_generate_otp();
	$ttl_min = (int) get_option( 'sft_otp_ttl_minutes', 15 );

	$wpdb->insert(
		"{$wpdb->prefix}sft_otps",
		[
			'share_id'   => $share_id,
			'email'      => strtolower( trim( $email ) ),
			'otp_hash'   => sft_hash_otp( $otp ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_min * 60 ),
			'used_at'    => null,
			'attempts'   => 0,
			'created_at' => current_time( 'mysql', true ),
		],
		[ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
	);

	sft_log( SFT_EVT_OTP_REQUESTED, (int) $share->vault_id, $share_id,
		[ 'email' => $email, 'ttl_minutes' => $ttl_min ] );

	sft_mail_otp( $email, $otp, $ttl_min );

	return true;
}

/**
 * Sends the OTP code via email.
 */
function sft_mail_otp( string $email, string $otp, int $ttl_min ): void {
	$site_name = get_bloginfo( 'name' );

	$subject = "[{$site_name}] Your secure file access code: {$otp}";

	$body = "Your one-time access code for {$site_name} is:\n\n"
		. "    {$otp}\n\n"
		. "This code is valid for {$ttl_min} minutes and can only be used once.\n\n"
		. "If you did not request this code, please ignore this email.\n\n"
		. "— {$site_name}";

	wp_mail( $email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
}

/**
 * Verifies a submitted OTP against the latest unused, unexpired record.
 *
 * Enforces a 5-attempt maximum before the OTP is permanently invalidated.
 *
 * @return true|WP_Error True on success, WP_Error describing the failure.
 */
function sft_verify_otp_for_share( int $share_id, string $email, string $otp ) {
	global $wpdb;

	$share = sft_get_share( $share_id );
	if ( ! $share || ! in_array( $share->status, [ 'pending', 'active' ], true ) ) {
		return new WP_Error( 'share_inactive', 'This share is no longer active.' );
	}

	// Fetch the most recent valid OTP record.
	$record = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sft_otps
			 WHERE share_id = %d AND email = %s AND used_at IS NULL
			 ORDER BY created_at DESC LIMIT 1",
			$share_id,
			strtolower( trim( $email ) )
		)
	);

	if ( ! $record ) {
		return new WP_Error( 'no_otp', 'No active verification code found. Please request a new code.' );
	}

	// Check expiry.
	if ( strtotime( $record->expires_at ) < time() ) {
		$wpdb->update(
			"{$wpdb->prefix}sft_otps",
			[ 'used_at' => current_time( 'mysql', true ) ],
			[ 'id' => $record->id ],
			[ '%s' ], [ '%d' ]
		);
		sft_log( SFT_EVT_OTP_EXPIRED, (int) $share->vault_id, $share_id );
		return new WP_Error( 'otp_expired', 'The verification code has expired. Please request a new one.' );
	}

	// Check attempt limit.
	$max_attempts = (int) get_option( 'sft_otp_max_attempts', 5 );
	if ( (int) $record->attempts >= $max_attempts ) {
		sft_log( SFT_EVT_OTP_FAILED, (int) $share->vault_id, $share_id,
			[ 'reason' => 'max_attempts_exceeded' ] );
		return new WP_Error( 'max_attempts', 'Too many incorrect attempts. Please request a new code.' );
	}

	// Increment attempt counter.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}sft_otps SET attempts = attempts + 1 WHERE id = %d",
			$record->id
		)
	);

	if ( ! sft_verify_otp( $otp, $record->otp_hash ) ) {
		sft_log( SFT_EVT_OTP_FAILED, (int) $share->vault_id, $share_id,
			[ 'reason' => 'wrong_code', 'attempt' => (int) $record->attempts + 1 ] );
		return new WP_Error( 'wrong_otp', 'The code you entered is incorrect.' );
	}

	// Mark OTP used.
	$wpdb->update(
		"{$wpdb->prefix}sft_otps",
		[ 'used_at' => current_time( 'mysql', true ) ],
		[ 'id' => $record->id ],
		[ '%s' ], [ '%d' ]
	);

	// Promote share to active if it was pending.
	if ( $share->status === 'pending' ) {
		$wpdb->update(
			"{$wpdb->prefix}sft_shares",
			[ 'status' => 'active', 'last_accessed' => current_time( 'mysql', true ) ],
			[ 'id' => $share_id ],
			[ '%s', '%s' ], [ '%d' ]
		);
	} else {
		$wpdb->update(
			"{$wpdb->prefix}sft_shares",
			[ 'last_accessed' => current_time( 'mysql', true ) ],
			[ 'id' => $share_id ],
			[ '%s' ], [ '%d' ]
		);
	}

	sft_log( SFT_EVT_OTP_SUCCESS, (int) $share->vault_id, $share_id,
		[ 'email' => $email ] );

	return true;
}

// ─── Download session ─────────────────────────────────────────────────────────

/**
 * Issues a short-lived download session token (WordPress transient, 30 min).
 *
 * Returns the random token string. The caller appends it to download URLs as ?dt=TOKEN.
 */
function sft_create_download_session( int $share_id ): string {
	$token = sft_generate_token( 32 );

	set_transient(
		'sft_dl_' . hash( 'sha256', $token ),
		[ 'share_id' => $share_id, 'created' => time() ],
		1800 // 30 minutes
	);

	return $token;
}

/**
 * Retrieves and validates a download session from a token.
 *
 * Returns the session data array on success, or null if invalid/expired.
 */
function sft_get_download_session( string $token ): ?array {
	$data = get_transient( 'sft_dl_' . hash( 'sha256', $token ) );

	return is_array( $data ) ? $data : null;
}

/**
 * Increments the download counter for a share and returns the updated count.
 */
function sft_increment_download_count( int $share_id ): int {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}sft_shares SET download_count = download_count + 1 WHERE id = %d",
			$share_id
		)
	);

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT download_count FROM {$wpdb->prefix}sft_shares WHERE id = %d", $share_id )
	);
}

/**
 * Retroactively applies current global download and expiry limits to all active/pending shares.
 *
 * Skips shares owned by SFT admins. Returns the count of updated shares.
 */
function sft_enforce_share_limits(): int {
	global $wpdb;

	$allow_unlimited = get_option( 'sft_allow_unlimited_downloads', '1' ) === '1';
	$ceiling         = (int) get_option( 'sft_max_download_limit', 0 );
	$allow_no_expiry = get_option( 'sft_allow_no_expiry', '1' ) === '1';
	$max_days        = (int) get_option( 'sft_max_expiry_days', 0 );
	$default_dl      = (int) get_option( 'sft_default_max_downloads', 10 );
	$default_expiry  = (int) get_option( 'sft_default_expiry_days', 30 );

	$shares = $wpdb->get_results(
		"SELECT s.*, v.owner_id FROM {$wpdb->prefix}sft_shares s
		 JOIN {$wpdb->prefix}sft_vaults v ON v.id = s.vault_id
		 WHERE s.status IN ('pending','active')"
	);

	$updated = 0;

	foreach ( $shares as $share ) {
		if ( sft_is_admin( (int) $share->owner_id ) ) {
			continue;
		}

		$new_max_dl  = (int) $share->max_downloads;
		$new_expires = $share->expires_at;
		$changed     = false;

		// Enforce download ceiling.
		if ( ! $allow_unlimited && $new_max_dl === 0 ) {
			$new_max_dl = $default_dl;
			$changed    = true;
		}
		if ( $ceiling > 0 && ( $new_max_dl === 0 || $new_max_dl > $ceiling ) ) {
			$new_max_dl = $ceiling;
			$changed    = true;
		}

		// Enforce expiry.
		$max_ts = $max_days > 0 ? strtotime( "+{$max_days} days" ) : 0;
		if ( ! $allow_no_expiry && ! $new_expires ) {
			$new_expires = gmdate( 'Y-m-d H:i:s', strtotime( "+{$default_expiry} days" ) );
			$changed     = true;
		}
		if ( $max_ts > 0 && $new_expires && strtotime( $new_expires ) > $max_ts ) {
			$new_expires = gmdate( 'Y-m-d H:i:s', $max_ts );
			$changed     = true;
		}

		if ( $changed ) {
			$wpdb->update(
				"{$wpdb->prefix}sft_shares",
				[
					'max_downloads' => $new_max_dl,
					'expires_at'    => $new_expires ?: null,
				],
				[ 'id' => (int) $share->id ],
				[ '%d', '%s' ],
				[ '%d' ]
			);
			++$updated;
		}
	}

	return $updated;
}

/**
 * Returns true if the share is accessible (active, not expired, not over download limit).
 */
function sft_share_is_accessible( object $share ): bool {
	if ( ! in_array( $share->status, [ 'pending', 'active' ], true ) ) {
		return false;
	}

	if ( $share->expires_at && strtotime( $share->expires_at ) < time() ) {
		return false;
	}

	if ( $share->max_downloads > 0 && $share->download_count >= $share->max_downloads ) {
		return false;
	}

	return true;
}
