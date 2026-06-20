<?php
/**
 * Plugin Name:       Folio Drawbridge
 * Description:       Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and role-based vault oversight.
 * Version:           1.2.0
 * Requires at least: 5.3
 * Requires PHP:      7.4
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       folio-drawbridge
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Plugin constants ─────────────────────────────────────────────────────────
define( 'SFT_VERSION',    '1.2.0' );
define( 'SFT_DB_VERSION', '1.2.0' );
define( 'SFT_DIR',        plugin_dir_path( __FILE__ ) );
define( 'SFT_BASENAME',   plugin_basename( __FILE__ ) );
define( 'SFT_VAULT_DIR',  WP_CONTENT_DIR . '/uploads/sft-vaults/' );

// Administrators can define SFT_MASTER_KEY as 64 hex chars in wp-config.php
// to keep the encryption master key out of the database entirely.

// ─── SFT admin capability ─────────────────────────────────────────────────────

/**
 * Returns true when the user is an SFT administrator.
 *
 * WordPress administrators (manage_options) always qualify. Non-admin users
 * explicitly granted the sft_admin capability also qualify, giving them full
 * access to the SFT admin panel without WordPress administrator privileges.
 *
 * @param int|null $user_id Defaults to the current user.
 */
function sft_is_admin( ?int $user_id = null ): bool {
	if ( $user_id !== null ) {
		$user = get_userdata( $user_id );
		return $user && ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'sft_admin' ) );
	}
	return current_user_can( 'manage_options' ) || current_user_can( 'sft_admin' );
}

// WordPress administrators implicitly receive the sft_admin capability.
add_filter( 'user_has_cap', static function ( array $allcaps ): array {
	if ( ! empty( $allcaps['manage_options'] ) ) {
		$allcaps['sft_admin'] = true;
	}
	return $allcaps;
} );

// ─── Date formatting helper ───────────────────────────────────────────────────

/**
 * Formats a UTC MySQL datetime string using the site's configured timezone.
 *
 * All timestamps stored by this plugin are UTC (current_time('mysql', true)).
 * This function appends ' UTC' before passing to strtotime so PHP does not
 * misinterpret them as local time.
 *
 * @param string $utc_mysql  MySQL datetime string in UTC.
 * @param string $format     Date format accepted by wp_date().
 */
function sft_format_date( string $utc_mysql, string $format = 'M j, Y g:i A' ): string {
	$ts = strtotime( $utc_mysql . ' UTC' );
	return $ts ? (string) wp_date( $format, $ts ) : '';
}

// ─── Load core modules ────────────────────────────────────────────────────────
require_once SFT_DIR . 'includes/class-sft-db.php';
require_once SFT_DIR . 'includes/class-sft-crypto.php';
require_once SFT_DIR . 'includes/class-sft-audit.php';
require_once SFT_DIR . 'includes/class-sft-vault.php';
require_once SFT_DIR . 'includes/class-sft-share.php';
require_once SFT_DIR . 'includes/class-sft-notifications.php';
require_once SFT_DIR . 'includes/class-sft-lifecycle.php';
require_once SFT_DIR . 'includes/class-sft-frontend.php';

if ( is_admin() ) {
	require_once SFT_DIR . 'admin/class-sft-admin.php';
	require_once SFT_DIR . 'admin/class-sft-user-dashboard.php';
	require_once SFT_DIR . 'admin/class-sft-dashboard-widgets.php';
}
