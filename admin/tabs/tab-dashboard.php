<?php
/**
 * Dashboard tab — at-a-glance stats and recent activity.
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters here are read-only display filters and sort state; no state changes occur on GET.

function sft_render_tab_dashboard(): void {
	global $wpdb;

	// ── Counts ───────────────────────────────────────────────────────────────
	// Cached as one block: on a 250k-row audit table the aggregates below cost
	// ~45 ms per render, most of it the unfiltered COUNT(*). Dashboard figures
	// do not need to be second-accurate, so a short transient removes that cost
	// from every page load while staying fresh enough to be useful.
	$stats = get_transient( 'sft_dashboard_stats' );

	if ( false === $stats ) {
		$p = $wpdb->prefix;

		$file_totals  = $wpdb->get_row( "SELECT COUNT(*) AS c, COALESCE(SUM(file_size),0) AS b FROM {$p}sft_files" );
		$share_totals = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
			        SUM(status IN('pending','active')) AS active,
			        COALESCE(SUM(download_count),0) AS downloads
			   FROM {$p}sft_shares"
		);
		$vault_totals = $wpdb->get_row(
			"SELECT COUNT(*) AS total, SUM(status='active') AS active FROM {$p}sft_vaults"
		);

		$stats = [
			'total_vaults'  => (int) $vault_totals->total,
			'active_vaults' => (int) $vault_totals->active,
			'total_files'   => (int) $file_totals->c,
			'total_storage' => (int) $file_totals->b,
			'total_shares'  => (int) $share_totals->total,
			'active_shares' => (int) $share_totals->active,
			'total_dl'      => (int) $share_totals->downloads,
			'total_audit'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sft_audit" ),
			// Bounded to 30 days to match what the Dashboard help tab describes,
			// and so the event_created index can serve it instead of counting
			// every otp_failed row ever recorded.
			'otp_failures'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$p}sft_audit
				  WHERE event_type='otp_failed'
				    AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
			),
		];

		set_transient( 'sft_dashboard_stats', $stats, 5 * MINUTE_IN_SECONDS );
	}

	$total_vaults  = $stats['total_vaults'];
	$active_vaults = $stats['active_vaults'];
	$total_files   = $stats['total_files'];
	$total_storage = $stats['total_storage'];
	$total_shares  = $stats['total_shares'];
	$active_shares = $stats['active_shares'];
	$total_dl      = $stats['total_dl'];
	$total_audit   = $stats['total_audit'];
	$otp_failures  = $stats['otp_failures'];

	// ── Recent audit events (last 10) ─────────────────────────────────────────
	// Ordered by id: append-only table, so identical to created_at order but
	// served straight from the clustered index.
	$recent = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}sft_audit ORDER BY id DESC LIMIT 10"
	) ?: [];

	// ── 7-day download activity ───────────────────────────────────────────────
	$dl_rows = $wpdb->get_results( "
		SELECT DATE(created_at) as day, COUNT(*) as cnt
		FROM {$wpdb->prefix}sft_audit
		WHERE event_type IN ('file_downloaded','file_served_admin')
		  AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
		GROUP BY DATE(created_at)
		ORDER BY day ASC
	", ARRAY_A ) ?: [];

	$spark = [];
	for ( $d = 6; $d >= 0; $d-- ) {
		$spark[ gmdate( 'Y-m-d', strtotime( "-{$d} days" ) ) ] = 0;
	}
	foreach ( $dl_rows as $r ) {
		if ( isset( $spark[ $r['day'] ] ) ) {
			$spark[ $r['day'] ] = (int) $r['cnt'];
		}
	}

	?>

	<!-- ── Stat cards ──────────────────────────────────────────────────────── -->
	<div class="sft-stats">
		<div class="sft-stat" style="border-top:3px solid #2271b1;">
			<div class="sft-stat-num"><?php echo number_format( $active_vaults ); ?></div>
			<div class="sft-stat-label">Active Vaults <span style="color:#aaa;">(<?php echo number_format( $total_vaults ); ?> total)</span></div>
		</div>
		<div class="sft-stat" style="border-top:3px solid #0a3622;">
			<div class="sft-stat-num"><?php echo number_format( $total_files ); ?></div>
			<div class="sft-stat-label">Encrypted Files <span style="color:#aaa;"><?php echo esc_html( size_format( $total_storage ) ); ?></span></div>
		</div>
		<div class="sft-stat" style="border-top:3px solid #6f42c1;">
			<div class="sft-stat-num" style="color:#6f42c1;"><?php echo number_format( $active_shares ); ?></div>
			<div class="sft-stat-label">Active Shares <span style="color:#aaa;">(<?php echo number_format( $total_shares ); ?> total)</span></div>
		</div>
		<div class="sft-stat" style="border-top:3px solid #fd7e14;">
			<div class="sft-stat-num" style="color:#fd7e14;"><?php echo number_format( $total_dl ); ?></div>
			<div class="sft-stat-label">Total Downloads</div>
		</div>
		<div class="sft-stat" style="border-top:3px solid <?php echo $otp_failures > 0 ? '#d63638' : '#ccd0d4'; ?>;">
			<div class="sft-stat-num" style="color:<?php echo $otp_failures > 0 ? '#d63638' : '#444'; ?>;"><?php echo number_format( $otp_failures ); ?></div>
			<div class="sft-stat-label">OTP Failures <span style="color:#aaa;">(30 days)</span></div>
		</div>
		<div class="sft-stat" style="border-top:3px solid #6c757d;">
			<div class="sft-stat-num" style="color:#444;"><?php echo number_format( $total_audit ); ?></div>
			<div class="sft-stat-label">Audit Events</div>
		</div>
	</div>

	<div style="display:flex; gap:20px; margin-top:20px; align-items:flex-start; flex-wrap:wrap;">

		<!-- ── Download sparkline ─────────────────────────────────────────── -->
		<div class="sft-card" style="flex:1; min-width:260px; margin-top:0;">
			<h3 style="margin:0 0 12px;">Downloads — Last 7 Days</h3>
			<?php
			$vals    = array_values( $spark );
			$max_val = max( 1, max( $vals ) );
			$sw = 280; $sh = 60; $n = count( $vals );
			$step = $sw / max( 1, $n - 1 );
			$days = array_keys( $spark );
			$pts  = [];
			foreach ( $vals as $i => $v ) {
				$pts[] = round( $i * $step ) . ',' . round( $sh - ( $v / $max_val ) * $sh );
			}
			?>
			<svg viewBox="0 0 <?php echo (int) $sw; ?> <?php echo (int) $sh + 20; ?>" style="width:100%;overflow:visible;">
				<?php foreach ( $days as $i => $day ) : ?>
					<text x="<?php echo (int) round( $i * $step ); ?>" y="<?php echo (int) $sh + 14; ?>"
					      text-anchor="middle" font-size="8" fill="#aaa"><?php echo esc_html( gmdate( 'M j', strtotime( $day ) ) ); ?></text>
				<?php endforeach; ?>
				<polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>"
				          fill="none" stroke="#2271b1" stroke-width="2.5" stroke-linejoin="round"/>
				<?php foreach ( $vals as $i => $v ) : ?>
					<circle cx="<?php echo (int) round( $i * $step ); ?>" cy="<?php echo (int) round( $sh - ( $v / $max_val ) * $sh ); ?>"
					        r="3" fill="#2271b1"/>
				<?php endforeach; ?>
			</svg>
		</div>

		<!-- ── Recent activity ──────────────────────────────────────────────── -->
		<div class="sft-card" style="flex:2; min-width:320px; margin-top:0;">
			<h3 style="margin:0 0 12px;">Recent Activity</h3>
			<?php if ( ! $recent ) : ?>
				<p style="color:#888;font-size:13px;">No activity recorded yet.</p>
			<?php else : ?>
				<table class="sft-table">
					<thead><tr>
						<th>Event</th><th>Vault</th><th>Actor</th><th>Time</th>
					</tr></thead>
					<tbody>
						<?php
					// Prime the user cache in one query so the per-row get_userdata()
					// calls below are served from memory.
					cache_users( array_filter( wp_list_pluck( $recent, 'actor_id' ) ) );
					foreach ( $recent as $row ) :
						$actor = $row->actor_id ? get_userdata( (int) $row->actor_id ) : null;
						$vault_label = $row->vault_id ? '#' . (int) $row->vault_id : '—';
					?>
						<tr>
							<td><strong><?php echo esc_html( sft_audit_event_label( $row->event_type ) ); ?></strong></td>
							<td><?php echo esc_html( $vault_label ); ?></td>
							<td><?php echo $actor ? esc_html( $actor->user_login ) : '<em>system</em>'; ?></td>
							<td style="color:#888;white-space:nowrap;"><?php echo esc_html( gmdate( 'M j, H:i', strtotime( $row->created_at ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="text-align:right;margin:8px 0 0;">
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) ); ?>">
						View full audit log →
					</a>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- ── Security notes ── -->
	<?php
	$master_key_source = defined( 'SFT_MASTER_KEY' ) ? 'wp-config.php constant (recommended)' : 'database (consider moving to SFT_MASTER_KEY in wp-config.php)';
	?>
	<div class="sft-card" style="margin-top:20px; border-left:4px solid #2271b1;">
		<h3 style="margin:0 0 8px;">Security Status</h3>
		<ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.8;">
			<li>Master encryption key source: <strong><?php echo esc_html( $master_key_source ); ?></strong></li>
			<li>Encryption algorithm: <strong>AES-256-CBC</strong> with per-file random IV and per-vault derived key</li>
			<li>OTP validity: <strong><?php echo (int) get_option( 'sft_otp_ttl_minutes', 15 ); ?> minutes</strong>, max 5 attempts</li>
			<li>File storage: <strong><?php echo esc_html( SFT_VAULT_DIR ); ?></strong> (HTTP-blocked)</li>
			<li>WP-Cron lifecycle: <strong><?php echo wp_next_scheduled( 'sft_lifecycle_cron' ) ? 'scheduled' : '⚠ not scheduled — reactivate plugin'; ?></strong></li>
		</ul>
	</div>
	<?php
}
