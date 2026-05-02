<?php
/**
 * Vaults tab — browse all vaults and inspect any individual vault.
 *
 * When ?vault_id=N is set, renders the vault inspector view:
 *   - Vault metadata and status controls
 *   - Files list with admin download and delete actions
 *   - Shares list with revoke action
 *   - Vault-specific audit log
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_tab_vaults(): void {
	$vault_id = (int) ( $_GET['vault_id'] ?? 0 );

	if ( $vault_id > 0 ) {
		sft_render_vault_inspector( $vault_id );
	} else {
		sft_render_vault_list();
	}
}

// ─── Vault list ───────────────────────────────────────────────────────────────

function sft_render_vault_list(): void {
	global $wpdb;

	$f_status  = sanitize_key( $_GET['f_status'] ?? '' );
	$f_search  = sanitize_text_field( $_GET['f_search'] ?? '' );
	$per_page  = 25;
	$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

	$query_args = [
		'status'   => $f_status,
		'search'   => $f_search,
		'per_page' => $per_page,
		'paged'    => $paged,
	];

	$vaults      = sft_get_all_vaults( $query_args );
	$total       = sft_count_all_vaults( $query_args );
	$total_pages = (int) ceil( $total / $per_page );

	$filter_args = array_filter( [ 'f_status' => $f_status, 'f_search' => $f_search ] );
	?>

	<div style="display:flex; gap:20px; align-items:flex-start; margin-top:20px;">

		<!-- Filter panel -->
		<div class="sft-card sft-filter-panel" style="margin-top:0;">
			<h3 style="margin-top:0;">Filter Vaults</h3>
			<form method="get">
				<input type="hidden" name="page" value="sft-pro">
				<input type="hidden" name="tab"  value="vaults">
				<p style="margin:0 0 8px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Status</label>
					<select name="f_status" style="width:100%;">
						<option value="">All</option>
						<?php foreach ( [ 'active', 'expired', 'revoked', 'archived' ] as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f_status, $s ); ?>><?php echo ucfirst( $s ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="margin:0 0 12px;">
					<label style="display:block;font-weight:600;margin-bottom:3px;font-size:13px;">Search Name</label>
					<input type="text" name="f_search" value="<?php echo esc_attr( $f_search ); ?>" style="width:100%;" placeholder="Vault name…">
				</p>
				<input type="submit" value="Apply" class="button button-primary" style="width:100%;">
				<?php if ( $f_status || $f_search ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults' ], admin_url( 'admin.php' ) ) ); ?>"
					   class="button" style="width:100%;margin-top:6px;text-align:center;box-sizing:border-box;">Clear</a>
				<?php endif; ?>
			</form>
		</div>

		<!-- Table -->
		<div class="sft-filter-body">
			<p style="color:#888;font-size:13px;margin:0 0 8px;"><?php echo number_format( $total ); ?> vault<?php echo $total !== 1 ? 's' : ''; ?> found</p>
			<table class="sft-table widefat striped">
				<thead><tr>
					<th>Vault Name</th>
					<th>Owner</th>
					<th>Status</th>
					<th>Files</th>
					<th>Shares</th>
					<th>Created</th>
					<th>Expires</th>
					<th>Actions</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $vaults ) : ?>
					<tr><td colspan="8" style="text-align:center;color:#888;padding:24px;">No vaults found.</td></tr>
				<?php else : foreach ( $vaults as $v ) :
					$file_count  = sft_get_vault_file_count( (int) $v->id );
					$share_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sft_shares WHERE vault_id=%d", $v->id ) );
					$inspect_url = add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => (int) $v->id ], admin_url( 'admin.php' ) );
				?>
					<tr>
						<td><a href="<?php echo esc_url( $inspect_url ); ?>" style="font-weight:600;"><?php echo esc_html( $v->name ); ?></a></td>
						<td><?php echo esc_html( $v->owner_login ?? '—' ); ?></td>
						<td><span class="sft-badge sft-badge-<?php echo esc_attr( $v->status ); ?>"><?php echo esc_html( $v->status ); ?></span></td>
						<td><?php echo (int) $file_count; ?></td>
						<td><?php echo (int) $share_count; ?></td>
						<td style="color:#888;"><?php echo esc_html( sft_format_date( $v->created_at, 'M j, Y' ) ); ?></td>
						<td style="color:#888;"><?php echo $v->expires_at ? esc_html( sft_format_date( $v->expires_at, 'M j, Y' ) ) : '—'; ?></td>
						<td>
							<a href="<?php echo esc_url( $inspect_url ); ?>" class="sft-btn">Inspect</a>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php sft_render_pagination( $paged, $total_pages, array_merge( [ 'tab' => 'vaults' ], $filter_args ) ); ?>
		</div>
	</div>
	<?php
}

// ─── Vault inspector ──────────────────────────────────────────────────────────

function sft_render_vault_inspector( int $vault_id ): void {
	$vault = sft_get_vault( $vault_id );

	$back_url = add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults' ], admin_url( 'admin.php' ) );

	if ( ! $vault ) {
		echo '<div class="sft-card" style="margin-top:20px;">';
		echo '<p>Vault not found. <a href="' . esc_url( $back_url ) . '">← Back to Vaults</a></p>';
		echo '</div>';
		return;
	}

	// Log the admin access.
	sft_log( SFT_EVT_ADMIN_VAULT_ACCESS, $vault_id, null,
		[ 'view' => 'inspector' ], get_current_user_id() );

	$owner  = get_userdata( (int) $vault->owner_id );
	$files  = sft_get_vault_files( $vault_id );
	$shares = sft_get_vault_shares( $vault_id );

	// Vault-specific audit log (last 25 events).
	$audit_rows = sft_get_audit_logs( [ 'vault_id' => $vault_id, 'per_page' => 25 ] );

	$form_url = add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) );
	?>
	<div class="sft-vault-inspector">
		<p><a href="<?php echo esc_url( $back_url ); ?>">← Back to Vaults</a></p>

		<!-- Vault header -->
		<h2>
			<?php echo esc_html( $vault->name ); ?>
			<span class="sft-badge sft-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span>
		</h2>
		<p class="sft-vault-meta" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<span>
				Owner: <strong><?php echo $owner ? esc_html( $owner->user_login ) : 'Unknown'; ?></strong>
				&bull; Created: <?php echo esc_html( sft_format_date( $vault->created_at ) ); ?>
				<?php if ( $vault->expires_at ) : ?>
					&bull; Expires: <?php echo esc_html( sft_format_date( $vault->expires_at ) ); ?>
				<?php endif; ?>
				<?php if ( $vault->description ) : ?>
					<br><?php echo esc_html( $vault->description ); ?>
				<?php endif; ?>
			</span>
			<button type="button" class="button button-small" onclick="sftAdmToggle('sft-adm-expiry-form')">Edit Expiry</button>
		</p>

		<!-- Edit vault expiry inline form -->
		<div id="sft-adm-expiry-form" style="display:none;margin-bottom:16px;">
			<div class="sft-card" style="margin-top:0;padding:14px 20px;">
				<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
					<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
					<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
					<div>
						<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">
							Expiry Date <span style="font-weight:400;color:#888;">(leave blank to remove)</span>
						</label>
						<input type="date" name="vault_new_expires"
						       value="<?php echo $vault->expires_at ? esc_attr( sft_format_date( $vault->expires_at, 'Y-m-d' ) ) : ''; ?>"
						       min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
						       style="padding:5px 8px;border:1px solid #d0d5dd;border-radius:4px;font-size:13px;">
					</div>
					<div>
						<input type="submit" name="sft_admin_edit_vault_expiry" value="Save Expiry" class="button button-primary">
						<button type="button" class="button" style="margin-left:4px;" onclick="sftAdmToggle('sft-adm-expiry-form')">Cancel</button>
					</div>
				</form>
			</div>
		</div>

		<!-- Status controls -->
		<div class="sft-card" style="margin-top:0; padding:14px 20px;">
			<form method="post" style="display:inline-flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
				<label style="font-size:13px;font-weight:600;">Change Status:</label>
				<select name="new_status" style="padding:4px 8px;font-size:13px;">
					<?php foreach ( [ 'active', 'expired', 'revoked', 'archived' ] as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $vault->status, $s ); ?>><?php echo ucfirst( $s ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="submit" name="sft_admin_vault_status" value="Update Status" class="button">
			</form>
			<form method="post" style="display:inline-block; margin-left:16px;"
			      onsubmit="return confirm('Permanently delete this vault and ALL its files? This cannot be undone.');">
				<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
				<input type="submit" name="sft_admin_delete_vault" value="Delete Vault" class="button sft-danger">
			</form>
		</div>

		<!-- Files -->
		<div class="sft-card">
			<h3 style="margin-top:0;">Encrypted Files (<?php echo count( $files ); ?>)</h3>
			<?php if ( ! $files ) : ?>
				<p style="color:#888;font-size:13px;">No files in this vault.</p>
			<?php else : ?>
				<table class="sft-table widefat">
					<thead><tr>
						<th>Filename</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Actions</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $files as $f ) :
						$uploader = get_userdata( (int) $f->uploaded_by );
					?>
						<tr>
							<td><?php echo esc_html( $f->original_name ); ?></td>
							<td><?php echo esc_html( size_format( $f->file_size ) ); ?></td>
							<td><?php echo $uploader ? esc_html( $uploader->user_login ) : '—'; ?></td>
							<td style="color:#888;"><?php echo esc_html( sft_format_date( $f->uploaded_at ) ); ?></td>
							<td style="display:flex;gap:6px;">
								<button class="sft-btn" onclick="sftAdminDownload(<?php echo (int) $f->id; ?>)">Download (Decrypted)</button>
								<form method="post" style="display:inline;"
								      onsubmit="return confirm('Permanently delete this file?');">
									<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
									<input type="hidden" name="file_id"  value="<?php echo (int) $f->id; ?>">
									<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
									<input type="submit" name="sft_admin_delete_file" value="Delete" class="sft-btn sft-danger">
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Shares -->
		<div class="sft-card">
			<h3 style="margin-top:0;">Shares (<?php echo count( $shares ); ?>)</h3>
			<?php if ( ! $shares ) : ?>
				<p style="color:#888;font-size:13px;">No shares created for this vault.</p>
			<?php else : ?>
				<table class="sft-table widefat">
					<thead><tr>
						<th>Recipient</th><th>Status</th><th>Created By</th><th>Downloads</th>
						<th>Expires</th><th>Last Access</th><th>Actions</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $shares as $s ) :
						$creator  = get_userdata( (int) $s->created_by );
						$dl_info  = $s->max_downloads > 0
							? (int) $s->download_count . ' / ' . (int) $s->max_downloads
							: (int) $s->download_count . ' / ∞';
						$editable = in_array( $s->status, [ 'pending', 'active' ], true );
						$edit_id  = 'sft-adm-share-edit-' . (int) $s->id;
						$cur_exp  = $s->expires_at ? sft_format_date( $s->expires_at, 'Y-m-d' ) : '';
					?>
						<tr>
							<td><?php echo esc_html( $s->recipient_email ); ?></td>
							<td><span class="sft-badge sft-badge-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span></td>
							<td><?php echo $creator ? esc_html( $creator->user_login ) : '—'; ?></td>
							<td><?php echo esc_html( $dl_info ); ?></td>
							<td style="color:#888;"><?php echo $s->expires_at ? esc_html( sft_format_date( $s->expires_at, 'M j, Y' ) ) : '—'; ?></td>
							<td style="color:#888;"><?php echo $s->last_accessed ? esc_html( sft_format_date( $s->last_accessed ) ) : 'Never'; ?></td>
							<td style="white-space:nowrap;">
								<?php if ( $editable ) : ?>
									<button type="button" class="sft-btn" onclick="sftAdmToggle('<?php echo esc_js( $edit_id ); ?>')" style="margin-right:4px;">Edit</button>
									<form method="post" style="display:inline;"
									      onsubmit="return confirm('Revoke this share? The recipient loses access immediately.');">
										<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
										<input type="hidden" name="share_id" value="<?php echo (int) $s->id; ?>">
										<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
										<input type="submit" name="sft_admin_revoke_share" value="Revoke" class="sft-btn sft-danger">
									</form>
								<?php else : ?>
									<span style="color:#aaa;font-size:12px;"><?php echo ucfirst( $s->status ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $editable ) : ?>
						<tr id="<?php echo esc_attr( $edit_id ); ?>" style="display:none;background:#f9fafc;">
							<td colspan="7" style="padding:12px 10px;">
								<form method="post" action="<?php echo esc_url( $form_url ); ?>"
								      style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
									<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
									<input type="hidden" name="share_id" value="<?php echo (int) $s->id; ?>">
									<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
									<div>
										<label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Download Limit <span style="font-weight:400;color:#888;">(0 = ∞)</span></label>
										<input type="number" name="share_max_downloads"
										       value="<?php echo (int) $s->max_downloads; ?>"
										       min="0" style="width:90px;padding:5px 8px;border:1px solid #d0d5dd;border-radius:4px;font-size:13px;">
									</div>
									<div>
										<label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">Expires <span style="font-weight:400;color:#888;">(optional)</span></label>
										<input type="date" name="share_new_expires"
										       value="<?php echo esc_attr( $cur_exp ); ?>"
										       min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
										       style="padding:5px 8px;border:1px solid #d0d5dd;border-radius:4px;font-size:13px;">
									</div>
									<div>
										<input type="submit" name="sft_admin_edit_share" value="Save" class="button button-primary">
										<button type="button" class="button" style="margin-left:4px;" onclick="sftAdmToggle('<?php echo esc_js( $edit_id ); ?>')">Cancel</button>
									</div>
								</form>
							</td>
						</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Vault audit log -->
		<div class="sft-card">
			<h3 style="margin-top:0;">Vault Audit Log <span style="font-size:13px;color:#888;font-weight:400;">(last 25 events)</span></h3>
			<?php if ( ! $audit_rows ) : ?>
				<p style="color:#888;font-size:13px;">No audit events recorded for this vault.</p>
			<?php else : ?>
				<table class="sft-table widefat striped">
					<thead><tr>
						<th>Event</th><th>Actor</th><th>IP</th><th>Details</th><th>Time</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $audit_rows as $row ) :
						$actor  = $row->actor_id ? get_userdata( (int) $row->actor_id ) : null;
						$detail = $row->details ? json_decode( $row->details, true ) : [];
						$detail_str = $detail
							? implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $detail ), $detail ) )
							: '';
					?>
						<tr>
							<td><strong><?php echo esc_html( sft_audit_event_label( $row->event_type ) ); ?></strong></td>
							<td><?php echo $actor ? esc_html( $actor->user_login ) : '<em>system</em>'; ?></td>
							<td style="font-size:11px;color:#888;"><?php echo esc_html( $row->ip_address ); ?></td>
							<td style="font-size:12px;color:#666;max-width:300px;word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
							<td style="color:#888;white-space:nowrap;font-size:12px;"><?php echo esc_html( sft_format_date( $row->created_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="text-align:right;margin:8px 0 0;font-size:13px;">
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit', 'f_vault_id' => $vault_id ], admin_url( 'admin.php' ) ) ); ?>">
						View full audit log for this vault →
					</a>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<script>
	function sftAdmToggle(id) {
		var el = document.getElementById(id);
		el.style.display = el.style.display === 'none' ? '' : 'none';
	}
	</script>
	<?php
}
