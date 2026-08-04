<?php
/**
 * User dashboard — vault detail view.
 *
 * Shows the selected vault's files, shares, and audit log (scoped to this
 * vault and this owner). Provides forms to upload files, create shares, and
 * revoke shares. File deletion and vault deletion are also available.
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters here are read-only display filters and sort state; no state changes occur on GET.

function folio_drawbridge_render_user_vault_detail( int $vault_id ): void {
	$user_id  = get_current_user_id();
	$vault    = folio_drawbridge_get_vault( $vault_id );
	$back_url = add_query_arg( [ 'page' => 'folio-drawbridge-vaults' ], admin_url( 'admin.php' ) );

	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) ) {
		echo '<p><a href="' . esc_url( $back_url ) . '">← Back to My Vaults</a></p>';
		echo '<p style="color:#d63638;">Vault not found or access denied.</p>';
		return;
	}

	$files   = folio_drawbridge_get_vault_files( $vault_id );
	$shares  = folio_drawbridge_get_vault_shares( $vault_id );
	$audit   = folio_drawbridge_get_audit_logs( [ 'vault_id' => $vault_id, 'per_page' => 20 ] );

	$form_url   = add_query_arg( [ 'page' => 'folio-drawbridge-vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) );
	$is_active  = $vault->status === 'active';
	$max_file_mb = (int) get_option( 'folio_drawbridge_max_file_mb', 50 );

	// Share form global limits (non-admin users are subject to these).
	$is_admin_user          = folio_drawbridge_is_admin();
	$allow_unlimited_dl     = get_option( 'folio_drawbridge_allow_unlimited_downloads', '1' ) === '1';
	$default_max_downloads  = (int) get_option( 'folio_drawbridge_default_max_downloads', 0 );
	$max_download_ceiling   = (int) get_option( 'folio_drawbridge_max_download_limit', 0 );
	$allow_no_expiry        = get_option( 'folio_drawbridge_allow_no_expiry', '1' ) === '1';
	$default_expiry_days    = (int) get_option( 'folio_drawbridge_default_expiry_days', 0 );
	$max_expiry_days        = (int) get_option( 'folio_drawbridge_max_expiry_days', 0 );

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
				<span class="folio-drawbridge-badge folio-drawbridge-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span>
			</h2>
			<p style="color:#888; font-size:13px; margin:0;">
				Created <?php echo esc_html( folio_drawbridge_format_date( $vault->created_at, 'M j, Y' ) ); ?>
				<?php if ( $vault->expires_at ) : ?>
					&bull; Expires <?php echo esc_html( folio_drawbridge_format_date( $vault->expires_at, 'M j, Y' ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $vault->description ) : ?>
				<p style="color:#555; font-size:13px; margin:4px 0 0;"><?php echo esc_html( $vault->description ); ?></p>
			<?php endif; ?>
		</div>
		<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start;">
			<!-- Edit name / description -->
			<button type="button" class="button" onclick="folioDrawbridgeUdToggle('folio-drawbridge-meta-form')">Edit Name &amp; Description</button>
			<!-- Edit expiry -->
			<button type="button" class="button" onclick="folioDrawbridgeUdToggle('folio-drawbridge-expiry-form')">Edit Expiry</button>
			<?php if ( $is_active ) : ?>
			<form method="post" action="<?php echo esc_url( $form_url ); ?>"
			      onsubmit="return confirm('Permanently delete vault &quot;<?php echo esc_js( $vault->name ); ?>&quot; and all its files?');">
				<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo (int) $vault_id; ?>">
				<input type="submit" name="folio_drawbridge_ud_delete_vault" value="Delete Vault" class="button folio-drawbridge-danger">
			</form>
			<?php endif; ?>
		</div>
	</div>

	<!-- Edit vault name / description inline form -->
	<div id="folio-drawbridge-meta-form" style="display:none; margin-top:12px;">
		<div class="folio-drawbridge-card" style="margin-top:0; padding:16px;">
			<form method="post" action="<?php echo esc_url( $form_url ); ?>">
				<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo (int) $vault_id; ?>">
				<div style="margin-bottom:12px;">
					<label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;" for="folio-drawbridge-vault-new-name">Vault Name <span style="color:#d63638;">*</span></label>
					<input type="text" id="folio-drawbridge-vault-new-name" name="vault_new_name"
					       value="<?php echo esc_attr( $vault->name ); ?>"
					       maxlength="255" style="width:100%; max-width:420px;" required>
				</div>
				<div style="margin-bottom:12px;">
					<label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;" for="folio-drawbridge-vault-new-desc">Description <span style="font-weight:400; color:#888;">(optional)</span></label>
					<textarea id="folio-drawbridge-vault-new-desc" name="vault_new_description"
					          rows="3" style="width:100%; max-width:420px;"><?php echo esc_textarea( $vault->description ); ?></textarea>
				</div>
				<input type="submit" name="folio_drawbridge_ud_edit_vault_meta" value="Save" class="button button-primary">
				<button type="button" class="button" style="margin-left:4px;" onclick="folioDrawbridgeUdToggle('folio-drawbridge-meta-form')">Cancel</button>
			</form>
		</div>
	</div>

	<!-- Edit vault expiry inline form -->
	<div id="folio-drawbridge-expiry-form" style="display:none; margin-top:12px;">
		<div class="folio-drawbridge-card" style="margin-top:0; padding:16px;">
			<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
				<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
				<input type="hidden" name="vault_id" value="<?php echo (int) $vault_id; ?>">
				<div class="folio-drawbridge-form-row" style="margin:0; flex:1; min-width:160px;">
					<label for="folio-drawbridge-vault-new-expires" style="margin-bottom:4px;">Expiry Date <span style="font-weight:400;color:#888;">(leave blank to remove)</span></label>
					<input type="date" id="folio-drawbridge-vault-new-expires" name="vault_new_expires"
					       value="<?php echo $vault->expires_at ? esc_attr( gmdate( 'Y-m-d', strtotime( $vault->expires_at ) ) ) : ''; ?>"
					       min="<?php echo esc_attr( $today ); ?>">
				</div>
				<div>
					<input type="submit" name="folio_drawbridge_ud_edit_vault_expiry" value="Save Expiry" class="button button-primary">
					<button type="button" class="button" style="margin-left:4px;" onclick="folioDrawbridgeUdToggle('folio-drawbridge-expiry-form')">Cancel</button>
				</div>
			</form>
		</div>
	</div>

	<!-- ── Files ──────────────────────────────────────────────────────────── -->
	<div class="folio-drawbridge-card">
		<h3 style="margin-top:0;">Files (<?php echo (int) count( $files ); ?>)</h3>

		<?php if ( $files ) : ?>
			<table id="folio-drawbridge-ud-files-<?php echo (int) $vault_id; ?>" class="folio-drawbridge-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Filename</th><th>Size</th><th>Uploaded</th><th data-nosort></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) : ?>
					<tr>
						<td><?php echo esc_html( $f->original_name ); ?></td>
						<td style="color:#888;"><?php echo esc_html( size_format( $f->file_size ) ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo esc_html( folio_drawbridge_format_date( $f->uploaded_at ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
							      onsubmit="return confirm('Delete <?php echo esc_js( $f->original_name ); ?>?');">
								<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
								<input type="hidden" name="vault_id" value="<?php echo (int) $vault_id; ?>">
								<input type="hidden" name="file_id"  value="<?php echo (int) $f->id; ?>">
								<input type="submit" name="folio_drawbridge_ud_delete_file" value="Delete" class="folio-drawbridge-btn folio-drawbridge-danger">
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
						Files <span style="font-weight:400; color:#888;">(max <?php echo (int) $max_file_mb; ?> MB each — hold Ctrl/Cmd to select multiple)</span>
					</label>
					<input type="file" id="folio-drawbridge-ud-file-input" multiple style="width:100%; padding:6px;">
				</div>
				<div>
					<button type="button" id="folio-drawbridge-ud-upload-btn" class="button button-primary" onclick="folioDrawbridgeUdUpload()">
						Encrypt &amp; Upload
					</button>
				</div>
			</div>
			<div id="folio-drawbridge-ud-file-queue" style="margin-top:10px;"></div>
			<p id="folio-drawbridge-ud-upload-error" style="display:none; color:#d63638; font-size:13px; margin:8px 0 0;"></p>
		</div>
		<script>
		var folioDrawbridgeUd = {
			ajaxUrl:  <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:    <?php echo wp_json_encode( wp_create_nonce( 'folio_drawbridge_user_nonce' ) ); ?>,
			vaultId:  <?php echo (int) $vault_id; ?>,
			chunkSize:<?php echo (int) folio_drawbridge_chunk_size_bytes(); ?>
		};
		function folioDrawbridgeUdGenId() {
			return Array.from(crypto.getRandomValues(new Uint8Array(16)))
				.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
		}
		function folioDrawbridgeUdToggle(id) {
			var el = document.getElementById(id);
			el.style.display = el.style.display === 'none' ? '' : 'none';
		}
		async function folioDrawbridgeUdUploadOne(file, rowEl) {
			var bar = rowEl.querySelector('.folio-drawbridge-ud-bar');
			var lbl = rowEl.querySelector('.folio-drawbridge-ud-lbl');
			var CHUNK = folioDrawbridgeUd.chunkSize;
			var total = Math.ceil(file.size / CHUNK) || 1;
			var uid   = folioDrawbridgeUdGenId();
			for (var i = 0; i < total; i++) {
				var start = i * CHUNK;
				var fd = new FormData();
				fd.append('action',       'folio_drawbridge_upload_chunk');
				fd.append('_wpnonce',     folioDrawbridgeUd.nonce);
				fd.append('vault_id',     folioDrawbridgeUd.vaultId);
				fd.append('upload_id',    uid);
				fd.append('chunk_index',  i);
				fd.append('total_chunks', total);
				fd.append('file_name',    file.name);
				fd.append('total_size',   file.size);
				fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
				var r = await fetch(folioDrawbridgeUd.ajaxUrl, {method:'POST', body:fd});
				var j = await r.json();
				if (!j.success) throw new Error(j.data || 'Upload failed.');
				var pct = Math.round((i + 1) / total * 100);
				bar.style.width = pct + '%';
				lbl.textContent = j.data.complete ? 'Done' : pct + '%';
			}
		}
		function folioDrawbridgeUdMakeRow(file) {
			var row = document.createElement('div');
			row.style.cssText = 'margin-bottom:6px;padding:8px 10px;background:#f6f7f7;border-radius:4px;font-size:12px;';
			row.innerHTML =
				'<div style="display:flex;justify-content:space-between;margin-bottom:4px;">'
				+ '<span style="font-weight:600;">' + folioDrawbridgeEsc(file.name) + '</span>'
				+ '<span class="folio-drawbridge-ud-lbl" style="color:#888;">Queued</span></div>'
				+ '<div style="background:#e0e0e0;border-radius:3px;height:8px;overflow:hidden;">'
				+ '<div class="folio-drawbridge-ud-bar" style="background:#2271b1;height:100%;width:0%;transition:width .2s;"></div></div>';
			return row;
		}
		function folioDrawbridgeEsc(s) {
			return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
		}
		async function folioDrawbridgeUdUpload() {
			var input  = document.getElementById('folio-drawbridge-ud-file-input');
			var errEl  = document.getElementById('folio-drawbridge-ud-upload-error');
			var queueEl = document.getElementById('folio-drawbridge-ud-file-queue');
			errEl.style.display = 'none';
			if (!input.files.length) {
				errEl.textContent = 'Please select at least one file.';
				errEl.style.display = '';
				return;
			}
			var btn   = document.getElementById('folio-drawbridge-ud-upload-btn');
			btn.disabled = true;
			queueEl.innerHTML = '';
			var files = Array.from(input.files);
			var rows  = files.map(function(f) {
				var row = folioDrawbridgeUdMakeRow(f);
				queueEl.appendChild(row);
				return row;
			});
			var hasError = false;
			for (var i = 0; i < files.length; i++) {
				var lbl = rows[i].querySelector('.folio-drawbridge-ud-lbl');
				lbl.textContent = 'Uploading…';
				try {
					await folioDrawbridgeUdUploadOne(files[i], rows[i]);
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
	<div class="folio-drawbridge-card">
		<h3 style="margin-top:0;">Shares (<?php echo (int) count( $shares ); ?>)</h3>

		<?php if ( $shares ) : ?>
			<table id="folio-drawbridge-ud-shares-<?php echo (int) $vault_id; ?>" class="folio-drawbridge-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Recipient</th><th>Status</th><th>Downloads</th><th>Expires</th><th>Last Access</th><th data-nosort></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $shares as $s ) :
					$dl_info    = $s->max_downloads > 0
						? (int) $s->download_count . ' / ' . (int) $s->max_downloads
						: (int) $s->download_count . ' / ∞';
					$editable   = in_array( $s->status, [ 'pending', 'active' ], true );
					$state      = folio_drawbridge_share_display_state( $s );
					$usable     = folio_drawbridge_share_is_accessible( $s );
					$edit_id    = 'folio-drawbridge-share-edit-' . (int) $s->id;
					$cur_expiry = $s->expires_at ? gmdate( 'Y-m-d', strtotime( $s->expires_at ) ) : '';
				?>
					<tr>
						<td><?php echo esc_html( $s->recipient_email ); ?></td>
						<td><span class="folio-drawbridge-badge folio-drawbridge-badge-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( folio_drawbridge_share_state_label( $state ) ); ?></span></td>
						<td style="font-size:13px;"><?php echo esc_html( $dl_info ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->expires_at ? esc_html( folio_drawbridge_format_date( $s->expires_at, 'M j, Y' ) ) : '—'; ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->last_accessed ? esc_html( folio_drawbridge_format_date( $s->last_accessed ) ) : 'Never'; ?></td>
						<td style="white-space:nowrap;">
							<?php if ( $editable ) : ?>
								<button type="button" class="folio-drawbridge-btn" onclick="folioDrawbridgeUdToggle('<?php echo esc_js( $edit_id ); ?>')" style="margin-right:4px;">Edit</button>
								<?php if ( $usable ) : ?>
									<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;margin-right:4px;">
										<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
										<input type="hidden" name="vault_id"  value="<?php echo (int) $vault_id; ?>">
										<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
										<input type="submit" name="folio_drawbridge_ud_resend_share" value="Resend"
										       class="folio-drawbridge-btn" title="Resend invite to <?php echo esc_attr( $s->recipient_email ); ?>">
									</form>
								<?php else : ?>
									<button type="button" class="folio-drawbridge-btn" disabled style="margin-right:4px;opacity:.5;cursor:not-allowed;"
									        title="<?php echo esc_attr( 'limit_reached' === $state
										        ? 'Download limit reached — resending the invite will not restore access. Raise the limit with Edit first.'
										        : 'This share has expired — resending the invite will not restore access. Extend the expiry with Edit first.' ); ?>">Resend</button>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
								      onsubmit="return confirm('Revoke access for <?php echo esc_js( $s->recipient_email ); ?>?');">
									<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
									<input type="hidden" name="vault_id"  value="<?php echo (int) $vault_id; ?>">
									<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
									<input type="submit" name="folio_drawbridge_ud_revoke_share" value="Revoke" class="folio-drawbridge-btn folio-drawbridge-danger">
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
								<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
								<input type="hidden" name="vault_id"  value="<?php echo (int) $vault_id; ?>">
								<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
								<div class="folio-drawbridge-form-row" style="margin:0; min-width:120px;">
									<label style="font-size:12px;">
										Download Limit
										<?php if ( $is_admin_user || $allow_unlimited_dl ) : ?>
											<span style="font-weight:400;color:#888;">(0 = ∞)</span>
										<?php endif; ?>
									</label>
									<input type="number" name="share_max_downloads"
									       value="<?php echo (int) $s->max_downloads; ?>"
									       min="<?php echo (int) $share_dl_min; ?>"
									       <?php if ( $share_dl_max_attr !== '' ) : ?>max="<?php echo (int) $share_dl_max_attr; ?>"<?php endif; ?>
									       style="width:90px; padding:5px 8px; border:1px solid #d0d5dd; border-radius:4px; font-size:13px;">
								</div>
								<div class="folio-drawbridge-form-row" style="margin:0; min-width:160px;">
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
									<input type="submit" name="folio_drawbridge_ud_edit_share" value="Save" class="button button-primary">
									<button type="button" class="button" style="margin-left:4px;" onclick="folioDrawbridgeUdToggle('<?php echo esc_js( $edit_id ); ?>')">Cancel</button>
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
			<?php wp_nonce_field( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' ); ?>
			<input type="hidden" name="vault_id" value="<?php echo (int) $vault_id; ?>">
			<div style="display:flex; gap:16px; flex-wrap:wrap;">
				<div style="flex:2; min-width:200px;">
					<div class="folio-drawbridge-form-row">
						<label for="folio-drawbridge-share-email">Recipient Email <span style="color:#d63638;">*</span></label>
						<input type="email" id="folio-drawbridge-share-email" name="share_email" placeholder="recipient@example.com" required>
					</div>
				</div>
				<div style="flex:1; min-width:130px;">
					<div class="folio-drawbridge-form-row">
						<label for="folio-drawbridge-share-maxdl">
							Download Limit
							<?php if ( $is_admin_user || $allow_unlimited_dl ) : ?>
								<span style="font-weight:400;color:#888;">(0 = ∞)</span>
							<?php endif; ?>
						</label>
						<input type="number" id="folio-drawbridge-share-maxdl" name="share_max_downloads"
						       value="<?php echo (int) $share_default_dl; ?>"
						       min="<?php echo (int) $share_dl_min; ?>"
						       <?php if ( $share_dl_max_attr !== '' ) : ?>max="<?php echo (int) $share_dl_max_attr; ?>"<?php endif; ?>>
					</div>
				</div>
				<div style="flex:1; min-width:160px;">
					<div class="folio-drawbridge-form-row">
						<label for="folio-drawbridge-share-expires">
							Link Expires
							<?php if ( ! $share_expiry_required ) : ?>
								<span style="font-weight:400;color:#888;">(optional)</span>
							<?php else : ?>
								<span style="color:#d63638;">*</span>
							<?php endif; ?>
						</label>
						<input type="date" id="folio-drawbridge-share-expires" name="share_expires"
						       value="<?php echo esc_attr( $share_expiry_default ); ?>"
						       min="<?php echo esc_attr( $today ); ?>"
						       <?php if ( $share_expiry_max_attr !== '' ) : ?>max="<?php echo esc_attr( $share_expiry_max_attr ); ?>"<?php endif; ?>
						       <?php if ( $share_expiry_required ) : ?>required<?php endif; ?>>
					</div>
				</div>
			</div>
			<div class="folio-drawbridge-form-actions">
				<input type="submit" name="folio_drawbridge_ud_create_share" value="Send Invite" class="button button-primary">
			</div>
		</form>
		<?php endif; ?>
	</div>

	<!-- ── Vault Audit Log ────────────────────────────────────────────────── -->
	<div class="folio-drawbridge-card">
		<h3 style="margin-top:0;">Activity Log <span style="font-size:13px; font-weight:400; color:#888;">(last 20 events)</span></h3>
		<?php if ( ! $audit ) : ?>
			<p style="color:#888; font-size:13px;">No activity recorded for this vault yet.</p>
		<?php else : ?>
			<table id="folio-drawbridge-ud-audit-<?php echo (int) $vault_id; ?>" class="folio-drawbridge-table widefat striped">
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
						<td><strong><?php echo esc_html( folio_drawbridge_audit_event_label( $row->event_type ) ); ?></strong></td>
						<td style="font-size:12px; color:#666; max-width:280px; word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
						<td style="font-size:11px; color:#aaa;"><?php echo esc_html( $row->ip_address ); ?></td>
						<td style="color:#888; white-space:nowrap; font-size:12px;"><?php echo esc_html( folio_drawbridge_format_date( $row->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		folioDrawbridgeSortTable('folio-drawbridge-ud-files-<?php echo (int) $vault_id; ?>');
		folioDrawbridgeSortTable('folio-drawbridge-ud-shares-<?php echo (int) $vault_id; ?>');
		folioDrawbridgeSortTable('folio-drawbridge-ud-audit-<?php echo (int) $vault_id; ?>');
	});
	</script>
	<?php
}
