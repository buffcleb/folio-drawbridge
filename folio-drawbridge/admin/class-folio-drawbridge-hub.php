<?php
/**
 * The shared "Folio" admin menu + suite dashboard.
 *
 * Every Folio plugin ships an identical copy of this helper. The first one to
 * run during admin_menu registers the top-level "Folio" menu (the rest detect it
 * and skip), and each plugin then hangs its own page beneath it — so the whole
 * Folio suite lives under one menu instead of a separate top-level item each.
 *
 * The dashboard auto-discovers installed plugins whose folder starts with
 * "folio-" and shows the suite at a glance.
 *
 * @package Folio_Drawbridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared Folio parent menu and renders the suite dashboard.
 */
class Folio_Drawbridge_Hub {

	/** Shared top-level menu slug, identical across every Folio plugin. */
	const SLUG = 'folio-hub';

	/**
	 * Ensure the shared "Folio" top-level menu exists. Idempotent across plugins:
	 * only the first caller in a request creates it.
	 *
	 * Pass the capability your own submenu requires. A plugin that delegates
	 * access to a non-administrator role must pass that role's capability, or
	 * WordPress hides the parent and its submenu becomes unreachable. render()
	 * enforces manage_options separately, so a lower parent capability never
	 * exposes the suite dashboard itself.
	 *
	 * @param string $capability Capability required to see the parent menu.
	 * @return void
	 */
	public static function ensure_parent( $capability = 'manage_options' ) {
		if ( self::parent_exists() ) {
			return;
		}
		add_menu_page(
			__( 'Folio', 'folio-drawbridge' ),
			__( 'Folio', 'folio-drawbridge' ),
			$capability,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			80
		);
		// Rename the auto-created first submenu (mirrors the parent slug) to
		// "Dashboard". This one keeps manage_options regardless of the parent's
		// capability, because that is what render() itself enforces — registering
		// it lower would show the entry to users who then get a blank screen.
		// WordPress drops submenu entries the user cannot access and points the
		// parent at the first remaining one, so a delegated admin lands on their
		// own plugin's page instead.
		add_submenu_page(
			self::SLUG,
			__( 'Folio', 'folio-drawbridge' ),
			__( 'Dashboard', 'folio-drawbridge' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Whether the shared Folio menu is already registered this request.
	 *
	 * @return bool
	 */
	private static function parent_exists() {
		global $menu;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The Folio suite and short descriptions, keyed by plugin folder slug.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function suite() {
		return array(
			'folio-gatehouse'  => array(
				'name' => __( 'Folio Gatehouse', 'folio-drawbridge' ),
				'desc' => __( 'Restrict files in your uploads directory to specific user roles.', 'folio-drawbridge' ),
				'page' => '',
			),
			'folio-drawbridge' => array(
				'name' => __( 'Folio Drawbridge', 'folio-drawbridge' ),
				'desc' => __( 'Encrypted file vaults with secure, two-factor external sharing.', 'folio-drawbridge' ),
				'page' => 'folio-drawbridge',
			),
			'folio-keep'       => array(
				'name' => __( 'Folio Keep', 'folio-drawbridge' ),
				'desc' => __( 'Turn WordPress into a SAML 2.0 Identity Provider.', 'folio-drawbridge' ),
				'page' => '',
			),
			'folio-portcullis' => array(
				'name' => __( 'Folio Portcullis', 'folio-drawbridge' ),
				'desc' => __( 'Sign in to WordPress via SAML 2.0 single sign-on.', 'folio-drawbridge' ),
				'page' => 'folio-portcullis',
			),
			'folio-barbican'   => array(
				'name' => __( 'Folio Barbican', 'folio-drawbridge' ),
				'desc' => __( 'Sign in to WordPress via OpenID Connect / OAuth.', 'folio-drawbridge' ),
				'page' => 'folio-barbican',
			),
		);
	}

	/**
	 * Render the suite dashboard.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = get_plugins();

		echo '<div class="wrap"><h1>' . esc_html__( 'Folio', 'folio-drawbridge' ) . '</h1>';
		echo '<p class="description" style="max-width:680px">'
			. esc_html__( 'Folio is a family of WordPress access and data-protection plugins. The ones installed on this site are shown below.', 'folio-drawbridge' )
			. '</p>';
		echo '<table class="widefat striped" style="max-width:860px;margin-top:12px"><thead><tr>'
			. '<th>' . esc_html__( 'Plugin', 'folio-drawbridge' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'folio-drawbridge' ) . '</th>'
			. '<th>' . esc_html__( 'Version', 'folio-drawbridge' ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( self::suite() as $slug => $info ) {
			$found  = self::find_installed( $installed, $slug );
			$active = $found && is_plugin_active( $found );
			if ( ! $found ) {
				$status  = '<span style="color:#646970">' . esc_html__( 'Not installed', 'folio-drawbridge' ) . '</span>';
				$version = '—';
				$action  = '';
			} else {
				$data    = $installed[ $found ];
				$version = esc_html( (string) $data['Version'] );
				$status  = $active
					? '<strong style="color:#00a32a">' . esc_html__( 'Active', 'folio-drawbridge' ) . '</strong>'
					: '<span style="color:#996800">' . esc_html__( 'Inactive', 'folio-drawbridge' ) . '</span>';
				$action  = ( $active && '' !== $info['page'] )
					? '<a href="' . esc_url( admin_url( 'admin.php?page=' . $info['page'] ) ) . '">' . esc_html__( 'Settings', 'folio-drawbridge' ) . '</a>'
					: '';
			}
			echo '<tr><td><strong>' . esc_html( $info['name'] ) . '</strong><br><span class="description">' . esc_html( $info['desc'] ) . '</span></td>'
				. '<td>' . wp_kses_post( $status ) . '</td>'
				. '<td>' . wp_kses_post( $version ) . '</td>'
				. '<td>' . wp_kses_post( $action ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The installed plugin file (dir/file.php) for a folder slug, or '' if absent.
	 *
	 * @param array<string,array<string,mixed>> $installed get_plugins() result.
	 * @param string                            $slug      Plugin folder slug.
	 * @return string
	 */
	private static function find_installed( array $installed, $slug ) {
		foreach ( array_keys( $installed ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) ) {
				return $file;
			}
		}
		return '';
	}
}
