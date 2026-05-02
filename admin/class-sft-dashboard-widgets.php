<?php
/**
 * WordPress dashboard widgets for WP Secure File Transfer Pro.
 *
 * Two widgets:
 *   - Admin overview  (requires sft_is_admin())
 *   - My Vaults       (requires sft_user_can_use())
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'sft_register_dashboard_widgets' );

function sft_register_dashboard_widgets(): void {
	if ( sft_is_admin() ) {
		wp_add_dashboard_widget(
			'sft_admin_overview',
			'Secure File Transfer — Vault Overview',
			'sft_render_admin_overview_widget'
		);
	}

	if ( sft_user_can_use() ) {
		wp_add_dashboard_widget(
			'sft_my_vaults_summary',
			'Secure File Transfer — My Vaults',
			'sft_render_user_vaults_widget'
		);
	}
}

// ─── Admin overview widget ─────────────────────────────────────────────────────

function sft_render_admin_overview_widget(): void {
	global $wpdb;

	$prefix = $wpdb->prefix;

	// Vault counts.
	$total_vaults  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}sft_vaults" );
	$active_vaults = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_vaults WHERE status = %s", 'active'
	) );

	// File count and total encrypted size.
	$file_row = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM {$prefix}sft_files" );
	$file_count = (int) ( $file_row->cnt ?? 0 );
	$total_size = (int) ( $file_row->total_size ?? 0 );

	// Active/pending shares.
	$active_shares = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$prefix}sft_shares WHERE status IN ('active','pending')"
	);

	// OTP failures last 30 days.
	$otp_failures = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_audit WHERE event = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
		SFT_EVT_OTP_FAILED
	) );

	// Downloads last 7 days.
	$downloads_7d = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_audit WHERE event = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
		SFT_EVT_FILE_DOWNLOADED
	) );

	$panel_url = admin_url( 'admin.php?page=sft-pro' );

	?>
	<style>
		#sft_admin_overview .sft-dw-stats { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px; }
		#sft_admin_overview .sft-dw-stat { flex:1; min-width:90px; background:#f9fafc; border:1px solid #e2e4e7;
		                                   border-radius:4px; padding:10px 12px; text-align:center; }
		#sft_admin_overview .sft-dw-num  { font-size:22px; font-weight:700; color:#2271b1; line-height:1.2; }
		#sft_admin_overview .sft-dw-lbl  { font-size:11px; color:#666; margin-top:2px; }
		#sft_admin_overview .sft-dw-alert { color:#d63638; font-weight:600; }
		#sft_admin_overview .sft-dw-footer { border-top:1px solid #f0f2f5; padding-top:10px; font-size:12px; color:#888; }
	</style>
	<div class="sft-dw-stats">
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $total_vaults ); ?></div>
			<div class="sft-dw-lbl">Total Vaults</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $active_vaults ); ?></div>
			<div class="sft-dw-lbl">Active</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $file_count ); ?></div>
			<div class="sft-dw-lbl">Files</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( size_format( $total_size ) ); ?></div>
			<div class="sft-dw-lbl">Encrypted Size</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $active_shares ); ?></div>
			<div class="sft-dw-lbl">Active Shares</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num <?php echo $downloads_7d > 0 ? 'sft-dw-num' : ''; ?>"><?php echo esc_html( $downloads_7d ); ?></div>
			<div class="sft-dw-lbl">Downloads (7d)</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num <?php echo $otp_failures > 0 ? 'sft-dw-alert' : ''; ?>"><?php echo esc_html( $otp_failures ); ?></div>
			<div class="sft-dw-lbl">OTP Failures (30d)</div>
		</div>
	</div>
	<div class="sft-dw-footer">
		<a href="<?php echo esc_url( $panel_url ); ?>">Open Secure Transfer panel →</a>
	</div>
	<?php
}

// ─── User vaults widget ────────────────────────────────────────────────────────

function sft_render_user_vaults_widget(): void {
	global $wpdb;

	$user_id = get_current_user_id();
	$prefix  = $wpdb->prefix;

	// Personal vault counts.
	$total_vaults  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_vaults WHERE owner_id = %d", $user_id
	) );
	$active_vaults = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_vaults WHERE owner_id = %d AND status = %s", $user_id, 'active'
	) );

	// File count across all owned vaults.
	$file_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_files f
		 INNER JOIN {$prefix}sft_vaults v ON v.id = f.vault_id
		 WHERE v.owner_id = %d", $user_id
	) );

	// Active/pending shares on owned vaults.
	$active_shares = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$prefix}sft_shares s
		 INNER JOIN {$prefix}sft_vaults v ON v.id = s.vault_id
		 WHERE v.owner_id = %d AND s.status IN ('active','pending')", $user_id
	) );

	// Last 5 audit events for this user's vaults.
	$recent = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.event, a.created_at, v.name AS vault_name
		 FROM {$prefix}sft_audit a
		 INNER JOIN {$prefix}sft_vaults v ON v.id = a.vault_id
		 WHERE v.owner_id = %d
		 ORDER BY a.created_at DESC
		 LIMIT 5",
		$user_id
	) );

	$dashboard_url = admin_url( 'admin.php?page=sft-my-vaults' );

	$label_map = [
		SFT_EVT_VAULT_CREATED   => 'Vault created',
		SFT_EVT_VAULT_DELETED   => 'Vault deleted',
		SFT_EVT_VAULT_EXPIRED   => 'Vault expired',
		SFT_EVT_VAULT_STATUS    => 'Status changed',
		SFT_EVT_FILE_UPLOADED   => 'File uploaded',
		SFT_EVT_FILE_DELETED    => 'File deleted',
		SFT_EVT_FILE_DOWNLOADED => 'File downloaded',
		SFT_EVT_SHARE_CREATED   => 'Share created',
		SFT_EVT_SHARE_REVOKED   => 'Share revoked',
		SFT_EVT_SHARE_EXPIRED   => 'Share expired',
		SFT_EVT_OTP_REQUESTED   => 'OTP sent',
		SFT_EVT_OTP_FAILED      => 'OTP failed',
		SFT_EVT_OTP_SUCCESS     => 'OTP verified',
	];

	?>
	<style>
		#sft_my_vaults_summary .sft-dw-stats { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px; }
		#sft_my_vaults_summary .sft-dw-stat  { flex:1; min-width:90px; background:#f9fafc; border:1px solid #e2e4e7;
		                                        border-radius:4px; padding:10px 12px; text-align:center; }
		#sft_my_vaults_summary .sft-dw-num   { font-size:22px; font-weight:700; color:#2271b1; line-height:1.2; }
		#sft_my_vaults_summary .sft-dw-lbl   { font-size:11px; color:#666; margin-top:2px; }
		#sft_my_vaults_summary .sft-dw-recent { font-size:12px; }
		#sft_my_vaults_summary .sft-dw-recent table { width:100%; border-collapse:collapse; }
		#sft_my_vaults_summary .sft-dw-recent td { padding:4px 6px; border-bottom:1px solid #f0f2f5; }
		#sft_my_vaults_summary .sft-dw-recent td:last-child { color:#888; text-align:right; white-space:nowrap; }
		#sft_my_vaults_summary .sft-dw-footer { border-top:1px solid #f0f2f5; padding-top:10px; font-size:12px; color:#888; margin-top:10px; }
	</style>
	<div class="sft-dw-stats">
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $total_vaults ); ?></div>
			<div class="sft-dw-lbl">My Vaults</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $active_vaults ); ?></div>
			<div class="sft-dw-lbl">Active</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $file_count ); ?></div>
			<div class="sft-dw-lbl">Files</div>
		</div>
		<div class="sft-dw-stat">
			<div class="sft-dw-num"><?php echo esc_html( $active_shares ); ?></div>
			<div class="sft-dw-lbl">Active Shares</div>
		</div>
	</div>
	<?php if ( $recent ) : ?>
	<div class="sft-dw-recent">
		<strong style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.05em;">Recent Activity</strong>
		<table>
		<?php foreach ( $recent as $row ) :
			$label = $label_map[ $row->event ] ?? ucwords( str_replace( '_', ' ', $row->event ) );
			$dt    = sft_format_date( $row->created_at );
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
	<div class="sft-dw-footer">
		<a href="<?php echo esc_url( $dashboard_url ); ?>">Open My Vaults →</a>
	</div>
	<?php
}
