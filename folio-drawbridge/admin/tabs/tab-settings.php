<?php
/**
 * Settings tab — plugin configuration.
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function folio_drawbridge_render_tab_settings(): void {
	$otp_ttl              = (int) get_option( 'folio_drawbridge_otp_ttl_minutes', 15 );
	$otp_max_attempts     = (int) get_option( 'folio_drawbridge_otp_max_attempts', 5 );
	$otp_cooldown         = (int) get_option( 'folio_drawbridge_otp_cooldown_seconds', 60 );
	$max_file_mb          = (int) get_option( 'folio_drawbridge_max_file_mb', 50 );
	$prune_enabled        = get_option( 'folio_drawbridge_audit_prune_enabled', '0' );
	$prune_days           = (int) get_option( 'folio_drawbridge_audit_prune_days', 365 );
	$delete_on_uninst     = get_option( 'folio_drawbridge_delete_on_uninstall', '0' );

	// Notifications.
	$notify_on_download   = get_option( 'folio_drawbridge_notify_on_download', '0' );
	$expiry_warning_days  = (int) get_option( 'folio_drawbridge_expiry_warning_days', 0 );

	// File type restrictions.
	$allowed_extensions   = (string) get_option( 'folio_drawbridge_allowed_file_extensions', '' );

	// Storage quota.
	$storage_quota_mb     = (int) get_option( 'folio_drawbridge_storage_quota_mb', 0 );

	// Email templates.
	$email_templates = [];
	foreach ( [ 'invite', 'otp', 'download_notification', 'expiry_warning' ] as $type ) {
		$email_templates[ $type ] = [
			'subject' => (string) get_option( "folio_drawbridge_email_{$type}_subject", '' ),
			'body'    => (string) get_option( "folio_drawbridge_email_{$type}_body", '' ),
		];
		$defaults = folio_drawbridge_get_email_template( $type );
		$email_templates[ $type ]['subject_placeholder'] = $defaults['subject'];
		$email_templates[ $type ]['body_placeholder']    = $defaults['body'];
	}

	// Download limit settings.
	$allow_unlimited_dl   = get_option( 'folio_drawbridge_allow_unlimited_downloads', '1' );
	$default_max_dl       = (int) get_option( 'folio_drawbridge_default_max_downloads', 0 );
	$max_dl_ceiling       = (int) get_option( 'folio_drawbridge_max_download_limit', 0 );

	// Expiration settings.
	$allow_no_expiry      = get_option( 'folio_drawbridge_allow_no_expiry', '1' );
	$default_expiry_days  = (int) get_option( 'folio_drawbridge_default_expiry_days', 0 );
	$max_expiry_days      = (int) get_option( 'folio_drawbridge_max_expiry_days', 0 );

	// SIEM logging settings. A path stored before the validation rule existed can
	// still be sitting in the options table; the writer refuses it, so say so
	// here rather than leaving an administrator to wonder why the feed is empty.
	$siem_enabled      = get_option( 'folio_drawbridge_siem_enabled', '0' );
	$siem_log_file     = folio_drawbridge_siem_log_file();
	$siem_format       = get_option( 'folio_drawbridge_siem_format', 'json' );
	// Only a wp-config-defined path can be invalid; the default always resolves.
	$siem_constant_error = defined( 'FOLIO_DRAWBRIDGE_SIEM_PATH' )
		? folio_drawbridge_siem_path_error( (string) FOLIO_DRAWBRIDGE_SIEM_PATH )
		: '';

	$form_url = add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'settings' ], admin_url( 'admin.php' ) );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>

	<form method="post" action="<?php echo esc_url( $form_url ); ?>">
		<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>

		<!-- ── Two-Factor Verification ──────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Two-Factor Verification</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="folio_drawbridge_otp_ttl_minutes">OTP Validity (minutes)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_otp_ttl_minutes" name="folio_drawbridge_otp_ttl_minutes"
						       value="<?php echo (int) $otp_ttl; ?>" min="5" max="60" style="width:80px;">
						<p class="description">How long a verification code remains valid. Minimum 5, maximum 60 minutes.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_otp_max_attempts">Max Verification Attempts</label></th>
					<td>
						<input type="number" id="folio_drawbridge_otp_max_attempts" name="folio_drawbridge_otp_max_attempts"
						       value="<?php echo (int) $otp_max_attempts; ?>" min="1" max="10" style="width:80px;">
						<p class="description">Number of incorrect OTP attempts allowed before the code is invalidated and a new one must be requested. Minimum 1, maximum 10.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_otp_cooldown_seconds">OTP Rate Limit (seconds)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_otp_cooldown_seconds" name="folio_drawbridge_otp_cooldown_seconds"
						       value="<?php echo (int) $otp_cooldown; ?>" min="0" max="300" style="width:80px;">
						<p class="description">Minimum seconds a recipient must wait before requesting a new code. 0 = no limit. Range 0–300. A fixed ceiling of 10 codes per share per hour always applies, so brute-force protection remains even at 0.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Download Limits ──────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Download Limits</h2>
			<p style="font-size:13px;color:#555;margin-top:-6px;margin-bottom:16px;">
				One download is counted per successful recipient verification, not per file — after entering
				their code a recipient may retrieve every file in the vault, individually or as a ZIP, for the
				life of that session. A limit of 1 lets them collect the vault once.
			</p>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Allow Unlimited Downloads</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_allow_unlimited_downloads" value="1"
							       id="folio_drawbridge_allow_unlimited_dl" <?php checked( $allow_unlimited_dl, '1' ); ?>>
							Permit shares with no download limit
						</label>
						<p class="description">When unchecked, a download limit must be set on every share. Admins are exempt.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_default_max_downloads">Default Download Limit</label></th>
					<td>
						<input type="number" id="folio_drawbridge_default_max_downloads" name="folio_drawbridge_default_max_downloads"
						       value="<?php echo (int) $default_max_dl; ?>" min="0" style="width:80px;">
						<p class="description">Pre-filled value in the share creation form. 0 = unlimited (only valid when unlimited is allowed).</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_max_download_limit">Maximum Download Limit</label></th>
					<td>
						<input type="number" id="folio_drawbridge_max_download_limit" name="folio_drawbridge_max_download_limit"
						       value="<?php echo (int) $max_dl_ceiling; ?>" min="0" style="width:80px;">
						<p class="description">Hard ceiling users cannot exceed when setting a limit. 0 = no ceiling. Admins are exempt.</p>
					</td>
				</tr>
			</table>
			<div id="folio-drawbridge-dl-enforce-wrap" style="display:none;margin-top:16px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
				<label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;">
					<input type="checkbox" name="folio_drawbridge_apply_to_existing_dl" value="1" style="margin-top:2px;flex-shrink:0;">
					<span>
						<strong>Apply to existing shares</strong> — retroactively enforce the new download limits on all active and pending shares that currently exceed them.
						Shares already within the limits and administrator shares are not changed.
					</span>
				</label>
			</div>
		</div>

		<!-- ── Link Expiration ──────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Link Expiration</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Allow No Expiry</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_allow_no_expiry" value="1"
							       id="folio_drawbridge_allow_no_expiry" <?php checked( $allow_no_expiry, '1' ); ?>>
							Permit shares with no expiration date
						</label>
						<p class="description">When unchecked, every share must have an expiration date. Admins are exempt.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_default_expiry_days">Default Expiry (days from today)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_default_expiry_days" name="folio_drawbridge_default_expiry_days"
						       value="<?php echo (int) $default_expiry_days; ?>" min="0" style="width:80px;">
						<p class="description">Pre-filled expiry in the share creation form, as days from today. 0 = no pre-fill.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_max_expiry_days">Maximum Expiry (days from today)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_max_expiry_days" name="folio_drawbridge_max_expiry_days"
						       value="<?php echo (int) $max_expiry_days; ?>" min="0" style="width:80px;">
						<p class="description">Furthest-out expiration date a user can set, as days from today. 0 = no ceiling. Admins are exempt.</p>
					</td>
				</tr>
			</table>
			<div id="folio-drawbridge-expiry-enforce-wrap" style="display:none;margin-top:16px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
				<label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;">
					<input type="checkbox" name="folio_drawbridge_apply_to_existing_expiry" value="1" style="margin-top:2px;flex-shrink:0;">
					<span>
						<strong>Apply to existing shares</strong> — retroactively enforce the new expiration limits on all active and pending shares that currently exceed them.
						Shares already within the limits and administrator shares are not changed.
					</span>
				</label>
			</div>
		</div>

		<!-- ── File Uploads ─────────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">File Uploads</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="folio_drawbridge_max_file_mb">Maximum File Size (MB)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_max_file_mb" name="folio_drawbridge_max_file_mb"
						       value="<?php echo (int) $max_file_mb; ?>" min="1" style="width:80px;">
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
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Audit Log Retention</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Auto-Prune</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_audit_prune_enabled" value="1" <?php checked( $prune_enabled, '1' ); ?>>
							Automatically delete old audit entries (runs hourly via WP-Cron)
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_audit_prune_days">Retention Window (days)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_audit_prune_days" name="folio_drawbridge_audit_prune_days"
						       value="<?php echo (int) $prune_days; ?>" min="30" style="width:80px;">
						<p class="description">Entries older than this are deleted when auto-prune runs. Minimum 30 days.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Encryption Key ───────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Encryption Key</h2>

			<?php if ( defined( 'FOLIO_DRAWBRIDGE_MASTER_KEY' ) ) : ?>
				<div style="background:#d1e7dd;border-left:4px solid #0a3622;padding:12px 16px;font-size:13px;border-radius:4px;margin-bottom:16px;">
					✓ Master key is loaded from <code>FOLIO_DRAWBRIDGE_MASTER_KEY</code> in wp-config.php.
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

			<button type="button" class="button" onclick="folioDrawbridgeOpenKeyModal()">Generate New Key for wp-config.php</button>
		</div>

		<!-- ── SIEM Logging ─────────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">SIEM Logging</h2>
			<p style="font-size:13px;color:#555;margin-top:-6px;margin-bottom:16px;">
				Log all audit events to an OS file for ingestion by external SIEM tools (Splunk, Datadog, ELK, etc.).
			</p>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Enable SIEM Log</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_siem_enabled" value="1" <?php checked( $siem_enabled, '1' ); ?>>
							Write every audit event to the log file below
						</label>
					</td>
				</tr>
				<tr>
					<th>Log File</th>
					<td>
						<code><?php echo esc_html( $siem_log_file ); ?></code>
						<?php if ( $siem_constant_error !== '' ) : ?>
							<p style="margin:6px 0;color:#d63638;">
								<strong>Nothing is being written.</strong>
								<?php echo esc_html( $siem_constant_error ); ?>
							</p>
						<?php endif; ?>
						<p class="description">
							Written inside your uploads directory, protected from direct web access by the
							same rules as the encrypted vaults. Point your SIEM agent at this file.
						</p>
						<p class="description">
							To write somewhere else — a path your collector already tails, for instance —
							define <code>FOLIO_DRAWBRIDGE_SIEM_PATH</code> in <code>wp-config.php</code>.
							It must be an absolute path outside the WordPress directory with a
							non-executable extension.
						</p>
					</td>
				</tr>
				<tr>
					<th>Log Format</th>
					<td>
						<label style="margin-right:20px;">
							<input type="radio" name="folio_drawbridge_siem_format" value="json" <?php checked( $siem_format, 'json' ); ?>>
							JSON <span style="font-size:12px;color:#888;">(one object per line — NDJSON)</span>
						</label>
						<label>
							<input type="radio" name="folio_drawbridge_siem_format" value="csv" <?php checked( $siem_format, 'csv' ); ?>>
							CSV <span style="font-size:12px;color:#888;">(header row written once on first create)</span>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Notifications ────────────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Notifications</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>Download Notification</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_notify_on_download" value="1" <?php checked( $notify_on_download, '1' ); ?>>
							Email vault owner each time a recipient downloads a file
						</label>
						<p class="description">One email per file download via the recipient share flow. Admin inspector downloads are not included.</p>
					</td>
				</tr>
				<tr>
					<th><label for="folio_drawbridge_expiry_warning_days">Share Expiry Warning (days)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_expiry_warning_days" name="folio_drawbridge_expiry_warning_days"
						       value="<?php echo (int) $expiry_warning_days; ?>" min="0" style="width:80px;">
						<p class="description">Email vault owners this many days before a share link expires. 0 = disabled. Warning is sent once per share via hourly WP-Cron.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── File Type Restrictions ───────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">File Type Restrictions</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="folio_drawbridge_allowed_file_extensions">Allowed Extensions</label></th>
					<td>
						<input type="text" id="folio_drawbridge_allowed_file_extensions" name="folio_drawbridge_allowed_file_extensions"
						       value="<?php echo esc_attr( $allowed_extensions ); ?>"
						       style="width:100%;max-width:400px;" placeholder="pdf, docx, xlsx, jpg, png">
						<p class="description">Comma-separated list of permitted file extensions (e.g. <code>pdf, docx, jpg</code>). Leave blank to allow all file types. Case-insensitive.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Storage Quotas ───────────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Storage Quotas</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="folio_drawbridge_storage_quota_mb">Per-User Quota (MB)</label></th>
					<td>
						<input type="number" id="folio_drawbridge_storage_quota_mb" name="folio_drawbridge_storage_quota_mb"
						       value="<?php echo (int) $storage_quota_mb; ?>" min="0" style="width:80px;">
						<p class="description">Maximum total encrypted storage per vault user across all their vaults. 0 = no limit. WordPress and Drawbridge admins are exempt.</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ── Email Templates ──────────────────────────────────────────────────── -->
		<div class="folio-drawbridge-card">
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
						<th><label for="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_subject">Subject</label></th>
						<td>
							<input type="text" id="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_subject"
							       name="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_subject"
							       value="<?php echo esc_attr( $tmpl['subject'] ); ?>"
							       placeholder="<?php echo esc_attr( $tmpl['subject_placeholder'] ); ?>"
							       style="width:100%;max-width:520px;">
						</td>
					</tr>
					<tr>
						<th><label for="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_body">Body</label></th>
						<td>
							<textarea id="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_body"
							          name="folio_drawbridge_email_<?php echo esc_attr( $type ); ?>_body"
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
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Data &amp; Privacy</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th>On Uninstall</th>
					<td>
						<label>
							<input type="checkbox" name="folio_drawbridge_delete_on_uninstall" value="1" <?php checked( $delete_on_uninst, '1' ); ?>>
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
		<div class="folio-drawbridge-card">
			<h2 style="margin-top:0;">Storage</h2>
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="folio_drawbridge_storage_dir">Storage Folder</label></th>
					<td>
						<code><?php echo esc_html( trailingslashit( wp_get_upload_dir()['basedir'] ) ); ?></code>
						<input type="text" id="folio_drawbridge_storage_dir" name="folio_drawbridge_storage_dir"
						       value="<?php echo esc_attr( folio_drawbridge_storage_dir_name() ); ?>"
						       style="width:220px;" placeholder="folio-drawbridge">
						<p class="description">
							Folder inside your uploads directory holding all encrypted files, upload staging,
							and logs. Letters, numbers, and hyphens only.
						</p>
						<p class="description" style="color:#d63638;">
							<strong>Changing this does not move existing files.</strong> Vaults created under the
							previous folder become unreadable until their files are moved across by hand.
						</p>
					</td>
				</tr>
				<tr>
					<th>Encrypted file storage</th>
					<td>
						<code><?php echo esc_html( folio_drawbridge_vault_dir() ); ?></code>
						<?php
						$htaccess = folio_drawbridge_vault_dir() . '.htaccess';
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
						<?php if ( is_writable( folio_drawbridge_vault_dir() ) ) : // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- read-only status display of the storage directory. ?>
							<span style="color:#0a3622;">✓ Writable</span>
						<?php else : ?>
							<span style="color:#d63638;">✗ Not writable — uploads will fail.</span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( 'Save Settings', 'primary', 'folio_drawbridge_save_settings' ); ?>
	</form>

	<!-- ── Key generator modal ─────────────────────────────────────────────── -->
	<div id="folio-drawbridge-key-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99998;align-items:center;justify-content:center;">
		<div style="background:#fff;border-radius:8px;padding:28px;width:100%;max-width:560px;box-shadow:0 8px 32px rgba(0,0,0,.2);position:relative;z-index:99999;">
			<h2 style="margin:0 0 16px;font-size:18px;">Generate Encryption Key</h2>

			<div style="background:#fef0f0;border:2px solid #d63638;padding:12px 16px;border-radius:4px;font-size:13px;margin-bottom:16px;">
				<strong style="color:#d63638;">⚠ Read before proceeding:</strong> Replacing your master key will permanently
				break decryption of all existing encrypted files. Only use this on a fresh installation with no uploaded files.
			</div>

			<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:16px;cursor:pointer;">
				<input type="checkbox" id="folio-drawbridge-key-understand" onchange="folioDrawbridgeToggleKeyReveal()">
				I understand that using this key to replace an existing key will break all encrypted files.
			</label>

			<div id="folio-drawbridge-key-reveal" style="display:none;">
				<p style="font-size:13px;margin:0 0 8px;font-weight:600;">Add this line to your <code>wp-config.php</code> before the "That's all" comment:</p>
				<div style="display:flex;gap:8px;align-items:flex-start;">
					<textarea id="folio-drawbridge-key-output" readonly rows="3"
					          style="flex:1;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:none;background:#f6f7f7;"></textarea>
				</div>
				<button type="button" class="button button-primary" onclick="folioDrawbridgeCopyKey()" style="margin-top:10px;">Copy to Clipboard</button>
				<span id="folio-drawbridge-copy-confirm" style="display:none;color:#0a3622;font-size:13px;margin-left:10px;">✓ Copied!</span>
				<p style="font-size:12px;color:#888;margin:10px 0 0;">This key was generated server-side using cryptographically secure random bytes. It is not stored anywhere — copy it now.</p>
			</div>

			<div id="folio-drawbridge-key-loading" style="display:none;color:#888;font-size:13px;padding:10px 0;">Generating key…</div>

			<button type="button" class="button" onclick="folioDrawbridgeCloseKeyModal()" style="margin-top:20px;">Close</button>
		</div>
	</div>

	<?php
}
