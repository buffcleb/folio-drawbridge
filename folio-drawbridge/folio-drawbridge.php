<?php
/**
 * Plugin Name:       Folio Drawbridge
 * Plugin URI:        https://github.com/buffcleb/folio-drawbridge
 * Description:       Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and role-based vault oversight.
 * Version:           1.2.0
 * Author:            buffcleb
 * Author URI:        https://github.com/buffcleb
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       folio-drawbridge
 * Requires at least: 6.2
 * Tested up to:      7.0
 * Requires PHP:      7.4
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Plugin constants ─────────────────────────────────────────────────────────
define( 'FOLIO_DRAWBRIDGE_VERSION',          '1.2.0' );
define( 'FOLIO_DRAWBRIDGE_DB_VERSION',       '1.2.1' ); // bump to apply the folio_drawbridge_audit composite index
define( 'FOLIO_DRAWBRIDGE_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'FOLIO_DRAWBRIDGE_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'FOLIO_DRAWBRIDGE_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );

// Administrators can define FOLIO_DRAWBRIDGE_MASTER_KEY as 64 hex chars in wp-config.php
// to keep the encryption master key out of the database entirely.

// ─── Drawbridge admin capability ─────────────────────────────────────────────────────

/**
 * Returns true when the user is an Drawbridge administrator.
 *
 * WordPress administrators (manage_options) always qualify. Non-admin users
 * explicitly granted the folio_drawbridge_admin capability also qualify, giving them full
 * access to the Drawbridge admin panel without WordPress administrator privileges.
 *
 * @param int|null $user_id Defaults to the current user.
 */
function folio_drawbridge_is_admin( ?int $user_id = null ): bool {
	if ( $user_id !== null ) {
		$user = get_userdata( $user_id );
		return $user && ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'folio_drawbridge_manage_vaults' ) );
	}
	return current_user_can( 'manage_options' ) || current_user_can( 'folio_drawbridge_manage_vaults' );
}

// WordPress administrators implicitly receive the folio_drawbridge_admin capability.
add_filter( 'user_has_cap', static function ( array $allcaps ): array {
	if ( ! empty( $allcaps['manage_options'] ) ) {
		$allcaps['folio_drawbridge_manage_vaults'] = true;
	}
	return $allcaps;
} );

// ─── Asset versioning ─────────────────────────────────────────────────────────

/**
 * Returns the cache-busting version string for one of this plugin's assets.
 *
 * The plugin version alone is not enough: an asset edited between releases
 * keeps the same URL, so browsers keep serving the copy they already have and
 * the change appears not to have taken effect. Appending the file's
 * modification time gives every edit a distinct URL while leaving the plugin
 * version visible at the front, which is useful when reading a bug report.
 *
 * @param string $relative_path Asset path relative to the plugin directory.
 */
function folio_drawbridge_asset_version( string $relative_path ): string {
	$file  = FOLIO_DRAWBRIDGE_PLUGIN_DIR . ltrim( $relative_path, '/' );
	$mtime = file_exists( $file ) ? filemtime( $file ) : false;

	return $mtime ? FOLIO_DRAWBRIDGE_VERSION . '.' . $mtime : FOLIO_DRAWBRIDGE_VERSION;
}

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
function folio_drawbridge_format_date( string $utc_mysql, string $format = 'M j, Y g:i A' ): string {
	$ts = strtotime( $utc_mysql . ' UTC' );
	return $ts ? (string) wp_date( $format, $ts ) : '';
}

// ─── Load core modules ────────────────────────────────────────────────────────
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-db.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-crypto.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-audit.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-vault.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-share.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-notifications.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-lifecycle.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'includes/class-folio-drawbridge-frontend.php';

if ( is_admin() ) {
	require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/class-folio-drawbridge-hub.php';
	require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/class-folio-drawbridge-admin.php';
	require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/class-folio-drawbridge-user-dashboard.php';
	require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/class-folio-drawbridge-dashboard-widgets.php';
}
