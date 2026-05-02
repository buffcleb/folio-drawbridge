<?php
/**
 * User dashboard — vault list view.
 *
 * Shows the current user's vaults with status, file count, and share count.
 * Includes an inline "Create Vault" form at the bottom.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_user_vault_list(): void {
	global $wpdb;

	$user_id     = get_current_user_id();
	$per_page    = 20;
	$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$vl_orderby  = sanitize_key( $_GET['orderby'] ?? 'created_at' );
	$vl_order    = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
	$vaults      = sft_get_user_vaults( $user_id, [
		'per_page' => $per_page,
		'paged'    => $paged,
		'orderby'  => $vl_orderby,
		'order'    => $vl_order,
	] );
	$total       = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}sft_vaults WHERE owner_id = %d", $user_id
	) );
	$total_pages = (int) ceil( $total / $per_page );
	$base_url    = add_query_arg( [ 'page' => 'sft-my-vaults' ], admin_url( 'admin.php' ) );
	$vl_sort_base = [ 'page' => 'sft-my-vaults' ];
	?>

	<!-- ── Vault list ──────────────────────────────────────────────────────── -->
	<div class="sft-card" style="margin-top:20px;">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
			<h2 style="margin:0; font-size:18px;">Your Vaults
				<span style="font-size:14px; font-weight:400; color:#888;">(<?php echo number_format( $total ); ?>)</span>
			</h2>
		</div>

		<?php if ( ! $vaults ) : ?>
			<p style="color:#888; font-size:13px;">You haven't created any vaults yet. Use the form below to get started.</p>
		<?php else : ?>
			<table class="sft-table widefat striped">
				<thead><tr>
					<?php echo sft_sortable_th( 'Vault Name', 'name',       $vl_orderby, $vl_order, $vl_sort_base ); ?>
					<?php echo sft_sortable_th( 'Status',     'status',     $vl_orderby, $vl_order, $vl_sort_base ); ?>
					<th data-nosort>Files</th>
					<th data-nosort>Shares</th>
					<?php echo sft_sortable_th( 'Created',    'created_at', $vl_orderby, $vl_order, $vl_sort_base ); ?>
					<?php echo sft_sortable_th( 'Expires',    'expires_at', $vl_orderby, $vl_order, $vl_sort_base ); ?>
					<th data-nosort></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $vaults as $vault ) :
					$file_count  = sft_get_vault_file_count( (int) $vault->id );
					$share_count = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}sft_shares WHERE vault_id = %d", $vault->id
					) );
					$detail_url  = add_query_arg( [ 'page' => 'sft-my-vaults', 'vault_id' => (int) $vault->id ], admin_url( 'admin.php' ) );
				?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>" style="font-weight:600;">
								<?php echo esc_html( $vault->name ); ?>
							</a>
							<?php if ( $vault->description ) : ?>
								<br><span style="font-size:11px;color:#888;"><?php echo esc_html( wp_trim_words( $vault->description, 10 ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="sft-badge sft-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span></td>
						<td><?php echo (int) $file_count; ?></td>
						<td><?php echo (int) $share_count; ?></td>
						<td style="color:#888; font-size:12px;"><?php echo esc_html( sft_format_date( $vault->created_at, 'M j, Y' ) ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $vault->expires_at ? esc_html( sft_format_date( $vault->expires_at, 'M j, Y' ) ) : '—'; ?></td>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>" class="sft-btn">Open</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div style="display:flex; gap:4px; margin-top:14px; justify-content:center;">
					<?php for ( $p = 1; $p <= $total_pages; $p++ ) :
						$url = add_query_arg( [ 'page' => 'sft-my-vaults', 'paged' => $p ], admin_url( 'admin.php' ) );
						$style = $p === $paged
							? 'background:#2271b1;color:#fff;border-color:#2271b1;font-weight:600;'
							: 'background:#fff;color:#2271b1;';
					?>
						<a href="<?php echo esc_url( $url ); ?>"
						   style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #ccd0d4;border-radius:6px;font-size:13px;text-decoration:none;<?php echo $style; ?>">
							<?php echo $p; ?>
						</a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- ── Create Vault form ───────────────────────────────────────────────── -->
	<div class="sft-card">
		<h2 style="margin-top:0; font-size:17px;">Create New Vault</h2>
		<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-my-vaults' ], admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>

			<div style="display:flex; gap:20px; flex-wrap:wrap;">
				<div style="flex:2; min-width:200px;">
					<div class="sft-form-row">
						<label for="sft-vault-name">Vault Name <span style="color:#d63638;">*</span></label>
						<input type="text" id="sft-vault-name" name="vault_name" placeholder="e.g. Q1 Financial Reports" maxlength="255" required>
					</div>
					<div class="sft-form-row">
						<label for="sft-vault-desc">Description <span style="font-weight:400;color:#888;">(optional)</span></label>
						<textarea id="sft-vault-desc" name="vault_desc" rows="2" placeholder="What is this vault for?"></textarea>
					</div>
				</div>
				<div style="flex:1; min-width:160px;">
					<div class="sft-form-row">
						<label for="sft-vault-expires">Expiry Date <span style="font-weight:400;color:#888;">(optional)</span></label>
						<input type="date" id="sft-vault-expires" name="vault_expires" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
					</div>
				</div>
			</div>

			<div class="sft-form-actions">
				<input type="submit" name="sft_ud_create_vault" value="Create Vault" class="button button-primary">
			</div>
		</form>
	</div>
	<?php
}
