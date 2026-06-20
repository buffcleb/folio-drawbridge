<?php
/**
 * Email notifications for Folio Drawbridge.
 *
 * Provides a lightweight template engine and two notification types:
 *
 *   sft_send_download_notification() — tells the vault owner when a recipient downloads a file.
 *   sft_send_expiry_warning()        — warns the vault owner before a share link expires.
 *
 * Template placeholders (all types):
 *   {site_name}          get_bloginfo('name')
 *   {vault_name}         Vault display name
 *   {recipient_email}    Share recipient's email address
 *   {owner_name}         Vault owner's display name
 *   {share_url}          The original invite URL for the share
 *   {expires_note}       Human-readable expiry sentence
 *
 * Download notification extras:
 *   {file_name}          Original filename that was downloaded
 *   {download_count}     Running download count for the share
 *   {recipient_ip}       IP address of the downloader
 *
 * Expiry warning extras:
 *   {expiry_date}        Formatted expiry date (site timezone)
 *   {days_until_expiry}  Whole number of days remaining
 *
 * Templates are loaded from wp_options (set via Settings → Email Templates).
 * An empty saved template falls back to the hardcoded defaults below.
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Template engine ──────────────────────────────────────────────────────────

/**
 * Returns the raw subject and body for a template type.
 * Falls back to hardcoded defaults when no custom template is saved.
 *
 * @param string $type  'invite' | 'otp' | 'download_notification' | 'expiry_warning'
 * @return array{subject: string, body: string}
 */
function sft_get_email_template( string $type ): array {
	static $defaults = null;

	if ( $defaults === null ) {
		$site = get_bloginfo( 'name' );

		$defaults = [
			'invite' => [
				'subject' => "[{site_name}] {owner_name} has shared a secure file vault with you",
				'body'    =>
					"Hello,\n\n"
					. "{owner_name} has shared a secure file vault with you on {site_name}.\n\n"
					. "Vault: {vault_name}\n\n"
					. "To access the files, click the link below. You will be asked to verify\n"
					. "your email address with a one-time code before downloading.\n\n"
					. "Access Link:\n{share_url}\n\n"
					. "{expires_note}\n\n"
					. "If you were not expecting this, you can safely ignore this email.\n\n"
					. "— {site_name}",
			],
			'otp' => [
				'subject' => "[{site_name}] Your secure file access code: {otp_code}",
				'body'    =>
					"Your one-time access code for {site_name} is:\n\n"
					. "    {otp_code}\n\n"
					. "This code is valid for {otp_ttl} minutes and can only be used once.\n\n"
					. "If you did not request this code, please ignore this email.\n\n"
					. "— {site_name}",
			],
			'download_notification' => [
				'subject' => "[{site_name}] A file was downloaded from your vault \"{vault_name}\"",
				'body'    =>
					"Hello {owner_name},\n\n"
					. "{recipient_email} downloaded a file from your vault \"{vault_name}\" on {site_name}.\n\n"
					. "File: {file_name}\n"
					. "Total downloads on this share: {download_count}\n"
					. "Recipient IP: {recipient_ip}\n\n"
					. "If this download was unexpected, you can revoke the share at any time.\n\n"
					. "— {site_name}",
			],
			'expiry_warning' => [
				'subject' => "[{site_name}] Share link for \"{vault_name}\" expires in {days_until_expiry} day(s)",
				'body'    =>
					"Hello {owner_name},\n\n"
					. "A share link for your vault \"{vault_name}\" on {site_name} will expire soon.\n\n"
					. "Recipient: {recipient_email}\n"
					. "Expiry: {expiry_date}\n"
					. "Days remaining: {days_until_expiry}\n\n"
					. "Log in to extend or revoke the share if needed.\n\n"
					. "— {site_name}",
			],
		];
	}

	$subject = (string) get_option( "sft_email_{$type}_subject", '' );
	$body    = (string) get_option( "sft_email_{$type}_body", '' );

	return [
		'subject' => $subject !== '' ? $subject : ( $defaults[ $type ]['subject'] ?? '' ),
		'body'    => $body    !== '' ? $body    : ( $defaults[ $type ]['body']    ?? '' ),
	];
}

/**
 * Replaces {placeholder} tokens in a string with values from $vars.
 *
 * @param string               $text  Raw subject or body string.
 * @param array<string,string> $vars  Map of placeholder name → replacement value.
 * @return string
 */
function sft_render_email_template( string $text, array $vars ): string {
	foreach ( $vars as $key => $value ) {
		$text = str_replace( '{' . $key . '}', (string) $value, $text );
	}
	return $text;
}

// ─── Download notification ────────────────────────────────────────────────────

/**
 * Emails the vault owner when a recipient downloads a file via a share link.
 *
 * No-ops silently when download notifications are disabled in Settings.
 *
 * @param int    $share_id      Share whose recipient triggered the download.
 * @param int    $file_id       File that was downloaded.
 * @param string $recipient_ip  IP of the downloader.
 */
function sft_send_download_notification( int $share_id, int $file_id, string $recipient_ip = '' ): void {
	if ( get_option( 'sft_notify_on_download', '0' ) !== '1' ) {
		return;
	}

	$share = sft_get_share( $share_id );
	if ( ! $share ) {
		return;
	}

	$vault = sft_get_vault( (int) $share->vault_id );
	$file  = sft_get_file( $file_id );

	if ( ! $vault || ! $file ) {
		return;
	}

	$owner = get_userdata( (int) $vault->owner_id );
	if ( ! $owner ) {
		return;
	}

	$share_url = add_query_arg( 'sft_share', $share->share_token, home_url( '/' ) );

	$expires_note = $share->expires_at
		? 'Expires on ' . sft_format_date( $share->expires_at, 'F j, Y' ) . '.'
		: 'No expiry date.';

	$vars = [
		'site_name'       => get_bloginfo( 'name' ),
		'vault_name'      => $vault->name,
		'recipient_email' => $share->recipient_email,
		'owner_name'      => $owner->display_name,
		'share_url'       => $share_url,
		'expires_note'    => $expires_note,
		'file_name'       => $file->original_name,
		'download_count'  => (string) $share->download_count,
		'recipient_ip'    => $recipient_ip ?: 'unknown',
	];

	$tmpl   = sft_get_email_template( 'download_notification' );
	$subject = sft_render_email_template( $tmpl['subject'], $vars );
	$body    = sft_render_email_template( $tmpl['body'], $vars );

	wp_mail( $owner->user_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );

	sft_log(
		SFT_EVT_DOWNLOAD_NOTIFIED,
		(int) $vault->id,
		$share_id,
		[ 'file_id' => $file_id, 'recipient' => $share->recipient_email ],
		(int) $vault->owner_id
	);
}

// ─── Expiry warning ───────────────────────────────────────────────────────────

/**
 * Emails the vault owner that a share link is about to expire.
 * Marks the share record so it is not warned again.
 *
 * @param object $share  Full share row from sft_shares.
 */
function sft_send_expiry_warning( object $share ): void {
	global $wpdb;

	$vault = sft_get_vault( (int) $share->vault_id );
	if ( ! $vault ) {
		return;
	}

	$owner = get_userdata( (int) $vault->owner_id );
	if ( ! $owner ) {
		return;
	}

	$expires_ts      = strtotime( $share->expires_at . ' UTC' );
	$days_remaining  = max( 0, (int) ceil( ( $expires_ts - time() ) / DAY_IN_SECONDS ) );
	$expiry_date     = (string) wp_date( 'F j, Y', $expires_ts );
	$share_url       = add_query_arg( 'sft_share', $share->share_token, home_url( '/' ) );

	$expires_note = 'Expires on ' . $expiry_date . '.';

	$vars = [
		'site_name'        => get_bloginfo( 'name' ),
		'vault_name'       => $vault->name,
		'recipient_email'  => $share->recipient_email,
		'owner_name'       => $owner->display_name,
		'share_url'        => $share_url,
		'expires_note'     => $expires_note,
		'expiry_date'      => $expiry_date,
		'days_until_expiry' => (string) $days_remaining,
	];

	$tmpl    = sft_get_email_template( 'expiry_warning' );
	$subject = sft_render_email_template( $tmpl['subject'], $vars );
	$body    = sft_render_email_template( $tmpl['body'], $vars );

	wp_mail( $owner->user_email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );

	// Mark as warned so we don't email again.
	$wpdb->update(
		"{$wpdb->prefix}sft_shares",
		[ 'expiry_warning_sent' => 1 ],
		[ 'id' => (int) $share->id ],
		[ '%d' ],
		[ '%d' ]
	);

	sft_log(
		SFT_EVT_EXPIRY_WARNING_SENT,
		(int) $share->vault_id,
		(int) $share->id,
		[
			'recipient'        => $share->recipient_email,
			'days_until_expiry' => $days_remaining,
		],
		(int) $vault->owner_id
	);
}
