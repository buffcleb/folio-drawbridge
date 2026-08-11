<?php
/**
 * WordPress dashboard widgets for Folio Drawbridge.
 *
 * Two widgets:
 *   - Admin overview  (requires folio_drawbridge_is_admin())
 *   - My Vaults       (requires folio_drawbridge_user_can_use())
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables, so $wpdb is the supported API and these dashboard figures are request-scoped. Every value goes through $wpdb->prepare(); only $wpdb->prefix appears in the SQL itself.

add_action( 'admin_enqueue_scripts', 'folio_drawbridge_enqueue_widget_assets' );

/**
 * Loads widget styles on the dashboard only.
 *
 * Both widgets scope their rules to their own widget id, so this sheet cannot
 * affect anything else on the screen.
 */
function folio_drawbridge_enqueue_widget_assets( string $hook ): void {
	if ( 'index.php' !== $hook ) {
		return;
	}

	if ( ! folio_drawbridge_is_admin() && ! folio_drawbridge_user_can_use() ) {
		return;
	}

	wp_enqueue_style(
		'folio-drawbridge-widgets',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'admin/css/widgets.css',
		[],
		FOLIO_DRAWBRIDGE_VERSION
	);
}

add_action( 'wp_dashboard_setup', 'folio_drawbridge_register_dashboard_widgets' );

function folio_drawbridge_register_dashboard_widgets(): void {
	if ( folio_drawbridge_is_admin() ) {
		wp_add_dashboard_widget(
			'folio_drawbridge_admin_overview',
			'Folio Drawbridge — Vault Overview',
			'folio_drawbridge_render_admin_overview_widget'
		);
	}

	if ( folio_drawbridge_user_can_use() ) {
		wp_add_dashboard_widget(
			'folio_drawbridge_my_vaults_summary',
			'Folio Drawbridge — My Vaults',
			'folio_drawbridge_render_user_vaults_widget'
		);
	}
}

// ─── Admin overview widget ─────────────────────────────────────────────────────

function folio_drawbridge_render_admin_overview_widget(): void {
	global $wpdb;


	// Vault counts.
	$total_vaults  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_vaults" );
	$active_vaults = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_vaults WHERE status = %s", 'active'
	) );

	// File count and total encrypted size.
	$file_row = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM {$wpdb->prefix}folio_drawbridge_files" );
	$file_count = (int) ( $file_row->cnt ?? 0 );
	$total_size = (int) ( $file_row->total_size ?? 0 );

	// Active/pending shares.
	$active_shares = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_shares WHERE status IN ('active','pending')"
	);

	// OTP failures last 30 days.
	$otp_failures = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_audit WHERE event_type = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
		FOLIO_DRAWBRIDGE_EVT_OTP_FAILED
	) );

	// Downloads last 7 days.
	$downloads_7d = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_audit WHERE event_type = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
		FOLIO_DRAWBRIDGE_EVT_FILE_DOWNLOADED
	) );

	$panel_url = admin_url( 'admin.php?page=folio-drawbridge' );

	?>
	<div class="folio-drawbridge-dw-stats">
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $total_vaults ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Total Vaults</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $active_vaults ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Active</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $file_count ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Files</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( size_format( $total_size ) ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Encrypted Size</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $active_shares ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Active Shares</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num <?php echo $downloads_7d > 0 ? 'folio-drawbridge-dw-num' : ''; ?>"><?php echo esc_html( $downloads_7d ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Downloads (7d)</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num <?php echo $otp_failures > 0 ? 'folio-drawbridge-dw-alert' : ''; ?>"><?php echo esc_html( $otp_failures ); ?></div>
			<div class="folio-drawbridge-dw-lbl">OTP Failures (30d)</div>
		</div>
	</div>
	<div class="folio-drawbridge-dw-footer">
		<a href="<?php echo esc_url( $panel_url ); ?>">Open Folio Drawbridge panel →</a>
	</div>
	<?php
}

// ─── User vaults widget ────────────────────────────────────────────────────────

function folio_drawbridge_render_user_vaults_widget(): void {
	global $wpdb;

	$user_id = get_current_user_id();

	// Personal vault counts.
	$total_vaults  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_vaults WHERE owner_id = %d", $user_id
	) );
	$active_vaults = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_vaults WHERE owner_id = %d AND status = %s", $user_id, 'active'
	) );

	// File count across all owned vaults.
	$file_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_files f
		 INNER JOIN {$wpdb->prefix}folio_drawbridge_vaults v ON v.id = f.vault_id
		 WHERE v.owner_id = %d", $user_id
	) );

	// Active/pending shares on owned vaults.
	$active_shares = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}folio_drawbridge_shares s
		 INNER JOIN {$wpdb->prefix}folio_drawbridge_vaults v ON v.id = s.vault_id
		 WHERE v.owner_id = %d AND s.status IN ('active','pending')", $user_id
	) );

	// Last 5 audit events for this user's vaults.
	$recent = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.event_type, a.created_at, v.name AS vault_name
		 FROM {$wpdb->prefix}folio_drawbridge_audit a
		 INNER JOIN {$wpdb->prefix}folio_drawbridge_vaults v ON v.id = a.vault_id
		 WHERE v.owner_id = %d
		 ORDER BY a.created_at DESC
		 LIMIT 5",
		$user_id
	) );

	$dashboard_url = admin_url( 'admin.php?page=folio-drawbridge-vaults' );

	$label_map = [
		FOLIO_DRAWBRIDGE_EVT_VAULT_CREATED   => 'Vault created',
		FOLIO_DRAWBRIDGE_EVT_VAULT_DELETED   => 'Vault deleted',
		FOLIO_DRAWBRIDGE_EVT_VAULT_EXPIRED   => 'Vault expired',
		FOLIO_DRAWBRIDGE_EVT_VAULT_STATUS    => 'Status changed',
		FOLIO_DRAWBRIDGE_EVT_FILE_UPLOADED   => 'File uploaded',
		FOLIO_DRAWBRIDGE_EVT_FILE_DELETED    => 'File deleted',
		FOLIO_DRAWBRIDGE_EVT_FILE_DOWNLOADED => 'File downloaded',
		FOLIO_DRAWBRIDGE_EVT_SHARE_CREATED   => 'Share created',
		FOLIO_DRAWBRIDGE_EVT_SHARE_RESENT    => 'Invite resent',
		FOLIO_DRAWBRIDGE_EVT_SHARE_REVOKED   => 'Share revoked',
		FOLIO_DRAWBRIDGE_EVT_SHARE_EXPIRED   => 'Share expired',
		FOLIO_DRAWBRIDGE_EVT_OTP_REQUESTED   => 'OTP sent',
		FOLIO_DRAWBRIDGE_EVT_OTP_FAILED      => 'OTP failed',
		FOLIO_DRAWBRIDGE_EVT_OTP_SUCCESS     => 'OTP verified',
	];

	?>
	<div class="folio-drawbridge-dw-stats">
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $total_vaults ); ?></div>
			<div class="folio-drawbridge-dw-lbl">My Vaults</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $active_vaults ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Active</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $file_count ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Files</div>
		</div>
		<div class="folio-drawbridge-dw-stat">
			<div class="folio-drawbridge-dw-num"><?php echo esc_html( $active_shares ); ?></div>
			<div class="folio-drawbridge-dw-lbl">Active Shares</div>
		</div>
	</div>
	<?php if ( $recent ) : ?>
	<div class="folio-drawbridge-dw-recent">
		<strong style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.05em;">Recent Activity</strong>
		<table>
		<?php foreach ( $recent as $row ) :
			$label = $label_map[ $row->event_type ] ?? ucwords( str_replace( '_', ' ', $row->event_type ) );
			$dt    = folio_drawbridge_format_date( $row->created_at );
		?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo esc_html( $row->vault_name ); ?></td>
				<td><?php echo esc_html( $dt ); ?></td>
			</tr>
		<?php endforeach; ?>
		</table>
	</div>
	<?php else : ?>
	<p style="color:#888;font-size:12px;margin:0 0 10px;">No vault activity yet.</p>
	<?php endif; ?>
	<div class="folio-drawbridge-dw-footer">
		<a href="<?php echo esc_url( $dashboard_url ); ?>">Open My Vaults →</a>
	</div>
	<?php
}
