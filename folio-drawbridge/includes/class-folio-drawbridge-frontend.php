<?php
/**
 * Front-end: public share access page, [folio_drawbridge_my_vaults] shortcode, and AJAX handlers.
 *
 * Public share flow (unauthenticated recipients):
 *   /?folio_drawbridge_share=TOKEN   →  email input  →  OTP email  →  OTP verify  →  download
 *   /?folio_drawbridge_download=FILE_ID&dt=DOWNLOAD_TOKEN  →  stream decrypted file
 *
 * Authenticated user shortcode:
 *   [folio_drawbridge_my_vaults]  —  lists the current user's vaults; allows vault creation,
 *                       file upload, share creation, and share revocation via AJAX.
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams encrypted files in fixed-size chunks and manages its own protected storage directory; WP_Filesystem cannot stream and buffers whole files in memory.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.

// ─── Capability helper ────────────────────────────────────────────────────────

/**
 * Returns true if the current user may use the vault features.
 * Drawbridge admins and WP admins are always allowed; others need the folio_drawbridge_use_vaults capability.
 */
function folio_drawbridge_user_can_use(): bool {
	return is_user_logged_in() &&
		( folio_drawbridge_is_admin() || current_user_can( 'folio_drawbridge_use_vaults' ) );
}

// ─── URL helper ───────────────────────────────────────────────────────────────

/**
 * Converts a WordPress URL into a root-relative one (path + query).
 *
 * Browsers resolve root-relative URLs against the scheme and host of the page
 * currently loaded. Emitting absolute URLs from home_url()/admin_url() breaks
 * whenever WordPress's stored scheme differs from how the visitor actually
 * reached the site — the normal situation behind a TLS-terminating proxy, load
 * balancer, or CDN, where is_ssl() reports false while the browser is on HTTPS.
 * The resulting http:// request from an https:// page is mixed content, which
 * browsers block: downloads fail and the browser falls back to naming files
 * after the URL path ("download", "admin-ajax") instead of honouring
 * Content-Disposition.
 *
 * Keeping the path intact (rather than hardcoding '/') preserves subdirectory
 * installations.
 *
 * @param string $url Absolute WordPress URL.
 * @return string Root-relative URL beginning with '/'.
 */
function folio_drawbridge_root_relative_url( string $url ): string {
	$parts = wp_parse_url( $url );
	$path  = $parts['path'] ?? '/';

	if ( ! empty( $parts['query'] ) ) {
		$path .= '?' . $parts['query'];
	}

	return strpos( $path, '/' ) === 0 ? $path : '/' . $path;
}

// ─── Query vars ───────────────────────────────────────────────────────────────

add_filter( 'query_vars', 'folio_drawbridge_register_query_vars' );

function folio_drawbridge_register_query_vars( array $vars ): array {
	$vars[] = 'folio_drawbridge_share';
	$vars[] = 'folio_drawbridge_download';
	return $vars;
}

// ─── Public page interception ─────────────────────────────────────────────────

add_action( 'template_redirect', 'folio_drawbridge_template_redirect' );

function folio_drawbridge_template_redirect(): void {
	$share_token = get_query_var( 'folio_drawbridge_share' );
	$file_id     = get_query_var( 'folio_drawbridge_download' );

	if ( $share_token ) {
		folio_drawbridge_render_share_page( sanitize_text_field( $share_token ) );
		exit;
	}

	if ( $file_id ) {
		folio_drawbridge_handle_file_download( (int) $file_id );
		exit;
	}
}

// ─── Public share access page ─────────────────────────────────────────────────

/**
 * Renders the full HTML page for recipient share access.
 * Handles: invalid token, expired/revoked share, email form, OTP form, and file list.
 */
function folio_drawbridge_render_share_page( string $token ): void {
	$share = folio_drawbridge_get_share_by_token( $token );

	$site_name = esc_html( get_bloginfo( 'name' ) );
	$home_url  = esc_url( home_url( '/' ) );

	folio_drawbridge_share_page_header( $site_name, $home_url );

	if ( ! $share ) {
		folio_drawbridge_share_page_error( 'Invalid link', 'This secure share link is not valid.' );
		folio_drawbridge_share_page_footer();
		return;
	}

	if ( ! folio_drawbridge_share_is_accessible( $share ) ) {
		$reason = $share->status === 'revoked' ? 'This share link has been revoked.' : 'This share link has expired or reached its download limit.';
		folio_drawbridge_share_page_error( 'Link unavailable', $reason );
		folio_drawbridge_share_page_footer();
		return;
	}

	$vault = folio_drawbridge_get_vault( (int) $share->vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		folio_drawbridge_share_page_error( 'Vault unavailable', 'The vault associated with this link is no longer available.' );
		folio_drawbridge_share_page_footer();
		return;
	}

	// Note: vault files are intentionally NOT loaded here. The manifest is
	// served by folio_drawbridge_verify_otp() only after successful OTP verification.
	//
	// URLs are root-relative so they inherit the scheme and host of the page the
	// recipient is actually on — see folio_drawbridge_root_relative_url().
	$ajax_url  = folio_drawbridge_root_relative_url( admin_url( 'admin-ajax.php' ) );
	$home_base = folio_drawbridge_root_relative_url( home_url( '/' ) );
	$nonce     = wp_create_nonce( 'folio_drawbridge_public_nonce' );
	$share_id  = (int) $share->id;
	?>
	<div class="folio-drawbridge-card">
		<h2><?php echo esc_html( $vault->name ); ?></h2>
		<?php if ( $vault->description ) : ?>
			<p class="folio-drawbridge-desc"><?php echo esc_html( $vault->description ); ?></p>
		<?php endif; ?>

		<!-- Step 1: Email verification -->
		<div id="folio-drawbridge-step-email" class="folio-drawbridge-step">
			<p>To access the files in this vault, enter the email address where you received this link. A verification code will be sent to confirm your identity.</p>
			<div id="folio-drawbridge-email-error" class="folio-drawbridge-alert folio-drawbridge-alert-error" style="display:none;"></div>
			<label for="folio-drawbridge-email">Your email address</label>
			<input type="email" id="folio-drawbridge-email" class="folio-drawbridge-input" placeholder="you@example.com" autocomplete="email">
			<button class="folio-drawbridge-btn folio-drawbridge-btn-primary" onclick="folioDrawbridgeRequestOtp()">Send Verification Code</button>
		</div>

		<!-- Step 2: OTP verification -->
		<div id="folio-drawbridge-step-otp" class="folio-drawbridge-step" style="display:none;">
			<p>A 6-digit verification code has been sent to your email address. Enter it below. The code is valid for <?php echo (int) get_option( 'folio_drawbridge_otp_ttl_minutes', 15 ); ?> minutes.</p>
			<div id="folio-drawbridge-otp-error" class="folio-drawbridge-alert folio-drawbridge-alert-error" style="display:none;"></div>
			<label for="folio-drawbridge-otp">Verification code</label>
			<input type="text" id="folio-drawbridge-otp" class="folio-drawbridge-input" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
			<button class="folio-drawbridge-btn folio-drawbridge-btn-primary" onclick="folioDrawbridgeVerifyOtp()">Verify Code</button>
			<button class="folio-drawbridge-btn folio-drawbridge-btn-secondary" onclick="folioDrawbridgeBackToEmail()" style="margin-top:8px;">← Change Email</button>
		</div>

		<!--
		Step 3: File list. Deliberately empty in the server response — file names
		and sizes are sensitive and must not reach anyone who merely holds the
		share link. The manifest arrives in the folio_drawbridge_verify_otp AJAX response,
		which only returns it once the one-time code has been verified.
		-->
		<div id="folio-drawbridge-step-files" class="folio-drawbridge-step" style="display:none;">
			<p class="folio-drawbridge-success-note">✓ Identity verified. You can now download the shared files.</p>
			<p class="folio-drawbridge-note" id="folio-drawbridge-dl-limit" style="display:none;"></p>
			<p id="folio-drawbridge-no-files" style="display:none;">This vault contains no files.</p>
			<ul class="folio-drawbridge-file-list" id="folio-drawbridge-file-list"></ul>
			<div id="folio-drawbridge-zip-wrap" style="margin-top:14px;display:none;">
				<a class="folio-drawbridge-btn folio-drawbridge-btn-secondary" href="#" onclick="folioDrawbridgeDownloadZip(); return false;">
					Download All as ZIP
				</a>
			</div>
		</div>
	</div>

	<script>
	var folioDrawbridgeData = {
		ajaxUrl:  <?php echo wp_json_encode( $ajax_url ); ?>,
		homeBase: <?php echo wp_json_encode( $home_base ); ?>,
		nonce:    <?php echo wp_json_encode( $nonce ); ?>,
		shareId:  <?php echo (int) $share_id; ?>,
		dlToken:  null
	};

	function folioDrawbridgeRequestOtp() {
		var email = document.getElementById('folio-drawbridge-email').value.trim();
		if (!email) { folioDrawbridgeShowError('folio-drawbridge-email-error', 'Please enter your email address.'); return; }
		folioDrawbridgeHideError('folio-drawbridge-email-error');
		folioDrawbridgePost({ action: 'folio_drawbridge_request_otp', share_id: folioDrawbridgeData.shareId, email: email, _wpnonce: folioDrawbridgeData.nonce })
			.then(function(r) {
				if (r.success) {
					document.getElementById('folio-drawbridge-step-email').style.display = 'none';
					document.getElementById('folio-drawbridge-step-otp').style.display   = '';
				} else {
					folioDrawbridgeShowError('folio-drawbridge-email-error', r.data || 'An error occurred.');
				}
			});
	}

	function folioDrawbridgeVerifyOtp() {
		var email = document.getElementById('folio-drawbridge-email').value.trim();
		var otp   = document.getElementById('folio-drawbridge-otp').value.trim();
		if (!otp)  { folioDrawbridgeShowError('folio-drawbridge-otp-error', 'Please enter the verification code.'); return; }
		folioDrawbridgeHideError('folio-drawbridge-otp-error');
		folioDrawbridgePost({ action: 'folio_drawbridge_verify_otp', share_id: folioDrawbridgeData.shareId, email: email, otp: otp, _wpnonce: folioDrawbridgeData.nonce })
			.then(function(r) {
				if (r.success) {
					folioDrawbridgeData.dlToken = r.data.download_token;
					folioDrawbridgeRenderFiles(r.data);
					document.getElementById('folio-drawbridge-step-otp').style.display   = 'none';
					document.getElementById('folio-drawbridge-step-files').style.display  = '';
				} else {
					folioDrawbridgeShowError('folio-drawbridge-otp-error', r.data || 'Verification failed.');
				}
			});
	}

	/**
	 * Builds the file list from the verified-OTP response.
	 * Uses textContent throughout so a filename can never inject markup.
	 */
	function folioDrawbridgeRenderFiles(data) {
		var ul = document.getElementById('folio-drawbridge-file-list');
		ul.innerHTML = '';

		var files = data.files || [];
		document.getElementById('folio-drawbridge-no-files').style.display = files.length ? 'none' : '';

		if (data.limit_note) {
			var note = document.getElementById('folio-drawbridge-dl-limit');
			note.textContent = data.limit_note;
			note.style.display = '';
		}

		files.forEach(function(f) {
			var li = document.createElement('li');

			var nameEl = document.createElement('span');
			nameEl.className = 'folio-drawbridge-file-name';
			nameEl.textContent = f.name;

			var sizeEl = document.createElement('span');
			sizeEl.className = 'folio-drawbridge-file-size';
			sizeEl.textContent = f.size;

			var btn = document.createElement('a');
			btn.className = 'folio-drawbridge-btn folio-drawbridge-btn-sm';
			btn.href = '#';
			btn.id = 'folio-drawbridge-dl-' + f.id;
			btn.textContent = 'Download';
			btn.onclick = function() { folioDrawbridgeDownload(f.id, f.name); return false; };

			li.appendChild(nameEl);
			li.appendChild(sizeEl);
			li.appendChild(btn);
			ul.appendChild(li);
		});

		folioDrawbridgeData.zipName = data.zip_name || '';
		document.getElementById('folio-drawbridge-zip-wrap').style.display = data.zip_available ? '' : 'none';
	}

	function folioDrawbridgeBackToEmail() {
		document.getElementById('folio-drawbridge-step-otp').style.display   = 'none';
		document.getElementById('folio-drawbridge-step-email').style.display  = '';
		document.getElementById('folio-drawbridge-otp').value = '';
		folioDrawbridgeHideError('folio-drawbridge-otp-error');
	}

	function folioDrawbridgeTriggerDownload(url, fileName) {
		var a = document.createElement('a');
		a.href = url;
		// An explicit name beats an empty download attribute, which makes the
		// browser guess from the URL path (giving "download" / "admin-ajax").
		if (fileName) { a.download = fileName; }
		a.style.display = 'none';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
	}

	function folioDrawbridgeDownload(fileId, fileName) {
		if (!folioDrawbridgeData.dlToken) return;
		folioDrawbridgeTriggerDownload(
			folioDrawbridgeData.homeBase + '?folio_drawbridge_download=' + fileId + '&dt=' + encodeURIComponent(folioDrawbridgeData.dlToken),
			fileName
		);
	}

	function folioDrawbridgeDownloadZip() {
		if (!folioDrawbridgeData.dlToken) return;
		folioDrawbridgeTriggerDownload(
			folioDrawbridgeData.ajaxUrl + '?action=folio_drawbridge_zip_download&dt=' + encodeURIComponent(folioDrawbridgeData.dlToken),
			folioDrawbridgeData.zipName
		);
	}

	function folioDrawbridgePost(data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
		return fetch(folioDrawbridgeData.ajaxUrl, { method: 'POST', body: body,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
		}).then(function(r){ return r.json(); });
	}

	function folioDrawbridgeShowError(id, msg) { var el = document.getElementById(id); el.textContent = msg; el.style.display = ''; }
	function folioDrawbridgeHideError(id) { document.getElementById(id).style.display = 'none'; }
	</script>
	<?php
	folio_drawbridge_share_page_footer();
}

function folio_drawbridge_share_page_header( string $site_name, string $home_url ): void {
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secure File Access &mdash; <?php echo esc_html( $site_name ); ?></title>
<?php wp_head(); ?>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;margin:0;padding:40px 16px;color:#1a1a2e}
.folio-drawbridge-wrap{max-width:560px;margin:0 auto}
.folio-drawbridge-logo{text-align:center;margin-bottom:24px}
.folio-drawbridge-logo a{color:#1a1a2e;text-decoration:none;font-weight:700;font-size:18px}
.folio-drawbridge-card{background:#fff;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:32px}
.folio-drawbridge-card h2{margin:0 0 8px;font-size:20px;color:#1a1a2e}
.folio-drawbridge-desc{color:#666;margin:0 0 24px;font-size:14px}
.folio-drawbridge-step label{display:block;font-weight:600;margin:0 0 6px;font-size:14px}
.folio-drawbridge-input{display:block;width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:6px;font-size:15px;margin-bottom:12px;outline:none;transition:border .15s}
.folio-drawbridge-input:focus{border-color:#2271b1}
.folio-drawbridge-btn{display:inline-block;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:background .15s}
.folio-drawbridge-btn-primary{background:#2271b1;color:#fff;width:100%;text-align:center}
.folio-drawbridge-btn-primary:hover{background:#135e96}
.folio-drawbridge-btn-secondary{background:#f0f2f5;color:#2271b1;width:100%;text-align:center;border:1px solid #d0d5dd}
.folio-drawbridge-btn-sm{background:#2271b1;color:#fff;padding:5px 12px;font-size:12px}
.folio-drawbridge-btn-sm:hover{background:#135e96;color:#fff}
.folio-drawbridge-alert{padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:14px}
.folio-drawbridge-alert-error{background:#fef0f0;border:1px solid #f5c6cb;color:#721c24}
.folio-drawbridge-success-note{color:#1a5c2e;background:#d1e7dd;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:14px}
.folio-drawbridge-note{color:#666;font-size:13px;margin:0 0 16px}
.folio-drawbridge-file-list{list-style:none;padding:0;margin:0}
.folio-drawbridge-file-list li{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f0f2f5}
.folio-drawbridge-file-list li:last-child{border-bottom:none}
.folio-drawbridge-file-name{flex:1;font-size:14px;word-break:break-all}
.folio-drawbridge-file-size{color:#888;font-size:12px;white-space:nowrap}
.folio-drawbridge-footer{text-align:center;margin-top:24px;font-size:12px;color:#999}
.folio-drawbridge-footer a{color:#999}
</style>
</head>
<body>
<div class="folio-drawbridge-wrap">
<div class="folio-drawbridge-logo"><a href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
<?php
}

function folio_drawbridge_share_page_footer(): void {
	?>
<div class="folio-drawbridge-footer">Secured by Folio Drawbridge &mdash; <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></div>
</div>
<?php wp_footer(); ?>
</body></html>
	<?php
}

function folio_drawbridge_share_page_error( string $title, string $message ): void {
	echo '<div class="folio-drawbridge-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p></div>';
}

// ─── File download endpoint ───────────────────────────────────────────────────

function folio_drawbridge_handle_file_download( int $file_id ): void {
	$token = sanitize_text_field( wp_unslash( $_GET['dt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public recipients authenticate via the one-time download-session token itself; nonces require a logged-in session.

	if ( ! $token ) {
		wp_die( 'Invalid download request.', 403 );
	}

	$session = folio_drawbridge_get_download_session( $token );
	if ( ! $session ) {
		wp_die( 'Download session expired or invalid. Please verify your identity again.', 403 );
	}

	// folio_drawbridge_share_is_live(), not folio_drawbridge_share_is_accessible(): once this session has
	// claimed its download, the spent limit must not strand the recipient
	// partway through collecting the vault.
	$share = folio_drawbridge_get_share( (int) $session['share_id'] );
	if ( ! $share || ! folio_drawbridge_share_is_live( $share ) ) {
		wp_die( 'This share link is no longer available.', 403 );
	}

	// Claim on the first file of the session; later files reuse that claim.
	if ( ! folio_drawbridge_session_claim_once( $token, $session ) ) {
		wp_die( 'This share link has reached its download limit.', 403 );
	}

	$file = folio_drawbridge_get_file( $file_id );
	if ( ! $file || (int) $file->vault_id !== (int) $share->vault_id ) {
		wp_die( 'File not found in this vault.', 404 );
	}

	$vault = folio_drawbridge_get_vault( (int) $share->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	folio_drawbridge_send_download_notification( (int) $share->id, $file_id, folio_drawbridge_get_client_ip() );
	folio_drawbridge_serve_file( $file, $vault, (int) $share->id, false );
}

// ─── ZIP bulk download ────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_folio_drawbridge_zip_download', 'folio_drawbridge_handle_zip_download' );
add_action( 'wp_ajax_folio_drawbridge_zip_download',        'folio_drawbridge_handle_zip_download' );

/**
 * Streams all files in a vault as a single ZIP archive.
 * Requires a valid download session token (?dt=TOKEN) and ZipArchive extension.
 */
function folio_drawbridge_handle_zip_download(): void {
	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_die( 'ZIP download is not available on this server (ZipArchive extension required).', 500 );
	}

	$token = sanitize_text_field( wp_unslash( $_GET['dt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public recipients authenticate via the one-time download-session token itself; nonces require a logged-in session.
	if ( ! $token ) {
		wp_die( 'Invalid download request.', 403 );
	}

	$session = folio_drawbridge_get_download_session( $token );
	if ( ! $session ) {
		wp_die( 'Download session expired or invalid. Please verify your identity again.', 403 );
	}

	// See folio_drawbridge_handle_file_download(): the limit was claimed at session issue.
	$share = folio_drawbridge_get_share( (int) $session['share_id'] );
	if ( ! $share || ! folio_drawbridge_share_is_live( $share ) ) {
		wp_die( 'This share link is no longer available.', 403 );
	}

	$vault = folio_drawbridge_get_vault( (int) $share->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	$files = folio_drawbridge_get_vault_files( (int) $vault->id );
	if ( empty( $files ) ) {
		wp_die( 'This vault has no files to download.', 404 );
	}

	// Claim before the expensive decrypt-and-archive work, so a refused request
	// costs no CPU. Reuses this session's existing claim if it already has one.
	if ( ! folio_drawbridge_session_claim_once( $token, $session ) ) {
		wp_die( 'This share link has reached its download limit.', 403 );
	}

	// Build ZIP in a temp file.
	$tmp_zip = wp_tempnam( 'folio_drawbridge_zip_' );
	$zip     = new ZipArchive();

	if ( $zip->open( $tmp_zip, ZipArchive::OVERWRITE ) !== true ) {
		@unlink( $tmp_zip );
		wp_die( 'Could not create ZIP archive.', 500 );
	}

	$dec_temps = [];
	$added     = 0;

	foreach ( $files as $file ) {
		$enc_path = folio_drawbridge_vault_file_path( (int) $vault->id, $file->stored_name );
		if ( ! file_exists( $enc_path ) ) {
			continue;
		}

		$dec_tmp     = wp_tempnam( 'folio_drawbridge_dec' );
		$dec_temps[] = $dec_tmp;

		$ok = folio_drawbridge_decrypt_file_to_path(
			$enc_path,
			$vault->vault_salt,
			$file->iv,
			(int) $file->file_size,
			$dec_tmp
		);

		if ( $ok ) {
			$zip->addFile( $dec_tmp, $file->original_name );
			$added++;
		}
	}

	$zip->close(); // copies all addFile() data into the archive

	foreach ( $dec_temps as $dec_tmp ) {
		@unlink( $dec_tmp );
	}

	if ( $added === 0 ) {
		@unlink( $tmp_zip );
		wp_die( 'No files could be decrypted.', 500 );
	}

	// Log and send. The download slot was already claimed above.
	folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_FILE_DOWNLOADED, (int) $vault->id, (int) $share->id,
		[ 'zip' => true, 'file_count' => $added ] );

	$safe_name = sanitize_file_name( $vault->name ) ?: 'vault';

	folio_drawbridge_prepare_binary_response();

	// Read the size after the archive is finalised and the stat cache is cleared,
	// so Content-Length can never disagree with the bytes actually sent.
	clearstatcache( true, $tmp_zip );
	$zip_bytes = (int) filesize( $tmp_zip );

	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: ' . folio_drawbridge_content_disposition( $safe_name . '.zip' ) );
	header( 'Content-Length: ' . $zip_bytes );
	header( 'Cache-Control: no-store' );
	header( 'X-Content-Type-Options: nosniff' );
	// Regenerated per request and no range support — see folio_drawbridge_serve_file().
	header( 'Accept-Ranges: none' );

	readfile( $tmp_zip );
	@unlink( $tmp_zip );

	exit;
}

// ─── AJAX: request OTP ────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_folio_drawbridge_request_otp', 'folio_drawbridge_ajax_request_otp' );
add_action( 'wp_ajax_folio_drawbridge_request_otp',        'folio_drawbridge_ajax_request_otp' );

function folio_drawbridge_ajax_request_otp(): void {
	check_ajax_referer( 'folio_drawbridge_public_nonce', '_wpnonce' );

	$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
	$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

	if ( ! $share_id || ! $email ) {
		wp_send_json_error( 'Invalid request.' );
	}

	$result = folio_drawbridge_send_otp( $share_id, $email );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'message' => 'Verification code sent.' ] );
}

// ─── AJAX: verify OTP ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_folio_drawbridge_verify_otp', 'folio_drawbridge_ajax_verify_otp' );
add_action( 'wp_ajax_folio_drawbridge_verify_otp',        'folio_drawbridge_ajax_verify_otp' );

function folio_drawbridge_ajax_verify_otp(): void {
	check_ajax_referer( 'folio_drawbridge_public_nonce', '_wpnonce' );

	$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
	$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$otp      = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['otp'] ?? '' ) ) );

	if ( ! $share_id || ! $email || strlen( $otp ) !== 6 ) {
		wp_send_json_error( 'Invalid request.' );
	}

	$result = folio_drawbridge_verify_otp_for_share( $share_id, $email, $otp );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	// No claim here: the limit is spent on the first file actually downloaded,
	// so verifying and then downloading nothing costs the recipient nothing.
	$dl_token = folio_drawbridge_create_download_session( $share_id );

	// The file manifest is returned only here, after the one-time code has been
	// verified — it is deliberately absent from the share page's HTML so that
	// holding the link alone never reveals what the vault contains.
	$share = folio_drawbridge_get_share( $share_id );
	$files = folio_drawbridge_get_vault_files( (int) $share->vault_id );

	$manifest = array_map(
		static function ( $file ) {
			return [
				'id'   => (int) $file->id,
				'name' => $file->original_name,
				'size' => size_format( $file->file_size ),
			];
		},
		$files
	);

	$limit_note = '';
	if ( (int) $share->max_downloads > 0 ) {
		$limit_note = sprintf(
			'Download limit: %d / %d used.',
			(int) $share->download_count,
			(int) $share->max_downloads
		);
	}

	$vault = folio_drawbridge_get_vault( (int) $share->vault_id );

	wp_send_json_success( [
		'download_token' => $dl_token,
		'files'          => $manifest,
		'zip_available'  => count( $manifest ) > 1 && class_exists( 'ZipArchive' ),
		'zip_name'       => ( $vault ? sanitize_file_name( $vault->name ) : '' ) . '.zip',
		'limit_note'     => $limit_note,
	] );
}

// ─── Shortcode: [folio_drawbridge_my_vaults] ───────────────────────────────────────────────

add_shortcode( 'folio_drawbridge_vaults', 'folio_drawbridge_render_my_vaults_shortcode' );

function folio_drawbridge_render_my_vaults_shortcode(): string {
	if ( ! folio_drawbridge_user_can_use() ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to manage your secure file vaults.', 'folio-drawbridge' ) . ' '
				. '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p>';
		}
		return '<p>' . esc_html__( 'You do not have permission to access the secure file vault.', 'folio-drawbridge' ) . '</p>';
	}

	$user_id  = get_current_user_id();
	$vaults   = folio_drawbridge_get_user_vaults( $user_id );
	$nonce    = wp_create_nonce( 'folio_drawbridge_user_nonce' );
	// Root-relative so chunked uploads are not blocked as mixed content when the
	// site is reached over HTTPS but WordPress has an http:// URL stored.
	$ajax_url = folio_drawbridge_root_relative_url( admin_url( 'admin-ajax.php' ) );

	// Share form global limits (admins are exempt).
	$sc_is_admin        = folio_drawbridge_is_admin();
	$sc_allow_unlim_dl  = get_option( 'folio_drawbridge_allow_unlimited_downloads', '1' ) === '1';
	$sc_default_dl      = (int) get_option( 'folio_drawbridge_default_max_downloads', 0 );
	$sc_dl_ceiling      = (int) get_option( 'folio_drawbridge_max_download_limit', 0 );
	$sc_allow_no_expiry = get_option( 'folio_drawbridge_allow_no_expiry', '1' ) === '1';
	$sc_default_expiry  = (int) get_option( 'folio_drawbridge_default_expiry_days', 0 );
	$sc_max_expiry      = (int) get_option( 'folio_drawbridge_max_expiry_days', 0 );

	$sc_dl_min          = ( ! $sc_is_admin && ! $sc_allow_unlim_dl ) ? 1 : 0;
	$sc_dl_max          = ( ! $sc_is_admin && $sc_dl_ceiling > 0 ) ? $sc_dl_ceiling : 0;
	$sc_expiry_required = ( ! $sc_is_admin && ! $sc_allow_no_expiry ) ? 'required' : '';
	$sc_expiry_max_ts   = ( ! $sc_is_admin && $sc_max_expiry > 0 ) ? strtotime( "+{$sc_max_expiry} days" ) : 0;

	// Pre-fill defaults as JS-safe values.
	$sc_js_defaults = wp_json_encode( [
		'defaultDl'      => $sc_default_dl,
		'dlMin'          => $sc_dl_min,
		'dlMax'          => $sc_dl_max,
		'expiryRequired' => (bool) $sc_expiry_required,
		'defaultExpiry'  => $sc_default_expiry > 0 ? gmdate( 'Y-m-d\TH:i', strtotime( "+{$sc_default_expiry} days" ) ) : '',
		'expiryMax'      => $sc_expiry_max_ts > 0 ? gmdate( 'Y-m-d\TH:i', $sc_expiry_max_ts ) : '',
	] );

	ob_start();
	?>
<div class="folio-drawbridge-vaults" id="folio-drawbridge-vaults">
<style>
.folio-drawbridge-vaults *{box-sizing:border-box}
.folio-drawbridge-vaults{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1a1a2e;max-width:860px}
.folio-drawbridge-mv-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.folio-drawbridge-mv-header h2{margin:0;font-size:22px}
.folio-drawbridge-mv-btn{display:inline-block;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:background .15s}
.folio-drawbridge-mv-btn-primary{background:#2271b1;color:#fff}
.folio-drawbridge-mv-btn-primary:hover{background:#135e96;color:#fff}
.folio-drawbridge-mv-btn-danger{background:#fff;color:#d63638;border:1px solid #d63638}
.folio-drawbridge-mv-btn-sm{padding:5px 10px;font-size:12px}
.folio-drawbridge-mv-vault{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:16px}
.folio-drawbridge-mv-vault-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
.folio-drawbridge-mv-vault-title{font-size:17px;font-weight:700;margin:0 0 4px}
.folio-drawbridge-mv-meta{font-size:12px;color:#888;margin:0 0 12px}
.folio-drawbridge-mv-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-left:6px}
.folio-drawbridge-badge-active{background:#d1e7dd;color:#0a3622}
.folio-drawbridge-badge-expired,.folio-drawbridge-badge-revoked{background:#f8d7da;color:#58151c}
.folio-drawbridge-badge-archived{background:#e2e3e5;color:#41464b}
.folio-drawbridge-mv-section{margin-top:12px;padding-top:12px;border-top:1px solid #f0f2f5}
.folio-drawbridge-mv-section h4{margin:0 0 8px;font-size:13px;font-weight:700;text-transform:uppercase;color:#888;letter-spacing:.5px}
.folio-drawbridge-mv-file-list,.folio-drawbridge-mv-share-list{list-style:none;padding:0;margin:0}
.folio-drawbridge-mv-file-list li,.folio-drawbridge-mv-share-list li{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f8f9fa;font-size:13px}
.folio-drawbridge-mv-file-list li:last-child,.folio-drawbridge-mv-share-list li:last-child{border-bottom:none}
.folio-drawbridge-mv-file-name,.folio-drawbridge-mv-share-email{flex:1;word-break:break-all}
.folio-drawbridge-mv-file-size{color:#aaa;font-size:11px;white-space:nowrap}
.folio-drawbridge-mv-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;display:flex;align-items:center;justify-content:center}
.folio-drawbridge-mv-modal{background:#fff;border-radius:10px;padding:28px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;z-index:9999}
.folio-drawbridge-mv-modal h3{margin:0 0 16px;font-size:18px}
.folio-drawbridge-mv-modal label{display:block;font-size:13px;font-weight:600;margin:12px 0 4px}
.folio-drawbridge-mv-modal input,.folio-drawbridge-mv-modal textarea,.folio-drawbridge-mv-modal select{width:100%;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px;font-size:14px}
.folio-drawbridge-mv-modal .folio-drawbridge-mv-actions{display:flex;gap:10px;margin-top:20px}
.folio-drawbridge-mv-alert{padding:8px 12px;border-radius:6px;margin-bottom:12px;font-size:13px}
.folio-drawbridge-mv-alert-error{background:#fef0f0;border:1px solid #f5c6cb;color:#721c24}
.folio-drawbridge-mv-alert-success{background:#d1e7dd;border:1px solid #a3cfbb;color:#0a3622}
.folio-drawbridge-mv-empty{color:#888;font-size:13px;font-style:italic}
</style>

<div class="folio-drawbridge-mv-header">
	<h2>My Secure Vaults</h2>
	<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-primary" onclick="folioDrawbridgeOpenNewVaultModal()">+ New Vault</button>
</div>

<div id="folio-drawbridge-mv-notice" style="display:none;margin-bottom:16px;"></div>

<?php if ( ! $vaults ) : ?>
	<p class="folio-drawbridge-mv-empty">You have no vaults yet. Click <strong>New Vault</strong> to create one.</p>
<?php else : ?>
	<?php
	// Two grouped queries covering every vault shown, not two per vault.
	$sc_files_by_vault  = folio_drawbridge_get_files_by_vault( wp_list_pluck( $vaults, 'id' ) );
	$sc_shares_by_vault = folio_drawbridge_get_shares_by_vault( wp_list_pluck( $vaults, 'id' ) );
	foreach ( $vaults as $vault ) :
		$files      = $sc_files_by_vault[ (int) $vault->id ] ?? [];
		$shares     = $sc_shares_by_vault[ (int) $vault->id ] ?? [];
	?>
	<div class="folio-drawbridge-mv-vault" id="folio-drawbridge-vault-<?php echo (int) $vault->id; ?>">
		<div class="folio-drawbridge-mv-vault-head">
			<div>
				<div class="folio-drawbridge-mv-vault-title">
					<?php echo esc_html( $vault->name ); ?>
					<span class="folio-drawbridge-mv-badge folio-drawbridge-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span>
				</div>
				<p class="folio-drawbridge-mv-meta">
					Created <?php echo esc_html( gmdate( 'M j, Y', strtotime( $vault->created_at ) ) ); ?>
					<?php if ( $vault->expires_at ) : ?>
						&bull; Expires <?php echo esc_html( gmdate( 'M j, Y', strtotime( $vault->expires_at ) ) ); ?>
					<?php endif; ?>
					&bull; <?php echo count( $files ); ?> file<?php echo count( $files ) !== 1 ? 's' : ''; ?>
				</p>
			</div>
			<div style="display:flex;gap:6px;flex-wrap:wrap">
				<?php if ( $vault->status === 'active' ) : ?>
					<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-primary folio-drawbridge-mv-btn-sm"
					        onclick="folioDrawbridgeOpenUploadModal(<?php echo (int) $vault->id; ?>)">Upload File</button>
					<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-sm" style="background:#f0f2f5;color:#2271b1;border:1px solid #d0d5dd;"
					        onclick="folioDrawbridgeOpenShareModal(<?php echo (int) $vault->id; ?>)">Share</button>
				<?php endif; ?>
				<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-danger folio-drawbridge-mv-btn-sm"
				        onclick="folioDrawbridgeDeleteVault(<?php echo (int) $vault->id; ?>, '<?php echo esc_js( $vault->name ); ?>')">Delete</button>
			</div>
		</div>

		<?php if ( $files ) : ?>
		<div class="folio-drawbridge-mv-section">
			<h4>Files</h4>
			<ul class="folio-drawbridge-mv-file-list">
				<?php foreach ( $files as $f ) : ?>
				<li>
					<span class="folio-drawbridge-mv-file-name"><?php echo esc_html( $f->original_name ); ?></span>
					<span class="folio-drawbridge-mv-file-size"><?php echo esc_html( size_format( $f->file_size ) ); ?></span>
					<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-danger folio-drawbridge-mv-btn-sm"
					        onclick="folioDrawbridgeDeleteFile(<?php echo (int) $f->id; ?>, <?php echo (int) $vault->id; ?>)">Remove</button>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( $shares ) : ?>
		<div class="folio-drawbridge-mv-section">
			<h4>Active Shares</h4>
			<ul class="folio-drawbridge-mv-share-list">
				<?php foreach ( $shares as $s ) : ?>
				<li>
					<span class="folio-drawbridge-mv-share-email"><?php echo esc_html( $s->recipient_email ); ?></span>
					<span class="folio-drawbridge-mv-badge folio-drawbridge-badge-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span>
					<span style="color:#aaa;font-size:11px"><?php echo (int) $s->download_count; ?> dl</span>
					<?php if ( in_array( $s->status, [ 'pending', 'active' ], true ) ) : ?>
						<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-danger folio-drawbridge-mv-btn-sm"
						        onclick="folioDrawbridgeRevokeShare(<?php echo (int) $s->id; ?>, <?php echo (int) $vault->id; ?>)">Revoke</button>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
<?php endif; ?>

<!-- New Vault Modal -->
<div id="folio-drawbridge-modal-vault" class="folio-drawbridge-mv-modal-overlay" style="display:none" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-vault')">
	<div class="folio-drawbridge-mv-modal" onclick="event.stopPropagation()">
		<h3>Create New Vault</h3>
		<div id="folio-drawbridge-vault-modal-error" class="folio-drawbridge-mv-alert folio-drawbridge-mv-alert-error" style="display:none"></div>
		<label>Vault Name *</label>
		<input type="text" id="folio-drawbridge-vault-name" placeholder="e.g. Q1 Financial Reports" maxlength="255">
		<label>Description</label>
		<textarea id="folio-drawbridge-vault-desc" rows="3" placeholder="Optional description..."></textarea>
		<label>Expiry Date (optional)</label>
		<input type="date" id="folio-drawbridge-vault-expires">
		<div class="folio-drawbridge-mv-actions">
			<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-primary" onclick="folioDrawbridgeCreateVault()">Create Vault</button>
			<button class="folio-drawbridge-mv-btn" style="background:#f0f2f5;color:#333" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-vault')">Cancel</button>
		</div>
	</div>
</div>

<!-- Upload File Modal -->
<div id="folio-drawbridge-modal-upload" class="folio-drawbridge-mv-modal-overlay" style="display:none" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-upload')">
	<div class="folio-drawbridge-mv-modal" onclick="event.stopPropagation()">
		<h3>Upload Files to Vault</h3>
		<div id="folio-drawbridge-upload-modal-error" class="folio-drawbridge-mv-alert folio-drawbridge-mv-alert-error" style="display:none"></div>
		<label>Select Files (max <?php echo (int) get_option( 'folio_drawbridge_max_file_mb', 50 ); ?> MB each — hold Ctrl/Cmd to select multiple)</label>
		<input type="file" id="folio-drawbridge-file-input" multiple>
		<div id="folio-drawbridge-upload-queue" style="margin-top:10px;max-height:200px;overflow-y:auto;"></div>
		<div class="folio-drawbridge-mv-actions">
			<button id="folio-drawbridge-upload-btn" class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-primary" onclick="folioDrawbridgeUploadFile()">Encrypt &amp; Upload</button>
			<button id="folio-drawbridge-upload-cancel-btn" class="folio-drawbridge-mv-btn" style="background:#f0f2f5;color:#333" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-upload')">Cancel</button>
		</div>
	</div>
</div>

<!-- Share Modal -->
<div id="folio-drawbridge-modal-share" class="folio-drawbridge-mv-modal-overlay" style="display:none" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-share')">
	<div class="folio-drawbridge-mv-modal" onclick="event.stopPropagation()">
		<h3>Share Vault</h3>
		<div id="folio-drawbridge-share-modal-error" class="folio-drawbridge-mv-alert folio-drawbridge-mv-alert-error" style="display:none"></div>
		<label>Recipient Email *</label>
		<input type="email" id="folio-drawbridge-share-email" placeholder="recipient@example.com">
		<label>
			Download Limit
			<?php echo ( $sc_is_admin || $sc_allow_unlim_dl ) ? '(0 = unlimited)' : ''; ?>
		</label>
		<input type="number" id="folio-drawbridge-share-maxdl" value="<?php echo (int) $sc_default_dl; ?>"
		       min="<?php echo (int) $sc_dl_min; ?>"
		       <?php echo $sc_dl_max > 0 ? 'max="' . (int) $sc_dl_max . '"' : ''; ?>>
		<label>
			Link Expires
			<?php echo $sc_expiry_required ? '<span style="color:#d63638;">*</span>' : '(optional)'; ?>
		</label>
		<input type="date" id="folio-drawbridge-share-expires"
		       min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
		       <?php echo esc_attr( $sc_expiry_required ); ?>>
		<div class="folio-drawbridge-mv-actions">
			<button class="folio-drawbridge-mv-btn folio-drawbridge-mv-btn-primary" onclick="folioDrawbridgeCreateShare()">Send Invite</button>
			<button class="folio-drawbridge-mv-btn" style="background:#f0f2f5;color:#333" onclick="folioDrawbridgeCloseModal('folio-drawbridge-modal-share')">Cancel</button>
		</div>
	</div>
</div>

<script>
var folioDrawbridgeUserData = {
	ajaxUrl:    <?php echo wp_json_encode( $ajax_url ); ?>,
	nonce:      <?php echo wp_json_encode( $nonce ); ?>,
	chunkSize:  <?php echo (int) folio_drawbridge_chunk_size_bytes(); ?>,
	activeVaultId: null,
	shareLimits: <?php echo $sc_js_defaults; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output. ?>
};

function folioDrawbridgeOpenNewVaultModal() {
	document.getElementById('folio-drawbridge-vault-name').value='';
	document.getElementById('folio-drawbridge-vault-desc').value='';
	document.getElementById('folio-drawbridge-vault-expires').value='';
	folioDrawbridgeHideError2('folio-drawbridge-vault-modal-error');
	document.getElementById('folio-drawbridge-modal-vault').style.display='flex';
}
function folioDrawbridgeOpenUploadModal(vaultId) {
	folioDrawbridgeUserData.activeVaultId = vaultId;
	document.getElementById('folio-drawbridge-file-input').value='';
	document.getElementById('folio-drawbridge-upload-queue').innerHTML='';
	document.getElementById('folio-drawbridge-upload-btn').disabled=false;
	document.getElementById('folio-drawbridge-upload-cancel-btn').disabled=false;
	folioDrawbridgeHideError2('folio-drawbridge-upload-modal-error');
	document.getElementById('folio-drawbridge-modal-upload').style.display='flex';
}
function folioDrawbridgeOpenShareModal(vaultId) {
	folioDrawbridgeUserData.activeVaultId = vaultId;
	var lim = folioDrawbridgeUserData.shareLimits;
	var dlEl = document.getElementById('folio-drawbridge-share-maxdl');
	var exEl = document.getElementById('folio-drawbridge-share-expires');
	document.getElementById('folio-drawbridge-share-email').value = '';
	dlEl.value = lim.defaultDl;
	dlEl.min   = lim.dlMin;
	if (lim.dlMax > 0) { dlEl.max = lim.dlMax; } else { dlEl.removeAttribute('max'); }
	exEl.value = lim.defaultExpiry ? lim.defaultExpiry.substring(0,10) : '';
	if (lim.expiryMax) { exEl.max = lim.expiryMax.substring(0,10); } else { exEl.removeAttribute('max'); }
	if (lim.expiryRequired) { exEl.setAttribute('required',''); } else { exEl.removeAttribute('required'); }
	folioDrawbridgeHideError2('folio-drawbridge-share-modal-error');
	document.getElementById('folio-drawbridge-modal-share').style.display='flex';
}
function folioDrawbridgeCloseModal(id) { document.getElementById(id).style.display='none'; }

function folioDrawbridgeCreateVault() {
	var name    = document.getElementById('folio-drawbridge-vault-name').value.trim();
	var desc    = document.getElementById('folio-drawbridge-vault-desc').value.trim();
	var expires = document.getElementById('folio-drawbridge-vault-expires').value;
	if (!name) { folioDrawbridgeShowError2('folio-drawbridge-vault-modal-error','Vault name is required.'); return; }
	folioDrawbridgeUserPost({ action:'folio_drawbridge_create_vault', name:name, desc:desc, expires_at:expires, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeCloseModal('folio-drawbridge-modal-vault'); folioDrawbridgeShowNotice('Vault created. Reloading…','success'); setTimeout(function(){ location.reload(); },1200); }
			else { folioDrawbridgeShowError2('folio-drawbridge-vault-modal-error', r.data||'Error creating vault.'); }
		});
}

function folioDrawbridgeGenerateUploadId() {
	return Array.from(crypto.getRandomValues(new Uint8Array(16)))
		.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
}

function folioDrawbridgeMvEsc(s) {
	return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function folioDrawbridgeMvMakeQueueRow(fileName) {
	var row = document.createElement('div');
	row.style.cssText = 'margin-bottom:6px;padding:7px 10px;background:#f6f7f7;border-radius:4px;font-size:12px;';
	row.innerHTML =
		'<div style="display:flex;justify-content:space-between;margin-bottom:4px;">'
		+ '<span style="font-weight:600;word-break:break-all;">' + folioDrawbridgeMvEsc(fileName) + '</span>'
		+ '<span class="folio-drawbridge-mv-qlbl" style="color:#888;white-space:nowrap;margin-left:8px;">Queued</span></div>'
		+ '<div style="background:#e0e0e0;border-radius:3px;height:7px;overflow:hidden;">'
		+ '<div class="folio-drawbridge-mv-qbar" style="background:#2271b1;height:100%;width:0%;transition:width .15s;"></div></div>';
	return row;
}

async function folioDrawbridgeUploadOneFile(file, rowEl) {
	var bar   = rowEl.querySelector('.folio-drawbridge-mv-qbar');
	var lbl   = rowEl.querySelector('.folio-drawbridge-mv-qlbl');
	var CHUNK = folioDrawbridgeUserData.chunkSize;
	var total = Math.ceil(file.size / CHUNK) || 1;
	var uid   = folioDrawbridgeGenerateUploadId();
	lbl.textContent = 'Uploading…';
	for (var i = 0; i < total; i++) {
		var start = i * CHUNK;
		var fd    = new FormData();
		fd.append('action',       'folio_drawbridge_upload_chunk');
		fd.append('_wpnonce',     folioDrawbridgeUserData.nonce);
		fd.append('vault_id',     folioDrawbridgeUserData.activeVaultId);
		fd.append('upload_id',    uid);
		fd.append('chunk_index',  i);
		fd.append('total_chunks', total);
		fd.append('file_name',    file.name);
		fd.append('total_size',   file.size);
		fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
		var r = await fetch(folioDrawbridgeUserData.ajaxUrl, {method:'POST', body:fd});
		var j = await r.json();
		if (!j.success) throw new Error(j.data || 'Upload failed.');
		var pct = Math.round((i + 1) / total * 100);
		bar.style.width = pct + '%';
		lbl.textContent = j.data.complete ? 'Done' : pct + '%';
	}
}

async function folioDrawbridgeUploadFile() {
	var input   = document.getElementById('folio-drawbridge-file-input');
	var queueEl = document.getElementById('folio-drawbridge-upload-queue');
	folioDrawbridgeHideError2('folio-drawbridge-upload-modal-error');
	if (!input.files.length) { folioDrawbridgeShowError2('folio-drawbridge-upload-modal-error','Please select at least one file.'); return; }

	var btn = document.getElementById('folio-drawbridge-upload-btn');
	var ccl = document.getElementById('folio-drawbridge-upload-cancel-btn');
	btn.disabled = true;
	ccl.disabled = true;
	queueEl.innerHTML = '';

	var files = Array.from(input.files);
	var rows  = files.map(function(f) {
		var row = folioDrawbridgeMvMakeQueueRow(f.name);
		queueEl.appendChild(row);
		return row;
	});

	var hasError = false;
	for (var i = 0; i < files.length; i++) {
		try {
			await folioDrawbridgeUploadOneFile(files[i], rows[i]);
			rows[i].querySelector('.folio-drawbridge-mv-qlbl').style.color = '#0a3622';
		} catch(e) {
			var lbl = rows[i].querySelector('.folio-drawbridge-mv-qlbl');
			lbl.textContent = 'Error: ' + e.message;
			lbl.style.color = '#d63638';
			hasError = true;
		}
	}

	if (!hasError) {
		folioDrawbridgeCloseModal('folio-drawbridge-modal-upload');
		folioDrawbridgeShowNotice(files.length + ' file(s) encrypted and uploaded. Reloading…', 'success');
		setTimeout(function(){ location.reload(); }, 1400);
	} else {
		btn.disabled = false;
		ccl.disabled = false;
	}
}

function folioDrawbridgeCreateShare() {
	var email      = document.getElementById('folio-drawbridge-share-email').value.trim();
	var maxdl      = document.getElementById('folio-drawbridge-share-maxdl').value;
	var expiresRaw = document.getElementById('folio-drawbridge-share-expires').value;
	var expires    = expiresRaw ? expiresRaw + ' 23:59:59' : '';
	if (!email) { folioDrawbridgeShowError2('folio-drawbridge-share-modal-error','Recipient email is required.'); return; }
	folioDrawbridgeUserPost({ action:'folio_drawbridge_create_share', vault_id:folioDrawbridgeUserData.activeVaultId, email:email, max_downloads:maxdl, expires_at:expires, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeCloseModal('folio-drawbridge-modal-share'); folioDrawbridgeShowNotice('Share invite sent to '+email+'.','success'); setTimeout(function(){ location.reload(); },1500); }
			else { folioDrawbridgeShowError2('folio-drawbridge-share-modal-error', r.data||'Error creating share.'); }
		});
}

function folioDrawbridgeDeleteFile(fileId, vaultId) {
	if (!confirm('Permanently delete this file? This cannot be undone.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_delete_file', file_id:fileId, vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('File deleted.','success'); setTimeout(function(){ location.reload(); },800); }
			else { folioDrawbridgeShowNotice(r.data||'Error deleting file.','error'); }
		});
}

function folioDrawbridgeDeleteVault(vaultId, name) {
	if (!confirm('Permanently delete vault "'+name+'" and all its files? This cannot be undone.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_delete_vault', vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('Vault deleted. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { folioDrawbridgeShowNotice(r.data||'Error deleting vault.','error'); }
		});
}

function folioDrawbridgeRevokeShare(shareId, vaultId) {
	if (!confirm('Revoke this share? The recipient will immediately lose access.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_revoke_share', share_id:shareId, vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('Share revoked. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { folioDrawbridgeShowNotice(r.data||'Error revoking share.','error'); }
		});
}

function folioDrawbridgeUserPost(data) {
	var body = new URLSearchParams();
	Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
	return fetch(folioDrawbridgeUserData.ajaxUrl,{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded'}}).then(function(r){return r.json();});
}
function folioDrawbridgeShowError2(id, msg) { var el=document.getElementById(id); el.textContent=msg; el.style.display=''; }
function folioDrawbridgeHideError2(id) { document.getElementById(id).style.display='none'; }
function folioDrawbridgeShowNotice(msg,type) {
	var el=document.getElementById('folio-drawbridge-mv-notice');
	el.className='folio-drawbridge-mv-alert folio-drawbridge-mv-alert-'+(type==='success'?'success':'error');
	el.textContent=msg; el.style.display='';
	setTimeout(function(){ el.style.display='none'; },4000);
}
</script>
</div>
	<?php
	return ob_get_clean();
}

// ─── AJAX: chunked file upload ────────────────────────────────────────────────

add_action( 'wp_ajax_folio_drawbridge_upload_chunk', 'folio_drawbridge_upload_chunk_handler' );

function folio_drawbridge_upload_chunk_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'Access denied.' );
	}

	$vault_id      = absint( wp_unslash( $_POST['vault_id']     ?? 0 ) );
	$upload_id     = preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['upload_id'] ?? '' ) ) );
	$chunk_index   = absint( wp_unslash( $_POST['chunk_index']  ?? 0 ) );
	$total_chunks  = absint( wp_unslash( $_POST['total_chunks'] ?? 0 ) );
	$original_name = sanitize_file_name( wp_unslash( $_POST['file_name'] ?? '' ) );
	$total_size    = absint( wp_unslash( $_POST['total_size']   ?? 0 ) );

	if ( ! $upload_id || strlen( $upload_id ) < 8 || ! $original_name
		|| $total_chunks < 1 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
		wp_send_json_error( 'Invalid parameters.' );
	}

	// Validate vault ownership.
	$user_id = get_current_user_id();
	$vault   = folio_drawbridge_get_vault( $vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		wp_send_json_error( 'Vault not found or not active.' );
	}
	if ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) {
		wp_send_json_error( 'Access denied.' );
	}

	// Enforce total-size limit early so we fail before writing chunks.
	$max_mb = (int) get_option( 'folio_drawbridge_max_file_mb', 50 );
	if ( $total_size > $max_mb * 1024 * 1024 ) {
		wp_send_json_error( "File exceeds the {$max_mb} MB limit." );
	}

	if ( empty( $_FILES['chunk'] ) || (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- PHP-populated upload metadata; error code is strictly compared.
		wp_send_json_error( 'Chunk upload failed — check server upload limits.' );
	}

	// Write chunk to temp directory.
	$chunks_base = folio_drawbridge_ensure_chunks_dir();
	$upload_dir  = $chunks_base . $upload_id . '/';
	if ( ! is_dir( $upload_dir ) ) {
		wp_mkdir_p( $upload_dir );
	}

	// wp_handle_upload() cannot be used here: each POST carries one raw chunk of a
	// larger file, which must land in the chunk staging area under its sequence
	// number — not in the media library. is_uploaded_file() guarantees the source
	// really came through this HTTP POST before we move it.
	$chunk_tmp = $_FILES['chunk']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- PHP-populated temp path, verified by is_uploaded_file() below.
	if ( ! is_uploaded_file( $chunk_tmp )
		|| ! move_uploaded_file( $chunk_tmp, $upload_dir . $chunk_index . '.part' ) // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- chunked upload staging; see comment above.
	) {
		wp_send_json_error( 'Failed to save chunk to disk.' );
	}

	// Not the final chunk — acknowledge and wait for the next one.
	if ( $chunk_index < $total_chunks - 1 ) {
		wp_send_json_success( [ 'chunk' => $chunk_index, 'complete' => false ] );
	}

	// Final chunk: verify all parts are present.
	for ( $i = 0; $i < $total_chunks; $i++ ) {
		if ( ! file_exists( $upload_dir . $i . '.part' ) ) {
			wp_send_json_error( "Missing chunk {$i} — please retry the upload." );
		}
	}

	// Assemble parts into a single temp file.
	$assembled = $chunks_base . $upload_id . '.tmp';
	$out       = fopen( $assembled, 'wb' );
	if ( ! $out ) {
		wp_send_json_error( 'Failed to assemble uploaded file.' );
	}

	for ( $i = 0; $i < $total_chunks; $i++ ) {
		$part_path = $upload_dir . $i . '.part';
		$part      = fopen( $part_path, 'rb' );
		stream_copy_to_stream( $part, $out );
		fclose( $part );
		unlink( $part_path );
	}
	fclose( $out );
	@rmdir( $upload_dir );

	// File type restriction check ($upload_dir already cleaned at this point).
	$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( ! folio_drawbridge_is_allowed_file_type( $ext ) ) {
		@unlink( $assembled );
		wp_send_json_error( "File type .{$ext} is not permitted on this site." );
	}

	// Per-user storage quota check (admins exempt).
	if ( ! folio_drawbridge_is_admin( $user_id ) ) {
		$quota_mb = (int) get_option( 'folio_drawbridge_storage_quota_mb', 0 );
		if ( $quota_mb > 0 ) {
			$used = folio_drawbridge_get_user_storage_used( $user_id );
			if ( $used + $total_size > $quota_mb * 1024 * 1024 ) {
				@unlink( $assembled );
				wp_send_json_error( "Upload would exceed your storage quota of {$quota_mb} MB." );
			}
		}
	}

	// Encrypt and store; clean up temp file regardless of outcome.
	$result = folio_drawbridge_encrypt_and_store_file( $vault_id, $assembled, $original_name, $total_size, $user_id );
	@unlink( $assembled );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'file_id' => $result, 'complete' => true ] );
}

// ─── AJAX: authenticated user vault actions ───────────────────────────────────

add_action( 'wp_ajax_folio_drawbridge_create_vault',  'folio_drawbridge_create_vault_handler' );
add_action( 'wp_ajax_folio_drawbridge_upload_file',   'folio_drawbridge_upload_file_handler' );
add_action( 'wp_ajax_folio_drawbridge_create_share',  'folio_drawbridge_create_share_handler' );
add_action( 'wp_ajax_folio_drawbridge_delete_file',   'folio_drawbridge_delete_file_handler' );
add_action( 'wp_ajax_folio_drawbridge_delete_vault',  'folio_drawbridge_delete_vault_handler' );
add_action( 'wp_ajax_folio_drawbridge_revoke_share',  'folio_drawbridge_revoke_share_handler' );

function folio_drawbridge_create_vault_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id    = get_current_user_id();
	$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$desc       = sanitize_textarea_field( wp_unslash( $_POST['desc'] ?? '' ) );
	$expires_at = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );

	if ( ! $name ) {
		wp_send_json_error( 'Vault name is required.' );
	}

	// Convert HTML datetime-local to MySQL datetime.
	$expires_mysql = '';
	if ( $expires_at ) {
		$ts = strtotime( $expires_at );
		if ( $ts ) {
			$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
		}
	}

	$vault_id = folio_drawbridge_create_vault( $user_id, $name, $desc, $expires_mysql );

	if ( ! $vault_id ) {
		wp_send_json_error( 'Failed to create vault.' );
	}

	wp_send_json_success( [ 'vault_id' => $vault_id ] );
}

function folio_drawbridge_upload_file_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
	$vault    = folio_drawbridge_get_vault( $vault_id );

	if ( ! $vault || (int) $vault->owner_id !== $user_id ) {
		// Admins may also upload to any vault.
		if ( ! folio_drawbridge_is_admin() || ! $vault ) {
			wp_send_json_error( 'Vault not found or access denied.' );
		}
	}

	if ( empty( $_FILES['folio_drawbridge_file'] ) ) {
		wp_send_json_error( 'No file received.' );
	}

	$result = folio_drawbridge_upload_file_to_vault( $vault_id, $_FILES['folio_drawbridge_file'], $user_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES entry is validated (error code) and its name sanitized inside folio_drawbridge_upload_file_to_vault().

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'file_id' => $result ] );
}

function folio_drawbridge_create_share_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id       = get_current_user_id();
	$vault_id      = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
	$email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$max_downloads = max( 0, absint( wp_unslash( $_POST['max_downloads'] ?? 0 ) ) );
	$expires_at    = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );

	$vault = folio_drawbridge_get_vault( $vault_id );
	if ( ! $vault || (int) $vault->owner_id !== $user_id ) {
		if ( ! folio_drawbridge_is_admin() || ! $vault ) {
			wp_send_json_error( 'Vault not found or access denied.' );
		}
	}

	$expires_mysql = '';
	if ( $expires_at ) {
		$ts = strtotime( $expires_at );
		if ( $ts ) {
			$expires_mysql = gmdate( 'Y-m-d 23:59:59', $ts );
		}
	}

	$result = folio_drawbridge_create_share( $vault_id, $user_id, $email, $max_downloads, $expires_mysql );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'share_id' => $result ] );
}

function folio_drawbridge_delete_file_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$file_id  = absint( wp_unslash( $_POST['file_id'] ?? 0 ) );
	$file     = folio_drawbridge_get_file( $file_id );

	if ( ! $file ) {
		wp_send_json_error( 'File not found.' );
	}

	$vault = folio_drawbridge_get_vault( (int) $file->vault_id );
	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) ) {
		wp_send_json_error( 'Access denied.' );
	}

	folio_drawbridge_delete_file( $file_id, $user_id );
	wp_send_json_success();
}

function folio_drawbridge_delete_vault_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
	$vault    = folio_drawbridge_get_vault( $vault_id );

	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) ) {
		wp_send_json_error( 'Vault not found or access denied.' );
	}

	folio_drawbridge_delete_vault( $vault_id );
	wp_send_json_success();
}

function folio_drawbridge_revoke_share_handler(): void {
	check_ajax_referer( 'folio_drawbridge_user_nonce', '_wpnonce' );

	if ( ! folio_drawbridge_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
	$share    = folio_drawbridge_get_share( $share_id );

	if ( ! $share ) {
		wp_send_json_error( 'Share not found.' );
	}

	$vault = folio_drawbridge_get_vault( (int) $share->vault_id );
	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) ) {
		wp_send_json_error( 'Access denied.' );
	}

	folio_drawbridge_revoke_share( $share_id, $user_id );
	wp_send_json_success();
}
