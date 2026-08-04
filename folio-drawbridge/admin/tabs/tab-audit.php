<?php
/**
 * Audit Log tab — filterable, sortable, paginated view of all plugin events.
 *
 * Filters: event type, vault ID, date range.
 * Export: CSV of the filtered result set.
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters here are read-only display filters and sort state; no state changes occur on GET.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CSV export streams rows to php://output; WP_Filesystem has no output-stream support.

// ─── CSV export (must run before any output — hooked via admin_init in admin class) ──
add_action( 'admin_init', 'folio_drawbridge_maybe_export_audit_csv' );

function folio_drawbridge_maybe_export_audit_csv(): void {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'folio-drawbridge' ) {
		return;
	}
	if ( sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) !== 'audit' || ! isset( $_GET['folio_drawbridge_export_audit'] ) ) {
		return;
	}
	if ( ! folio_drawbridge_is_admin() ) {
		return;
	}
	check_admin_referer( 'folio_drawbridge_export_audit' );

	$args = folio_drawbridge_audit_filter_args_from_get();
	$args['per_page'] = 9999;
	$rows = folio_drawbridge_get_audit_logs( $args );

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="folio-drawbridge-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$fh = fopen( 'php://output', 'w' );
	fputcsv( $fh, [ 'ID', 'Event', 'Vault ID', 'Share ID', 'Actor', 'IP', 'Details', 'Date/Time (Site Timezone)' ] );

	// One query for every actor in the export rather than one per row.
	cache_users( array_filter( wp_list_pluck( $rows, 'actor_id' ) ) );

	foreach ( $rows as $row ) {
		$actor  = $row->actor_id ? ( get_userdata( (int) $row->actor_id )->user_login ?? $row->actor_id ) : 'system';
		$detail = $row->details ? str_replace( [ "\r", "\n" ], ' ', $row->details ) : '';
		fputcsv( $fh, array_map( 'folio_drawbridge_csv_safe', [
			$row->id,
			folio_drawbridge_audit_event_label( $row->event_type ),
			$row->vault_id ?? '',
			$row->share_id ?? '',
			$actor,
			$row->ip_address,
			$detail,
			folio_drawbridge_format_date( $row->created_at, 'Y-m-d H:i:s' ),
		] ) );
	}

	fclose( $fh );
	exit;
}

// ─── Tab renderer ─────────────────────────────────────────────────────────────

function folio_drawbridge_render_tab_audit(): void {
	$args        = folio_drawbridge_audit_filter_args_from_get();
	$per_page    = 25;
	$paged       = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
	$a_orderby   = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'created_at' ) );
	$a_order     = strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'DESC' ) ) ) === 'ASC' ? 'ASC' : 'DESC';
	$args['per_page'] = $per_page;
	$args['paged']    = $paged;
	$args['orderby']  = $a_orderby;
	$args['order']    = $a_order;

	$rows        = folio_drawbridge_get_audit_logs( $args );
	$total       = folio_drawbridge_count_audit_logs( $args );
	$total_pages = (int) ceil( $total / $per_page );

	$f_event    = sanitize_key( wp_unslash( $_GET['f_event'] ?? '' ) );
	$f_vault_id = absint( wp_unslash( $_GET['f_vault_id'] ?? 0 ) );
	$f_from     = sanitize_text_field( wp_unslash( $_GET['f_from'] ?? '' ) );
	$f_to       = sanitize_text_field( wp_unslash( $_GET['f_to'] ?? '' ) );
	$f_details  = sanitize_text_field( wp_unslash( $_GET['f_details'] ?? '' ) );

	$filter_args = array_filter( [
		'f_event'    => $f_event,
		'f_vault_id' => $f_vault_id ?: '',
		'f_from'     => $f_from,
		'f_to'       => $f_to,
		'f_details'  => $f_details,
	] );

	// All distinct event types for the filter dropdown.
	global $wpdb;
	$event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$wpdb->prefix}folio_drawbridge_audit ORDER BY event_type ASC" ) ?: [];

	$export_url = add_query_arg(
		array_merge(
			[ 'page' => 'folio-drawbridge', 'tab' => 'audit', 'folio_drawbridge_export_audit' => '1' ],
			$filter_args,
			[ '_wpnonce' => wp_create_nonce( 'folio_drawbridge_export_audit' ) ]
		),
		admin_url( 'admin.php' )
	);
	?>

	<div style="display:flex; gap:20px; align-items:flex-start; margin-top:20px;">

		<!-- Filter panel -->
		<div class="folio-drawbridge-card folio-drawbridge-filter-panel" style="margin-top:0;">
			<h3 style="margin-top:0;">Filter Events</h3>
			<form method="get">
				<input type="hidden" name="page" value="folio-drawbridge">
				<input type="hidden" name="tab"  value="audit">

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Event Type</label>
					<select name="f_event" style="width:100%;">
						<option value="">All</option>
						<?php foreach ( $event_types as $et ) : ?>
							<option value="<?php echo esc_attr( $et ); ?>" <?php selected( $f_event, $et ); ?>>
								<?php echo esc_html( folio_drawbridge_audit_event_label( $et ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Vault ID</label>
					<input type="number" name="f_vault_id" value="<?php echo $f_vault_id ? (int) $f_vault_id : ''; ?>" style="width:100%;" placeholder="e.g. 42" min="1">
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
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) ); ?>"
					   class="button" style="width:100%;margin-top:6px;text-align:center;box-sizing:border-box;">Clear</a>
				<?php endif; ?>
			</form>

			<!-- Manual prune -->
			<hr style="margin:16px 0;">
			<h4 style="margin:0 0 8px;font-size:13px;">Manual Prune</h4>
			<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) ); ?>"
			      onsubmit="return confirm('Delete all audit entries older than the specified number of days?');">
				<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
				<label style="display:block;font-size:12px;margin-bottom:4px;">Keep last N days:</label>
				<input type="number" name="folio_drawbridge_prune_days_manual" value="<?php echo (int) get_option( 'folio_drawbridge_audit_prune_days', 365 ); ?>"
				       min="1" style="width:100%;margin-bottom:8px;">
				<input type="submit" name="folio_drawbridge_manual_prune" value="Prune Now" class="button" style="width:100%;">
			</form>
		</div>

		<!-- Table -->
		<div class="folio-drawbridge-filter-body">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
				<span style="color:#888;font-size:13px;"><?php echo esc_html( number_format( $total ) ); ?> event<?php echo $total !== 1 ? 's' : ''; ?> found</span>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button">Export to CSV</a>
			</div>

			<?php $audit_sort_base = array_merge( [ 'page' => 'folio-drawbridge', 'tab' => 'audit' ], $filter_args ); ?>
			<table class="folio-drawbridge-table widefat striped">
				<thead><tr>
					<?php echo folio_drawbridge_sortable_th( 'Event',     'event_type', $a_orderby, $a_order, $audit_sort_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML. ?>
					<?php echo folio_drawbridge_sortable_th( 'Vault',     'vault_id',   $a_orderby, $a_order, $audit_sort_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML. ?>
					<?php echo folio_drawbridge_sortable_th( 'Share',     'share_id',   $a_orderby, $a_order, $audit_sort_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML. ?>
					<?php echo folio_drawbridge_sortable_th( 'Actor',     'actor_id',   $a_orderby, $a_order, $audit_sort_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML. ?>
					<th data-nosort>IP</th>
					<th data-nosort>Details</th>
					<?php echo folio_drawbridge_sortable_th( 'Date/Time', 'created_at', $a_orderby, $a_order, $audit_sort_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML. ?>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7" style="text-align:center;color:#888;padding:24px;">No audit events match the current filters.</td></tr>
				<?php else :
					// One query for every actor on this page, not one per row.
					cache_users( array_filter( wp_list_pluck( $rows, 'actor_id' ) ) );
					foreach ( $rows as $row ) :
					$actor  = $row->actor_id ? get_userdata( (int) $row->actor_id ) : null;
					$detail = $row->details ? json_decode( $row->details, true ) : [];
					$detail_str = $detail
						? implode( '; ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $detail ), $detail ) )
						: '';
					$vault_link = $row->vault_id
						? '<a href="' . esc_url( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => (int) $row->vault_id ], admin_url( 'admin.php' ) ) ) . '">#' . (int) $row->vault_id . '</a>'
						: '—';
				?>
					<tr>
						<td><strong><?php echo esc_html( folio_drawbridge_audit_event_label( $row->event_type ) ); ?></strong></td>
						<td><?php echo $vault_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url/esc_html parts. ?></td>
						<td><?php echo $row->share_id ? '#' . (int) $row->share_id : '—'; ?></td>
						<td><?php echo $actor ? esc_html( $actor->user_login ) : '<em>system</em>'; ?></td>
						<td style="font-size:11px;color:#888;"><?php echo esc_html( $row->ip_address ); ?></td>
						<td style="font-size:12px;color:#666;max-width:260px;word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
						<td style="color:#888;white-space:nowrap;font-size:12px;"><?php echo esc_html( folio_drawbridge_format_date( $row->created_at ) ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php folio_drawbridge_render_pagination( $paged, $total_pages, array_merge( [ 'tab' => 'audit' ], $filter_args ) ); ?>
		</div>
	</div>
	<?php
}

// ─── Helper: extract filter args from $_GET ───────────────────────────────────

function folio_drawbridge_audit_filter_args_from_get(): array {
	$f_from    = sanitize_text_field( wp_unslash( $_GET['f_from'] ?? '' ) );
	$f_to      = sanitize_text_field( wp_unslash( $_GET['f_to'] ?? '' ) );
	$f_details = sanitize_text_field( wp_unslash( $_GET['f_details'] ?? '' ) );

	return [
		'event_type'     => sanitize_key( wp_unslash( $_GET['f_event'] ?? '' ) ),
		'vault_id'       => absint( wp_unslash( $_GET['f_vault_id'] ?? 0 ) ) ?: null,
		'date_from'      => $f_from ? $f_from . ' 00:00:00' : '',
		'date_to'        => $f_to   ? $f_to   . ' 23:59:59' : '',
		'details_search' => $f_details,
	];
}
