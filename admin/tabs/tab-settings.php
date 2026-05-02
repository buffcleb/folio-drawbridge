<?php
/**
 * Settings tab — plugin configuration.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_tab_settings(): void {
	$otp_ttl              = (int) get_option( 'sft_otp_ttl_minutes', 15 );
	$otp_max_attempts     = (int) get_option( 'sft_otp_max_attempts', 5 );
	$otp_cooldown         = (int) get_option( 'sft_otp_cooldown_seconds', 60 );
	$max_file_mb          = (int) get_option( 'sft_max_file_mb', 50 );
	$prune_enabled        = get_option( 'sft_audit_prune_enabled', '0' );
	$prune_days           = (int) get_option( 'sft_audit_prune_days', 365 );
	$delete_on_uninst     = get_option( 'sft_delete_on_uninstall', '0' );

	// Notifications.
	$notify_on_download   = get_option( 'sft_notify_on_download', '0' );
	$expiry_warning_days  = (int) get_option( 'sft_expiry_warning_days', 0 );

	// File type restrictions.
	$allowed_extensions   = (string) get_option( 'sft_allowed_file_extensions', '' );

	// Storage quota.
	$storage_quota_mb     = (int) get_option( 'sft_storage_quota_mb', 0 );

	// Email templates.
	$email_templates = [];
	foreach ( [ 'invite', 'otp', 'download_notification', 'expiry_warning' ] as $type ) {
		$email_templates[ $type ] = [
			'subject' => (string) get_option( "sft_email_{$type}_subject", '' ),
			'body'    => (string) get_option( "sft_email_{$type}_body", '' ),
		];
		$defaults = sft_get_email_template( $type );
		$email_templates[ $type ]['subject_placeholder'] = $defaults['subject'];
		$email_templates[ $type ]['body_placeholder']    = $defaults['body'];
	}

	// Download limit settings.
	$allow_unlimited_dl   = get_option( 'sft_allow_unlimited_downloads', '1' );
	$default_max_dl       = (int) get_option( 'sft_default_max_downloads', 0 );
	$max_dl_ceiling       = (int) get_option( 'sft_max_download_limit', 0 );

	// Expiration settings.
	$allow_no_expiry      = get_option( 'sft_allow_no_expiry', '1' );
	$default_expiry_days  = (int) get_option( 'sft_default_expiry_days', 0 );
	$max_expiry_days      = (int) get_option( 'sft_max_expiry_days', 0 );

	// SIEM logging settings.
	$siem_enabled  = get_option( 'sft_siem_enabled', '0' );
	$siem_log_path = (string) get_option( 'sft_siem_log_path', '' );
	$siem_format   = get_option( 'sft_siem_format', 'json' );

	$form_url = add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'settings' ], admin_url( 'admin.php' ) );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>

	<form method="post" action="<?php echo esc_url( $form_url ); ?>">
		<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>

		<!-- ── Two-Factor Verification ──────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Two-Factor Verification</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="sft_otp_ttl_minutes">OTP Validity (minutes)</label></th>
					<td>
						<input type="number" id="sft_otp_ttl_minutes" name="sft_otp_ttl_minutes"
						       value="<?php echo $otp_ttl; ?>" min="5" max="60" style="width:80px;">
						<p class="description">How long a verification code remains valid. Minimum 5, maximum 60 minutes.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_otp_max_attempts">Max Verification Attempts</label></th>
					<td>
						<input type="number" id="sft_otp_max_attempts" name="sft_otp_max_attempts"
						       value="<?php echo $otp_max_attempts; ?>" min="1" max="10" style="width:80px;">
						<p class="description">Number of incorrect OTP attempts allowed before the code is invalidated and a new one must be requested. Minimum 1, maximum 10.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_otp_cooldown_seconds">OTP Rate Limit (seconds)</label></th>
					<td>
						<input type="number" id="sft_otp_cooldown_seconds" name="sft_otp_cooldown_seconds"
						       value="<?php echo $otp_cooldown; ?>" min="0" max="300" style="width:80px;">
						<p class="description">Minimum seconds a recipient must wait before requesting a new code. 0 = no limit. Range 0–300.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Download Limits ──────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Download Limits</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Allow Unlimited Downloads</th>
					<td>
						<label>
							<input type="checkbox" name="sft_allow_unlimited_downloads" value="1"
							       id="sft_allow_unlimited_dl" <?php checked( $allow_unlimited_dl, '1' ); ?>>
							Permit shares with no download limit
						</label>
						<p class="description">When unchecked, a download limit must be set on every share. Admins are exempt.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_default_max_downloads">Default Download Limit</label></th>
					<td>
						<input type="number" id="sft_default_max_downloads" name="sft_default_max_downloads"
						       value="<?php echo $default_max_dl; ?>" min="0" style="width:80px;">
						<p class="description">Pre-filled value in the share creation form. 0 = unlimited (only valid when unlimited is allowed).</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_max_download_limit">Maximum Download Limit</label></th>
					<td>
						<input type="number" id="sft_max_download_limit" name="sft_max_download_limit"
						       value="<?php echo $max_dl_ceiling; ?>" min="0" style="width:80px;">
						<p class="description">Hard ceiling users cannot exceed when setting a limit. 0 = no ceiling. Admins are exempt.</p>
					</td>
				</tr>
			</table>
			<div id="sft-dl-enforce-wrap" style="display:none;margin-top:16px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
				<label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;">
					<input type="checkbox" name="sft_apply_to_existing_dl" value="1" style="margin-top:2px;flex-shrink:0;">
					<span>
						<strong>Apply to existing shares</strong> — retroactively enforce the new download limits on all active and pending shares that currently exceed them.
						Shares already within the limits and administrator shares are not changed.
					</span>
				</label>
			</div>
		</div>

		<!-- ── Link Expiration ──────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Link Expiration</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Allow No Expiry</th>
					<td>
						<label>
							<input type="checkbox" name="sft_allow_no_expiry" value="1"
							       id="sft_allow_no_expiry" <?php checked( $allow_no_expiry, '1' ); ?>>
							Permit shares with no expiration date
						</label>
						<p class="description">When unchecked, every share must have an expiration date. Admins are exempt.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_default_expiry_days">Default Expiry (days from today)</label></th>
					<td>
						<input type="number" id="sft_default_expiry_days" name="sft_default_expiry_days"
						       value="<?php echo $default_expiry_days; ?>" min="0" style="width:80px;">
						<p class="description">Pre-filled expiry in the share creation form, as days from today. 0 = no pre-fill.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_max_expiry_days">Maximum Expiry (days from today)</label></th>
					<td>
						<input type="number" id="sft_max_expiry_days" name="sft_max_expiry_days"
						       value="<?php echo $max_expiry_days; ?>" min="0" style="width:80px;">
						<p class="description">Furthest-out expiration date a user can set, as days from today. 0 = no ceiling. Admins are exempt.</p>
					</td>
				</tr>
			</table>
			<div id="sft-expiry-enforce-wrap" style="display:none;margin-top:16px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
				<label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;">
					<input type="checkbox" name="sft_apply_to_existing_expiry" value="1" style="margin-top:2px;flex-shrink:0;">
					<span>
						<strong>Apply to existing shares</strong> — retroactively enforce the new expiration limits on all active and pending shares that currently exceed them.
						Shares already within the limits and administrator shares are not changed.
					</span>
				</label>
			</div>
		</div>

		<!-- ── File Uploads ─────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">File Uploads</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="sft_max_file_mb">Maximum File Size (MB)</label></th>
					<td>
						<input type="number" id="sft_max_file_mb" name="sft_max_file_mb"
						       value="<?php echo $max_file_mb; ?>" min="1" style="width:80px;">
						<p class="description">
							Files are uploaded in chunks and reassembled server-side, so this limit can safely
							<strong>exceed</strong> your server's <code>upload_max_filesize</code> and <code>post_max_size</code> PHP settings.
							Each chunk is sized to fit within those server limits automatically.
						</p>
						<p class="description" style="margin-top:6px;">
							Current server limits (for reference only):
							<code>upload_max_filesize</code> = <strong><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></strong>,
							<code>post_max_size</code> = <strong><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></strong>.
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Audit Log Retention ──────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Audit Log Retention</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Auto-Prune</th>
					<td>
						<label>
							<input type="checkbox" name="sft_audit_prune_enabled" value="1" <?php checked( $prune_enabled, '1' ); ?>>
							Automatically delete old audit entries (runs hourly via WP-Cron)
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="sft_audit_prune_days">Retention Window (days)</label></th>
					<td>
						<input type="number" id="sft_audit_prune_days" name="sft_audit_prune_days"
						       value="<?php echo $prune_days; ?>" min="30" style="width:80px;">
						<p class="description">Entries older than this are deleted when auto-prune runs. Minimum 30 days.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Encryption Key ───────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Encryption Key</h2>

			<?php if ( defined( 'SFT_MASTER_KEY' ) ) : ?>
				<div style="background:#d1e7dd;border-left:4px solid #0a3622;padding:12px 16px;font-size:13px;border-radius:4px;margin-bottom:16px;">
					✓ Master key is loaded from <code>SFT_MASTER_KEY</code> in wp-config.php.
				</div>
			<?php else : ?>
				<div style="background:#fff8e5;border-left:4px solid #ffb900;padding:12px 16px;font-size:13px;border-radius:4px;margin-bottom:16px;">
					<strong>Recommendation:</strong> Your master encryption key is currently stored in the database.
					For stronger security, move it to <code>wp-config.php</code> using the generator below.
				</div>
			<?php endif; ?>

			<div style="background:#fef0f0;border:2px solid #d63638;padding:14px 16px;border-radius:4px;font-size:13px;margin-bottom:16px;">
				<strong style="color:#d63638;">⚠ Warning:</strong> Replacing the master key will permanently break
				decryption of all existing encrypted files and shares. There is no recovery path.
				Only generate a new key on a fresh installation with no uploaded files.
			</div>

			<button type="button" class="button" onclick="sftOpenKeyModal()">Generate New Key for wp-config.php</button>
		</div>

		<!-- ── SIEM Logging ─────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">SIEM Logging</h2>
			<p style="font-size:13px;color:#555;margin-top:-6px;margin-bottom:16px;">
				Log all audit events to an OS file for ingestion by external SIEM tools (Splunk, Datadog, ELK, etc.).
			</p>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Enable SIEM Log</th>
					<td>
						<label>
							<input type="checkbox" name="sft_siem_enabled" value="1" <?php checked( $siem_enabled, '1' ); ?>>
							Write every audit event to the log file below
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="sft_siem_log_path">Log File Path</label></th>
					<td>
						<input type="text" id="sft_siem_log_path" name="sft_siem_log_path"
						       value="<?php echo esc_attr( $siem_log_path ); ?>"
						       style="width:100%;max-width:520px;" placeholder="/var/log/sft-audit.json">
						<p class="description">
							Absolute path to the log file on the server. The web server process must have write permission.
							Example: <code>/var/log/sft-audit.log</code>
						</p>
					</td>
				</tr>
				<tr>
					<th>Log Format</th>
					<td>
						<label style="margin-right:20px;">
							<input type="radio" name="sft_siem_format" value="json" <?php checked( $siem_format, 'json' ); ?>>
							JSON <span style="font-size:12px;color:#888;">(one object per line — NDJSON)</span>
						</label>
						<label>
							<input type="radio" name="sft_siem_format" value="csv" <?php checked( $siem_format, 'csv' ); ?>>
							CSV <span style="font-size:12px;color:#888;">(header row written once on first create)</span>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Notifications ────────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Notifications</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Download Notification</th>
					<td>
						<label>
							<input type="checkbox" name="sft_notify_on_download" value="1" <?php checked( $notify_on_download, '1' ); ?>>
							Email vault owner each time a recipient downloads a file
						</label>
						<p class="description">One email per file download via the recipient share flow. Admin inspector downloads are not included.</p>
					</td>
				</tr>
				<tr>
					<th><label for="sft_expiry_warning_days">Share Expiry Warning (days)</label></th>
					<td>
						<input type="number" id="sft_expiry_warning_days" name="sft_expiry_warning_days"
						       value="<?php echo $expiry_warning_days; ?>" min="0" style="width:80px;">
						<p class="description">Email vault owners this many days before a share link expires. 0 = disabled. Warning is sent once per share via hourly WP-Cron.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── File Type Restrictions ───────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">File Type Restrictions</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="sft_allowed_file_extensions">Allowed Extensions</label></th>
					<td>
						<input type="text" id="sft_allowed_file_extensions" name="sft_allowed_file_extensions"
						       value="<?php echo esc_attr( $allowed_extensions ); ?>"
						       style="width:100%;max-width:400px;" placeholder="pdf, docx, xlsx, jpg, png">
						<p class="description">Comma-separated list of permitted file extensions (e.g. <code>pdf, docx, jpg</code>). Leave blank to allow all file types. Case-insensitive.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Storage Quotas ───────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Storage Quotas</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="sft_storage_quota_mb">Per-User Quota (MB)</label></th>
					<td>
						<input type="number" id="sft_storage_quota_mb" name="sft_storage_quota_mb"
						       value="<?php echo $storage_quota_mb; ?>" min="0" style="width:80px;">
						<p class="description">Maximum total encrypted storage per vault user across all their vaults. 0 = no limit. WordPress and SFT admins are exempt.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Email Templates ──────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Email Templates</h2>
			<p style="font-size:13px;color:#555;margin-top:-6px;margin-bottom:16px;">
				Customize subject and body for each system email. Leave blank to use the built-in default. Use <code>{placeholder}</code> tokens — available tokens are listed below each body field.
			</p>
			<?php
			$tmpl_labels = [
				'invite'                => 'Share Invite',
				'otp'                   => 'OTP Verification Code',
				'download_notification' => 'Download Notification',
				'expiry_warning'        => 'Share Expiry Warning',
			];
			$tmpl_hints = [
				'invite'                => '{site_name}, {vault_name}, {owner_name}, {recipient_email}, {share_url}, {expires_note}',
				'otp'                   => '{site_name}, {otp_code}, {otp_ttl}',
				'download_notification' => '{site_name}, {vault_name}, {owner_name}, {recipient_email}, {file_name}, {download_count}, {recipient_ip}',
				'expiry_warning'        => '{site_name}, {vault_name}, {owner_name}, {recipient_email}, {expiry_date}, {days_until_expiry}',
			];
			foreach ( $email_templates as $type => $tmpl ) : ?>
			<div style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid #f0f2f5;">
				<h3 style="font-size:14px;margin:0 0 10px;"><?php echo esc_html( $tmpl_labels[ $type ] ); ?></h3>
				<table class="form-table" style="margin-top:0;">
					<tr>
						<th><label for="sft_email_<?php echo esc_attr( $type ); ?>_subject">Subject</label></th>
						<td>
							<input type="text" id="sft_email_<?php echo esc_attr( $type ); ?>_subject"
							       name="sft_email_<?php echo esc_attr( $type ); ?>_subject"
							       value="<?php echo esc_attr( $tmpl['subject'] ); ?>"
							       placeholder="<?php echo esc_attr( $tmpl['subject_placeholder'] ); ?>"
							       style="width:100%;max-width:520px;">
						</td>
					</tr>
					<tr>
						<th><label for="sft_email_<?php echo esc_attr( $type ); ?>_body">Body</label></th>
						<td>
							<textarea id="sft_email_<?php echo esc_attr( $type ); ?>_body"
							          name="sft_email_<?php echo esc_attr( $type ); ?>_body"
							          rows="6" style="width:100%;max-width:520px;font-family:monospace;font-size:12px;"
							          placeholder="<?php echo esc_attr( $tmpl['body_placeholder'] ); ?>"><?php echo esc_textarea( $tmpl['body'] ); ?></textarea>
							<p class="description">Placeholders: <code><?php echo esc_html( $tmpl_hints[ $type ] ); ?></code></p>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- ── Data & Privacy ───────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Data &amp; Privacy</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>On Uninstall</th>
					<td>
						<label>
							<input type="checkbox" name="sft_delete_on_uninstall" value="1" <?php checked( $delete_on_uninst, '1' ); ?>>
							Delete all plugin data when the plugin is uninstalled
						</label>
						<p class="description" style="color:#d63638;">
							<strong>Warning:</strong> All encrypted files and audit records are permanently deleted on uninstall.
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Storage ──────────────────────────────────────────────────────── -->
		<div class="sft-card">
			<h2 style="margin-top:0;">Storage</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Encrypted file storage</th>
					<td>
						<code><?php echo esc_html( SFT_VAULT_DIR ); ?></code>
						<?php
						$htaccess = SFT_VAULT_DIR . '.htaccess';
						if ( file_exists( $htaccess ) ) {
							echo '<span style="color:#0a3622;background:#d1e7dd;padding:2px 8px;border-radius:10px;font-size:11px;margin-left:8px;">✓ .htaccess protected</span>';
						} else {
							echo '<span style="color:#58151c;background:#f8d7da;padding:2px 8px;border-radius:10px;font-size:11px;margin-left:8px;">⚠ .htaccess missing</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th>Directory writable</th>
					<td>
						<?php if ( is_writable( SFT_VAULT_DIR ) ) : ?>
							<span style="color:#0a3622;">✓ Writable</span>
						<?php else : ?>
							<span style="color:#d63638;">✗ Not writable — uploads will fail.</span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( 'Save Settings', 'primary', 'sft_save_settings' ); ?>
	</form>

	<!-- ── Key generator modal ─────────────────────────────────────────────── -->
	<div id="sft-key-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99998;align-items:center;justify-content:center;">
		<div style="background:#fff;border-radius:8px;padding:28px;width:100%;max-width:560px;box-shadow:0 8px 32px rgba(0,0,0,.2);position:relative;z-index:99999;">
			<h2 style="margin:0 0 16px;font-size:18px;">Generate Encryption Key</h2>

			<div style="background:#fef0f0;border:2px solid #d63638;padding:12px 16px;border-radius:4px;font-size:13px;margin-bottom:16px;">
				<strong style="color:#d63638;">⚠ Read before proceeding:</strong> Replacing your master key will permanently
				break decryption of all existing encrypted files. Only use this on a fresh installation with no uploaded files.
			</div>

			<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:16px;cursor:pointer;">
				<input type="checkbox" id="sft-key-understand" onchange="sftToggleKeyReveal()">
				I understand that using this key to replace an existing key will break all encrypted files.
			</label>

			<div id="sft-key-reveal" style="display:none;">
				<p style="font-size:13px;margin:0 0 8px;font-weight:600;">Add this line to your <code>wp-config.php</code> before the "That's all" comment:</p>
				<div style="display:flex;gap:8px;align-items:flex-start;">
					<textarea id="sft-key-output" readonly rows="3"
					          style="flex:1;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:none;background:#f6f7f7;"></textarea>
				</div>
				<button type="button" class="button button-primary" onclick="sftCopyKey()" style="margin-top:10px;">Copy to Clipboard</button>
				<span id="sft-copy-confirm" style="display:none;color:#0a3622;font-size:13px;margin-left:10px;">✓ Copied!</span>
				<p style="font-size:12px;color:#888;margin:10px 0 0;">This key was generated server-side using cryptographically secure random bytes. It is not stored anywhere — copy it now.</p>
			</div>

			<div id="sft-key-loading" style="display:none;color:#888;font-size:13px;padding:10px 0;">Generating key…</div>

			<button type="button" class="button" onclick="sftCloseKeyModal()" style="margin-top:20px;">Close</button>
		</div>
	</div>

	<script>
	var sftKeyNonce = <?php echo wp_json_encode( wp_create_nonce( 'sft_generate_key_preview' ) ); ?>;
	var sftAjaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var sftGeneratedKey = '';

	function sftOpenKeyModal() {
		document.getElementById('sft-key-modal-overlay').style.display = 'flex';
		document.getElementById('sft-key-understand').checked = false;
		document.getElementById('sft-key-reveal').style.display = 'none';
		document.getElementById('sft-key-output').value = '';
		document.getElementById('sft-copy-confirm').style.display = 'none';
		sftGeneratedKey = '';
		sftFetchKey();
	}

	function sftFetchKey() {
		document.getElementById('sft-key-loading').style.display = 'block';
		var body = new URLSearchParams({ action: 'sft_generate_key_preview', _wpnonce: sftKeyNonce });
		fetch(sftAjaxUrl, { method: 'POST', body: body,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
		})
		.then(function(r){ return r.json(); })
		.then(function(r) {
			document.getElementById('sft-key-loading').style.display = 'none';
			if (r.success) {
				sftGeneratedKey = r.data.key;
				document.getElementById('sft-key-output').value = "define( 'SFT_MASTER_KEY', '" + r.data.key + "' );";
			}
		});
	}

	function sftToggleKeyReveal() {
		var checked = document.getElementById('sft-key-understand').checked;
		document.getElementById('sft-key-reveal').style.display = checked && sftGeneratedKey ? '' : 'none';
	}

	function sftCopyKey() {
		var el = document.getElementById('sft-key-output');
		var key = el.value;
		var confirm = document.getElementById('sft-copy-confirm');
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(key).then(function() {
				confirm.style.display = 'inline';
				setTimeout(function(){ confirm.style.display = 'none'; }, 3000);
			});
		} else {
			// Fallback for older browsers.
			el.select();
			document.execCommand('copy');
			confirm.style.display = 'inline';
			setTimeout(function(){ confirm.style.display = 'none'; }, 3000);
		}
	}

	function sftCloseKeyModal() {
		document.getElementById('sft-key-modal-overlay').style.display = 'none';
	}

	// Close on overlay click.
	document.getElementById('sft-key-modal-overlay').addEventListener('click', function(e) {
		if (e.target === this) sftCloseKeyModal();
	});

	// ── Show "apply to existing" checkboxes when settings change ────────────
	(function() {
		var dlFields     = ['sft_allow_unlimited_downloads', 'sft_default_max_downloads', 'sft_max_download_limit'];
		var expiryFields = ['sft_allow_no_expiry', 'sft_default_expiry_days', 'sft_max_expiry_days'];

		function getVal(name) {
			var el = document.querySelector('[name="' + name + '"]');
			if (!el) return null;
			return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
		}

		var dlOrig = {}, expiryOrig = {};
		dlFields.forEach(function(n)     { dlOrig[n]     = getVal(n); });
		expiryFields.forEach(function(n) { expiryOrig[n] = getVal(n); });

		function checkDl() {
			var changed = dlFields.some(function(n) { return getVal(n) !== dlOrig[n]; });
			var wrap = document.getElementById('sft-dl-enforce-wrap');
			wrap.style.display = changed ? '' : 'none';
			if (!changed) wrap.querySelector('input[type=checkbox]').checked = false;
		}

		function checkExpiry() {
			var changed = expiryFields.some(function(n) { return getVal(n) !== expiryOrig[n]; });
			var wrap = document.getElementById('sft-expiry-enforce-wrap');
			wrap.style.display = changed ? '' : 'none';
			if (!changed) wrap.querySelector('input[type=checkbox]').checked = false;
		}

		dlFields.forEach(function(n) {
			var el = document.querySelector('[name="' + n + '"]');
			if (el) el.addEventListener('change', checkDl);
		});
		expiryFields.forEach(function(n) {
			var el = document.querySelector('[name="' + n + '"]');
			if (el) el.addEventListener('change', checkExpiry);
		});
	})();
	</script>
	<?php
}
