<?php
/**
 * User dashboard — vault detail view.
 *
 * Shows the selected vault's files, shares, and audit log (scoped to this
 * vault and this owner). Provides forms to upload files, create shares, and
 * revoke shares. File deletion and vault deletion are also available.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_user_vault_detail( int $vault_id ): void {
	$user_id  = get_current_user_id();
	$vault    = sft_get_vault( $vault_id );
	$back_url = add_query_arg( [ 'page' => 'sft-my-vaults' ], admin_url( 'admin.php' ) );

	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! sft_is_admin() ) ) {
		echo '<p><a href="' . esc_url( $back_url ) . '">← Back to My Vaults</a></p>';
		echo '<p style="color:#d63638;">Vault not found or access denied.</p>';
		return;
	}

	$files   = sft_get_vault_files( $vault_id );
	$shares  = sft_get_vault_shares( $vault_id );
	$audit   = sft_get_audit_logs( [ 'vault_id' => $vault_id, 'per_page' => 20 ] );

	$form_url   = add_query_arg( [ 'page' => 'sft-my-vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) );
	$is_active  = $vault->status === 'active';
	$max_file_mb = (int) get_option( 'sft_max_file_mb', 50 );

	// Share form global limits (non-admin users are subject to these).
	$is_admin_user          = sft_is_admin();
	$allow_unlimited_dl     = get_option( 'sft_allow_unlimited_downloads', '1' ) === '1';
	$default_max_downloads  = (int) get_option( 'sft_default_max_downloads', 0 );
	$max_download_ceiling   = (int) get_option( 'sft_max_download_limit', 0 );
	$allow_no_expiry        = get_option( 'sft_allow_no_expiry', '1' ) === '1';
	$default_expiry_days    = (int) get_option( 'sft_default_expiry_days', 0 );
	$max_expiry_days        = (int) get_option( 'sft_max_expiry_days', 0 );

	// Pre-fill values for the share form.
	$share_default_dl      = $default_max_downloads;
	$share_dl_max_attr     = ( ! $is_admin_user && $max_download_ceiling > 0 ) ? $max_download_ceiling : '';
	$share_dl_min          = ( ! $is_admin_user && ! $allow_unlimited_dl ) ? 1 : 0;
	$share_expiry_default  = $default_expiry_days > 0 ? gmdate( 'Y-m-d', strtotime( "+{$default_expiry_days} days" ) ) : '';
	$share_expiry_max_attr = ( ! $is_admin_user && $max_expiry_days > 0 )
		? gmdate( 'Y-m-d', strtotime( "+{$max_expiry_days} days" ) )
		: '';
	$share_expiry_required = ( ! $is_admin_user && ! $allow_no_expiry );

	$today = gmdate( 'Y-m-d' );
	?>

	<p style="margin-top:16px;"><a href="<?php echo esc_url( $back_url ); ?>">← Back to My Vaults</a></p>

	<!-- Vault header -->
	<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-top:4px;">
		<div>
			<h2 style="margin:0 0 4px; display:flex; align-items:center; gap:10px;">
				<?php echo esc_html( $vault->name ); ?>
				<span class="sft-badge sft-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span>
			</h2>
			<p style="color:#888; font-size:13px; margin:0;">
				Created <?php echo esc_html( sft_format_date( $vault->created_at, 'M j, Y' ) ); ?>
				<?php if ( $vault->expires_at ) : ?>
					&bull; Expires <?php echo esc_html( sft_format_date( $vault->expires_at, 'M j, Y' ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $vault->description ) : ?>
				<p style="color:#555; font-size:13px; margin:4px 0 0;"><?php echo esc_html( $vault->description ); ?></p>
			<?php endif; ?>
		</div>
		<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start;">
			<!-- Edit expiry -->
			<button type="button" class="button" onclick="sftUdToggle('sft-expiry-form')">Edit Expiry</button>
			<?php if ( $is_active ) : ?>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>"
			      onsubmit="return confirm('Permanently delete vault &quot;<?php echo esc_js( $vault->name ); ?>&quot; and all its files?');">
				<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
				<input type="submit" name="sft_ud_delete_vault" value="Delete Vault" class="button sft-danger">
			</form>
			<?php endif; ?>
		</div>
	</div>

	<!-- Edit vault expiry inline form -->
	<div id="sft-expiry-form" style="display:none; margin-top:12px;">
		<div class="sft-card" style="margin-top:0; padding:16px;">
			<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
				<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
				<div class="sft-form-row" style="margin:0; flex:1; min-width:160px;">
					<label for="sft-vault-new-expires" style="margin-bottom:4px;">Expiry Date <span style="font-weight:400;color:#888;">(leave blank to remove)</span></label>
					<input type="date" id="sft-vault-new-expires" name="vault_new_expires"
					       value="<?php echo $vault->expires_at ? esc_attr( gmdate( 'Y-m-d', strtotime( $vault->expires_at ) ) ) : ''; ?>"
					       min="<?php echo esc_attr( $today ); ?>">
				</div>
				<div>
					<input type="submit" name="sft_ud_edit_vault_expiry" value="Save Expiry" class="button button-primary">
					<button type="button" class="button" style="margin-left:4px;" onclick="sftUdToggle('sft-expiry-form')">Cancel</button>
				</div>
			</form>
		</div>
	</div>

	<!-- ── Files ──────────────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Files (<?php echo count( $files ); ?>)</h3>

		<?php if ( $files ) : ?>
			<table id="sft-ud-files-<?php echo $vault_id; ?>" class="sft-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Filename</th><th>Size</th><th>Uploaded</th><th data-nosort></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) : ?>
					<tr>
						<td><?php echo esc_html( $f->original_name ); ?></td>
						<td style="color:#888;"><?php echo esc_html( size_format( $f->file_size ) ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo esc_html( sft_format_date( $f->uploaded_at ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
							      onsubmit="return confirm('Delete <?php echo esc_js( $f->original_name ); ?>?');">
								<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
								<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
								<input type="hidden" name="file_id"  value="<?php echo (int) $f->id; ?>">
								<input type="submit" name="sft_ud_delete_file" value="Delete" class="sft-btn sft-danger">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#888; font-size:13px; margin-bottom:16px;">No files uploaded yet.</p>
		<?php endif; ?>

		<?php if ( $is_active ) : ?>
		<hr style="margin:0 0 16px;">
		<h4 style="margin:0 0 10px; font-size:13px; font-weight:700; text-transform:uppercase; color:#888; letter-spacing:.5px;">Upload File</h4>
		<div>
			<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
				<div style="flex:1; min-width:200px;">
					<label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
						Files <span style="font-weight:400; color:#888;">(max <?php echo $max_file_mb; ?> MB each — hold Ctrl/Cmd to select multiple)</span>
					</label>
					<input type="file" id="sft-ud-file-input" multiple style="width:100%; padding:6px;">
				</div>
				<div>
					<button type="button" id="sft-ud-upload-btn" class="button button-primary" onclick="sftUdUpload()">
						Encrypt &amp; Upload
					</button>
				</div>
			</div>
			<div id="sft-ud-file-queue" style="margin-top:10px;"></div>
			<p id="sft-ud-upload-error" style="display:none; color:#d63638; font-size:13px; margin:8px 0 0;"></p>
		</div>
		<script>
		var sftUd = {
			ajaxUrl:  <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:    <?php echo wp_json_encode( wp_create_nonce( 'sft_user_nonce' ) ); ?>,
			vaultId:  <?php echo (int) $vault_id; ?>,
			chunkSize:<?php echo sft_chunk_size_bytes(); ?>
		};
		function sftUdGenId() {
			return Array.from(crypto.getRandomValues(new Uint8Array(16)))
				.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
		}
		function sftUdToggle(id) {
			var el = document.getElementById(id);
			el.style.display = el.style.display === 'none' ? '' : 'none';
		}
		async function sftUdUploadOne(file, rowEl) {
			var bar = rowEl.querySelector('.sft-ud-bar');
			var lbl = rowEl.querySelector('.sft-ud-lbl');
			var CHUNK = sftUd.chunkSize;
			var total = Math.ceil(file.size / CHUNK) || 1;
			var uid   = sftUdGenId();
			for (var i = 0; i < total; i++) {
				var start = i * CHUNK;
				var fd = new FormData();
				fd.append('action',       'sft_upload_chunk');
				fd.append('_wpnonce',     sftUd.nonce);
				fd.append('vault_id',     sftUd.vaultId);
				fd.append('upload_id',    uid);
				fd.append('chunk_index',  i);
				fd.append('total_chunks', total);
				fd.append('file_name',    file.name);
				fd.append('total_size',   file.size);
				fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
				var r = await fetch(sftUd.ajaxUrl, {method:'POST', body:fd});
				var j = await r.json();
				if (!j.success) throw new Error(j.data || 'Upload failed.');
				var pct = Math.round((i + 1) / total * 100);
				bar.style.width = pct + '%';
				lbl.textContent = j.data.complete ? 'Done' : pct + '%';
			}
		}
		function sftUdMakeRow(file) {
			var row = document.createElement('div');
			row.style.cssText = 'margin-bottom:6px;padding:8px 10px;background:#f6f7f7;border-radius:4px;font-size:12px;';
			row.innerHTML =
				'<div style="display:flex;justify-content:space-between;margin-bottom:4px;">'
				+ '<span style="font-weight:600;">' + sftEsc(file.name) + '</span>'
				+ '<span class="sft-ud-lbl" style="color:#888;">Queued</span></div>'
				+ '<div style="background:#e0e0e0;border-radius:3px;height:8px;overflow:hidden;">'
				+ '<div class="sft-ud-bar" style="background:#2271b1;height:100%;width:0%;transition:width .2s;"></div></div>';
			return row;
		}
		function sftEsc(s) {
			return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
		}
		async function sftUdUpload() {
			var input  = document.getElementById('sft-ud-file-input');
			var errEl  = document.getElementById('sft-ud-upload-error');
			var queueEl = document.getElementById('sft-ud-file-queue');
			errEl.style.display = 'none';
			if (!input.files.length) {
				errEl.textContent = 'Please select at least one file.';
				errEl.style.display = '';
				return;
			}
			var btn   = document.getElementById('sft-ud-upload-btn');
			btn.disabled = true;
			queueEl.innerHTML = '';
			var files = Array.from(input.files);
			var rows  = files.map(function(f) {
				var row = sftUdMakeRow(f);
				queueEl.appendChild(row);
				return row;
			});
			var hasError = false;
			for (var i = 0; i < files.length; i++) {
				var lbl = rows[i].querySelector('.sft-ud-lbl');
				lbl.textContent = 'Uploading…';
				try {
					await sftUdUploadOne(files[i], rows[i]);
					lbl.style.color = '#0a3622';
				} catch(e) {
					lbl.textContent = 'Error: ' + e.message;
					lbl.style.color = '#d63638';
					hasError = true;
				}
			}
			if (!hasError) {
				window.location.reload();
			} else {
				btn.disabled = false;
			}
		}
		</script>
		<?php endif; ?>
	</div>

	<!-- ── Shares ─────────────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Shares (<?php echo count( $shares ); ?>)</h3>

		<?php if ( $shares ) : ?>
			<table id="sft-ud-shares-<?php echo $vault_id; ?>" class="sft-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Recipient</th><th>Status</th><th>Downloads</th><th>Expires</th><th>Last Access</th><th data-nosort></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $shares as $s ) :
					$dl_info    = $s->max_downloads > 0
						? (int) $s->download_count . ' / ' . (int) $s->max_downloads
						: (int) $s->download_count . ' / ∞';
					$editable   = in_array( $s->status, [ 'pending', 'active' ], true );
					$edit_id    = 'sft-share-edit-' . (int) $s->id;
					$cur_expiry = $s->expires_at ? gmdate( 'Y-m-d', strtotime( $s->expires_at ) ) : '';
				?>
					<tr>
						<td><?php echo esc_html( $s->recipient_email ); ?></td>
						<td><span class="sft-badge sft-badge-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span></td>
						<td style="font-size:13px;"><?php echo esc_html( $dl_info ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->expires_at ? esc_html( sft_format_date( $s->expires_at, 'M j, Y' ) ) : '—'; ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->last_accessed ? esc_html( sft_format_date( $s->last_accessed ) ) : 'Never'; ?></td>
						<td style="white-space:nowrap;">
							<?php if ( $editable ) : ?>
								<button type="button" class="sft-btn" onclick="sftUdToggle('<?php echo esc_js( $edit_id ); ?>')" style="margin-right:4px;">Edit</button>
								<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;margin-right:4px;">
									<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
									<input type="hidden" name="vault_id"  value="<?php echo $vault_id; ?>">
									<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
									<input type="submit" name="sft_ud_resend_share" value="Resend"
									       class="sft-btn" title="Resend invite to <?php echo esc_attr( $s->recipient_email ); ?>">
								</form>
								<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
								      onsubmit="return confirm('Revoke access for <?php echo esc_js( $s->recipient_email ); ?>?');">
									<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
									<input type="hidden" name="vault_id"  value="<?php echo $vault_id; ?>">
									<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
									<input type="submit" name="sft_ud_revoke_share" value="Revoke" class="sft-btn sft-danger">
								</form>
							<?php else : ?>
								<span style="color:#aaa; font-size:12px;"><?php echo esc_html( ucfirst( $s->status ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $editable ) : ?>
					<tr id="<?php echo esc_attr( $edit_id ); ?>" data-subrow style="display:none; background:#f9fafc;">
						<td colspan="6" style="padding:12px 10px;">
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
								<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
								<input type="hidden" name="vault_id"  value="<?php echo $vault_id; ?>">
								<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
								<div class="sft-form-row" style="margin:0; min-width:120px;">
									<label style="font-size:12px;">
										Download Limit
										<?php if ( $is_admin_user || $allow_unlimited_dl ) : ?>
											<span style="font-weight:400;color:#888;">(0 = ∞)</span>
										<?php endif; ?>
									</label>
									<input type="number" name="share_max_downloads"
									       value="<?php echo (int) $s->max_downloads; ?>"
									       min="<?php echo $share_dl_min; ?>"
									       <?php if ( $share_dl_max_attr !== '' ) : ?>max="<?php echo $share_dl_max_attr; ?>"<?php endif; ?>
									       style="width:90px; padding:5px 8px; border:1px solid #d0d5dd; border-radius:4px; font-size:13px;">
								</div>
								<div class="sft-form-row" style="margin:0; min-width:160px;">
									<label style="font-size:12px;">
										Expires
										<?php if ( ! $share_expiry_required ) : ?>
											<span style="font-weight:400;color:#888;">(optional)</span>
										<?php else : ?>
											<span style="color:#d63638;">*</span>
										<?php endif; ?>
									</label>
									<input type="date" name="share_new_expires"
									       value="<?php echo esc_attr( $cur_expiry ); ?>"
									       min="<?php echo esc_attr( $today ); ?>"
									       <?php if ( $share_expiry_max_attr !== '' ) : ?>max="<?php echo esc_attr( $share_expiry_max_attr ); ?>"<?php endif; ?>
									       <?php if ( $share_expiry_required ) : ?>required<?php endif; ?>
									       style="padding:5px 8px; border:1px solid #d0d5dd; border-radius:4px; font-size:13px;">
								</div>
								<div>
									<input type="submit" name="sft_ud_edit_share" value="Save" class="button button-primary">
									<button type="button" class="button" style="margin-left:4px;" onclick="sftUdToggle('<?php echo esc_js( $edit_id ); ?>')">Cancel</button>
								</div>
							</form>
						</td>
					</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#888; font-size:13px; margin-bottom:16px;">No shares created yet.</p>
		<?php endif; ?>

		<?php if ( $is_active ) : ?>
		<hr style="margin:0 0 16px;">
		<h4 style="margin:0 0 10px; font-size:13px; font-weight:700; text-transform:uppercase; color:#888; letter-spacing:.5px;">Create New Share</h4>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
			<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
			<div style="display:flex; gap:16px; flex-wrap:wrap;">
				<div style="flex:2; min-width:200px;">
					<div class="sft-form-row">
						<label for="sft-share-email">Recipient Email <span style="color:#d63638;">*</span></label>
						<input type="email" id="sft-share-email" name="share_email" placeholder="recipient@example.com" required>
					</div>
				</div>
				<div style="flex:1; min-width:130px;">
					<div class="sft-form-row">
						<label for="sft-share-maxdl">
							Download Limit
							<?php if ( $is_admin_user || $allow_unlimited_dl ) : ?>
								<span style="font-weight:400;color:#888;">(0 = ∞)</span>
							<?php endif; ?>
						</label>
						<input type="number" id="sft-share-maxdl" name="share_max_downloads"
						       value="<?php echo $share_default_dl; ?>"
						       min="<?php echo $share_dl_min; ?>"
						       <?php if ( $share_dl_max_attr !== '' ) : ?>max="<?php echo $share_dl_max_attr; ?>"<?php endif; ?>>
					</div>
				</div>
				<div style="flex:1; min-width:160px;">
					<div class="sft-form-row">
						<label for="sft-share-expires">
							Link Expires
							<?php if ( ! $share_expiry_required ) : ?>
								<span style="font-weight:400;color:#888;">(optional)</span>
							<?php else : ?>
								<span style="color:#d63638;">*</span>
							<?php endif; ?>
						</label>
						<input type="date" id="sft-share-expires" name="share_expires"
						       value="<?php echo esc_attr( $share_expiry_default ); ?>"
						       min="<?php echo esc_attr( $today ); ?>"
						       <?php if ( $share_expiry_max_attr !== '' ) : ?>max="<?php echo esc_attr( $share_expiry_max_attr ); ?>"<?php endif; ?>
						       <?php if ( $share_expiry_required ) : ?>required<?php endif; ?>>
					</div>
				</div>
			</div>
			<div class="sft-form-actions">
				<input type="submit" name="sft_ud_create_share" value="Send Invite" class="button button-primary">
			</div>
		</form>
		<?php endif; ?>
	</div>

	<!-- ── Vault Audit Log ────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Activity Log <span style="font-size:13px; font-weight:400; color:#888;">(last 20 events)</span></h3>
		<?php if ( ! $audit ) : ?>
			<p style="color:#888; font-size:13px;">No activity recorded for this vault yet.</p>
		<?php else : ?>
			<table id="sft-ud-audit-<?php echo $vault_id; ?>" class="sft-table widefat striped">
				<thead><tr>
					<th>Event</th><th data-nosort>Details</th><th>IP</th><th>Date/Time</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $audit as $row ) :
					$detail     = $row->details ? json_decode( $row->details, true ) : [];
					$detail_str = $detail
						? implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $detail ), $detail ) )
						: '';
				?>
					<tr>
						<td><strong><?php echo esc_html( sft_audit_event_label( $row->event_type ) ); ?></strong></td>
						<td style="font-size:12px; color:#666; max-width:280px; word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
						<td style="font-size:11px; color:#aaa;"><?php echo esc_html( $row->ip_address ); ?></td>
						<td style="color:#888; white-space:nowrap; font-size:12px;"><?php echo esc_html( sft_format_date( $row->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		sftSortTable('sft-ud-files-<?php echo (int) $vault_id; ?>');
		sftSortTable('sft-ud-shares-<?php echo (int) $vault_id; ?>');
		sftSortTable('sft-ud-audit-<?php echo (int) $vault_id; ?>');
	});
	</script>
	<?php
}
