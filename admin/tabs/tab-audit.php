<?php
/**
 * Audit Log tab — filterable, sortable, paginated view of all plugin events.
 *
 * Filters: event type, vault ID, date range.
 * Export: CSV of the filtered result set.
 *
 * @package FolioDrawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── CSV export (must run before any output — hooked via admin_init in admin class) ──
add_action( 'admin_init', 'sft_maybe_export_audit_csv' );

function sft_maybe_export_audit_csv(): void {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sft-pro' ) {
		return;
	}
	if ( ( $_GET['tab'] ?? '' ) !== 'audit' || ! isset( $_GET['sft_export_audit'] ) ) {
		return;
	}
	if ( ! sft_is_admin() ) {
		return;
	}
	check_admin_referer( 'sft_export_audit' );

	$args = sft_audit_filter_args_from_get();
	$args['per_page'] = 9999;
	$rows = sft_get_audit_logs( $args );

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="sft-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$fh = fopen( 'php://output', 'w' );
	fputcsv( $fh, [ 'ID', 'Event', 'Vault ID', 'Share ID', 'Actor', 'IP', 'Details', 'Date/Time (Site Timezone)' ] );

	foreach ( $rows as $row ) {
		$actor  = $row->actor_id ? ( get_userdata( (int) $row->actor_id )->user_login ?? $row->actor_id ) : 'system';
		$detail = $row->details ? str_replace( [ "\r", "\n" ], ' ', $row->details ) : '';
		fputcsv( $fh, [
			$row->id,
			sft_audit_event_label( $row->event_type ),
			$row->vault_id ?? '',
			$row->share_id ?? '',
			$actor,
			$row->ip_address,
			$detail,
			sft_format_date( $row->created_at, 'Y-m-d H:i:s' ),
		] );
	}

	fclose( $fh );
	exit;
}

// ─── Tab renderer ─────────────────────────────────────────────────────────────

function sft_render_tab_audit(): void {
	$args        = sft_audit_filter_args_from_get();
	$per_page    = 25;
	$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$a_orderby   = sanitize_key( $_GET['orderby'] ?? 'created_at' );
	$a_order     = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
	$args['per_page'] = $per_page;
	$args['paged']    = $paged;
	$args['orderby']  = $a_orderby;
	$args['order']    = $a_order;

	$rows        = sft_get_audit_logs( $args );
	$total       = sft_count_audit_logs( $args );
	$total_pages = (int) ceil( $total / $per_page );

	$f_event    = sanitize_key( $_GET['f_event'] ?? '' );
	$f_vault_id = (int) ( $_GET['f_vault_id'] ?? 0 );
	$f_from     = sanitize_text_field( $_GET['f_from'] ?? '' );
	$f_to       = sanitize_text_field( $_GET['f_to'] ?? '' );
	$f_details  = sanitize_text_field( $_GET['f_details'] ?? '' );

	$filter_args = array_filter( [
		'f_event'    => $f_event,
		'f_vault_id' => $f_vault_id ?: '',
		'f_from'     => $f_from,
		'f_to'       => $f_to,
		'f_details'  => $f_details,
	] );

	// All distinct event types for the filter dropdown.
	global $wpdb;
	$event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$wpdb->prefix}sft_audit ORDER BY event_type ASC" ) ?: [];

	$export_url = add_query_arg(
		array_merge(
			[ 'page' => 'sft-pro', 'tab' => 'audit', 'sft_export_audit' => '1' ],
			$filter_args,
			[ '_wpnonce' => wp_create_nonce( 'sft_export_audit' ) ]
		),
		admin_url( 'admin.php' )
	);
	?>

	<div style="display:flex; gap:20px; align-items:flex-start; margin-top:20px;">

		<!-- Filter panel -->
		<div class="sft-card sft-filter-panel" style="margin-top:0;">
			<h3 style="margin-top:0;">Filter Events</h3>
			<form method="get">
				<input type="hidden" name="page" value="sft-pro">
				<input type="hidden" name="tab"  value="audit">

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Event Type</label>
					<select name="f_event" style="width:100%;">
						<option value="">All</option>
						<?php foreach ( $event_types as $et ) : ?>
							<option value="<?php echo esc_attr( $et ); ?>" <?php selected( $f_event, $et ); ?>>
								<?php echo esc_html( sft_audit_event_label( $et ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Vault ID</label>
					<input type="number" name="f_vault_id" value="<?php echo $f_vault_id ?: ''; ?>" style="width:100%;" placeholder="e.g. 42" min="1">
				</p>

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">From</label>
					<input type="date" name="f_from" value="<?php echo esc_attr( $f_from ); ?>" style="width:100%;">
				</p>

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">To</label>
					<input type="date" name="f_to" value="<?php echo esc_attr( $f_to ); ?>" style="width:100%;">
				</p>

				<p style="margin:0 0 12px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Search Details</label>
					<input type="text" name="f_details" value="<?php echo esc_attr( $f_details ); ?>" style="width:100%;" placeholder="keyword…">
				</p>

				<input type="submit" value="Apply" class="button button-primary" style="width:100%;">
				<?php if ( $filter_args ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) ); ?>"
					   class="button" style="width:100%;margin-top:6px;text-align:center;box-sizing:border-box;">Clear</a>
				<?php endif; ?>
			</form>

			<!-- Manual prune -->
			<hr style="margin:16px 0;">
			<h4 style="margin:0 0 8px;font-size:13px;">Manual Prune</h4>
			<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) ); ?>"
			      onsubmit="return confirm('Delete all audit entries older than the specified number of days?');">
				<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
				<label style="display:block;font-size:12px;margin-bottom:4px;">Keep last N days:</label>
				<input type="number" name="sft_prune_days_manual" value="<?php echo (int) get_option( 'sft_audit_prune_days', 365 ); ?>"
				       min="1" style="width:100%;margin-bottom:8px;">
				<input type="submit" name="sft_manual_prune" value="Prune Now" class="button" style="width:100%;">
			</form>
		</div>

		<!-- Table -->
		<div class="sft-filter-body">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
				<span style="color:#888;font-size:13px;"><?php echo number_format( $total ); ?> event<?php echo $total !== 1 ? 's' : ''; ?> found</span>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button">Export to CSV</a>
			</div>

			<?php $audit_sort_base = array_merge( [ 'page' => 'sft-pro', 'tab' => 'audit' ], $filter_args ); ?>
			<table class="sft-table widefat striped">
				<thead><tr>
					<?php echo sft_sortable_th( 'Event',     'event_type', $a_orderby, $a_order, $audit_sort_base ); ?>
					<?php echo sft_sortable_th( 'Vault',     'vault_id',   $a_orderby, $a_order, $audit_sort_base ); ?>
					<?php echo sft_sortable_th( 'Share',     'share_id',   $a_orderby, $a_order, $audit_sort_base ); ?>
					<?php echo sft_sortable_th( 'Actor',     'actor_id',   $a_orderby, $a_order, $audit_sort_base ); ?>
					<th data-nosort>IP</th>
					<th data-nosort>Details</th>
					<?php echo sft_sortable_th( 'Date/Time', 'created_at', $a_orderby, $a_order, $audit_sort_base ); ?>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7" style="text-align:center;color:#888;padding:24px;">No audit events match the current filters.</td></tr>
				<?php else : foreach ( $rows as $row ) :
					$actor  = $row->actor_id ? get_userdata( (int) $row->actor_id ) : null;
					$detail = $row->details ? json_decode( $row->details, true ) : [];
					$detail_str = $detail
						? implode( '; ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $detail ), $detail ) )
						: '';
					$vault_link = $row->vault_id
						? '<a href="' . esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => (int) $row->vault_id ], admin_url( 'admin.php' ) ) ) . '">#' . (int) $row->vault_id . '</a>'
						: '—';
				?>
					<tr>
						<td><strong><?php echo esc_html( sft_audit_event_label( $row->event_type ) ); ?></strong></td>
						<td><?php echo $vault_link; // escaped above ?></td>
						<td><?php echo $row->share_id ? '#' . (int) $row->share_id : '—'; ?></td>
						<td><?php echo $actor ? esc_html( $actor->user_login ) : '<em>system</em>'; ?></td>
						<td style="font-size:11px;color:#888;"><?php echo esc_html( $row->ip_address ); ?></td>
						<td style="font-size:12px;color:#666;max-width:260px;word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
						<td style="color:#888;white-space:nowrap;font-size:12px;"><?php echo esc_html( sft_format_date( $row->created_at ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php sft_render_pagination( $paged, $total_pages, array_merge( [ 'tab' => 'audit' ], $filter_args ) ); ?>
		</div>
	</div>
	<?php
}

// ─── Helper: extract filter args from $_GET ───────────────────────────────────

function sft_audit_filter_args_from_get(): array {
	$f_from    = sanitize_text_field( $_GET['f_from'] ?? '' );
	$f_to      = sanitize_text_field( $_GET['f_to'] ?? '' );
	$f_details = sanitize_text_field( $_GET['f_details'] ?? '' );

	return [
		'event_type'     => sanitize_key( $_GET['f_event'] ?? '' ),
		'vault_id'       => (int) ( $_GET['f_vault_id'] ?? 0 ) ?: null,
		'date_from'      => $f_from ? $f_from . ' 00:00:00' : '',
		'date_to'        => $f_to   ? $f_to   . ' 23:59:59' : '',
		'details_search' => $f_details,
	];
}
