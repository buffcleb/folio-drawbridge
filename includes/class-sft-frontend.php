<?php
/**
 * Front-end: public share access page, [sft_my_vaults] shortcode, and AJAX handlers.
 *
 * Public share flow (unauthenticated recipients):
 *   /?sft_share=TOKEN   →  email input  →  OTP email  →  OTP verify  →  download
 *   /?sft_download=FILE_ID&dt=DOWNLOAD_TOKEN  →  stream decrypted file
 *
 * Authenticated user shortcode:
 *   [sft_my_vaults]  —  lists the current user's vaults; allows vault creation,
 *                       file upload, share creation, and share revocation via AJAX.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Capability helper ────────────────────────────────────────────────────────

/**
 * Returns true if the current user may use the vault features.
 * SFT admins and WP admins are always allowed; others need the use_sft_vaults capability.
 */
function sft_user_can_use(): bool {
	return is_user_logged_in() &&
		( sft_is_admin() || current_user_can( 'use_sft_vaults' ) );
}

// ─── Query vars ───────────────────────────────────────────────────────────────

add_filter( 'query_vars', 'sft_register_query_vars' );

function sft_register_query_vars( array $vars ): array {
	$vars[] = 'sft_share';
	$vars[] = 'sft_download';
	return $vars;
}

// ─── Public page interception ─────────────────────────────────────────────────

add_action( 'template_redirect', 'sft_template_redirect' );

function sft_template_redirect(): void {
	$share_token = get_query_var( 'sft_share' );
	$file_id     = get_query_var( 'sft_download' );

	if ( $share_token ) {
		sft_render_share_page( sanitize_text_field( $share_token ) );
		exit;
	}

	if ( $file_id ) {
		sft_handle_file_download( (int) $file_id );
		exit;
	}
}

// ─── Public share access page ─────────────────────────────────────────────────

/**
 * Renders the full HTML page for recipient share access.
 * Handles: invalid token, expired/revoked share, email form, OTP form, and file list.
 */
function sft_render_share_page( string $token ): void {
	$share = sft_get_share_by_token( $token );

	$site_name = esc_html( get_bloginfo( 'name' ) );
	$home_url  = esc_url( home_url( '/' ) );

	sft_share_page_header( $site_name, $home_url );

	if ( ! $share ) {
		sft_share_page_error( 'Invalid link', 'This secure share link is not valid.' );
		sft_share_page_footer();
		return;
	}

	if ( ! sft_share_is_accessible( $share ) ) {
		$reason = $share->status === 'revoked' ? 'This share link has been revoked.' : 'This share link has expired or reached its download limit.';
		sft_share_page_error( 'Link unavailable', $reason );
		sft_share_page_footer();
		return;
	}

	$vault = sft_get_vault( (int) $share->vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		sft_share_page_error( 'Vault unavailable', 'The vault associated with this link is no longer available.' );
		sft_share_page_footer();
		return;
	}

	$files    = sft_get_vault_files( (int) $vault->id );
	$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
	$nonce    = wp_create_nonce( 'sft_public_nonce' );
	$share_id = (int) $share->id;
	?>
	<div class="sft-card">
		<h2><?php echo esc_html( $vault->name ); ?></h2>
		<?php if ( $vault->description ) : ?>
			<p class="sft-desc"><?php echo esc_html( $vault->description ); ?></p>
		<?php endif; ?>

		<!-- Step 1: Email verification -->
		<div id="sft-step-email" class="sft-step">
			<p>To access the files in this vault, enter the email address where you received this link. A verification code will be sent to confirm your identity.</p>
			<div id="sft-email-error" class="sft-alert sft-alert-error" style="display:none;"></div>
			<label for="sft-email">Your email address</label>
			<input type="email" id="sft-email" class="sft-input" placeholder="you@example.com" autocomplete="email">
			<button class="sft-btn sft-btn-primary" onclick="sftRequestOtp()">Send Verification Code</button>
		</div>

		<!-- Step 2: OTP verification -->
		<div id="sft-step-otp" class="sft-step" style="display:none;">
			<p>A 6-digit verification code has been sent to your email address. Enter it below. The code is valid for <?php echo (int) get_option( 'sft_otp_ttl_minutes', 15 ); ?> minutes.</p>
			<div id="sft-otp-error" class="sft-alert sft-alert-error" style="display:none;"></div>
			<label for="sft-otp">Verification code</label>
			<input type="text" id="sft-otp" class="sft-input" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
			<button class="sft-btn sft-btn-primary" onclick="sftVerifyOtp()">Verify Code</button>
			<button class="sft-btn sft-btn-secondary" onclick="sftBackToEmail()" style="margin-top:8px;">← Change Email</button>
		</div>

		<!-- Step 3: File list (hidden until OTP verified) -->
		<div id="sft-step-files" class="sft-step" style="display:none;">
			<p class="sft-success-note">✓ Identity verified. You can now download the shared files.</p>
			<?php if ( $share->max_downloads > 0 ) : ?>
				<p class="sft-note">Download limit: <?php echo (int) $share->download_count; ?> / <?php echo (int) $share->max_downloads; ?> used.</p>
			<?php endif; ?>
			<?php if ( ! $files ) : ?>
				<p>This vault contains no files.</p>
			<?php else : ?>
				<ul class="sft-file-list">
					<?php foreach ( $files as $file ) : ?>
						<li>
							<span class="sft-file-name"><?php echo esc_html( $file->original_name ); ?></span>
							<span class="sft-file-size"><?php echo esc_html( size_format( $file->file_size ) ); ?></span>
							<a class="sft-btn sft-btn-sm"
							   id="sft-dl-<?php echo (int) $file->id; ?>"
							   href="#"
							   onclick="sftDownload(<?php echo (int) $file->id; ?>); return false;">
								Download
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>

	<script>
	var sftData = {
		ajaxUrl: <?php echo wp_json_encode( $ajax_url ); ?>,
		nonce:   <?php echo wp_json_encode( $nonce ); ?>,
		shareId: <?php echo $share_id; ?>,
		dlToken: null
	};

	function sftRequestOtp() {
		var email = document.getElementById('sft-email').value.trim();
		if (!email) { sftShowError('sft-email-error', 'Please enter your email address.'); return; }
		sftHideError('sft-email-error');
		sftPost({ action: 'sft_request_otp', share_id: sftData.shareId, email: email, _wpnonce: sftData.nonce })
			.then(function(r) {
				if (r.success) {
					document.getElementById('sft-step-email').style.display = 'none';
					document.getElementById('sft-step-otp').style.display   = '';
				} else {
					sftShowError('sft-email-error', r.data || 'An error occurred.');
				}
			});
	}

	function sftVerifyOtp() {
		var email = document.getElementById('sft-email').value.trim();
		var otp   = document.getElementById('sft-otp').value.trim();
		if (!otp)  { sftShowError('sft-otp-error', 'Please enter the verification code.'); return; }
		sftHideError('sft-otp-error');
		sftPost({ action: 'sft_verify_otp', share_id: sftData.shareId, email: email, otp: otp, _wpnonce: sftData.nonce })
			.then(function(r) {
				if (r.success) {
					sftData.dlToken = r.data.download_token;
					document.getElementById('sft-step-otp').style.display   = 'none';
					document.getElementById('sft-step-files').style.display  = '';
				} else {
					sftShowError('sft-otp-error', r.data || 'Verification failed.');
				}
			});
	}

	function sftBackToEmail() {
		document.getElementById('sft-step-otp').style.display   = 'none';
		document.getElementById('sft-step-email').style.display  = '';
		document.getElementById('sft-otp').value = '';
		sftHideError('sft-otp-error');
	}

	function sftDownload(fileId) {
		if (!sftData.dlToken) return;
		var url = <?php echo wp_json_encode( home_url( '/' ) ); ?> + '?sft_download=' + fileId + '&dt=' + encodeURIComponent(sftData.dlToken);
		var a = document.createElement('a');
		a.href = url; a.download = ''; a.style.display = 'none';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
	}

	function sftPost(data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
		return fetch(sftData.ajaxUrl, { method: 'POST', body: body,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
		}).then(function(r){ return r.json(); });
	}

	function sftShowError(id, msg) { var el = document.getElementById(id); el.textContent = msg; el.style.display = ''; }
	function sftHideError(id) { document.getElementById(id).style.display = 'none'; }
	</script>
	<?php
	sft_share_page_footer();
}

function sft_share_page_header( string $site_name, string $home_url ): void {
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secure File Access &mdash; <?php echo $site_name; ?></title>
<?php wp_head(); ?>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;margin:0;padding:40px 16px;color:#1a1a2e}
.sft-wrap{max-width:560px;margin:0 auto}
.sft-logo{text-align:center;margin-bottom:24px}
.sft-logo a{color:#1a1a2e;text-decoration:none;font-weight:700;font-size:18px}
.sft-card{background:#fff;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:32px}
.sft-card h2{margin:0 0 8px;font-size:20px;color:#1a1a2e}
.sft-desc{color:#666;margin:0 0 24px;font-size:14px}
.sft-step label{display:block;font-weight:600;margin:0 0 6px;font-size:14px}
.sft-input{display:block;width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:6px;font-size:15px;margin-bottom:12px;outline:none;transition:border .15s}
.sft-input:focus{border-color:#2271b1}
.sft-btn{display:inline-block;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:background .15s}
.sft-btn-primary{background:#2271b1;color:#fff;width:100%;text-align:center}
.sft-btn-primary:hover{background:#135e96}
.sft-btn-secondary{background:#f0f2f5;color:#2271b1;width:100%;text-align:center;border:1px solid #d0d5dd}
.sft-btn-sm{background:#2271b1;color:#fff;padding:5px 12px;font-size:12px}
.sft-btn-sm:hover{background:#135e96;color:#fff}
.sft-alert{padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:14px}
.sft-alert-error{background:#fef0f0;border:1px solid #f5c6cb;color:#721c24}
.sft-success-note{color:#1a5c2e;background:#d1e7dd;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:14px}
.sft-note{color:#666;font-size:13px;margin:0 0 16px}
.sft-file-list{list-style:none;padding:0;margin:0}
.sft-file-list li{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f0f2f5}
.sft-file-list li:last-child{border-bottom:none}
.sft-file-name{flex:1;font-size:14px;word-break:break-all}
.sft-file-size{color:#888;font-size:12px;white-space:nowrap}
.sft-footer{text-align:center;margin-top:24px;font-size:12px;color:#999}
.sft-footer a{color:#999}
</style>
</head>
<body>
<div class="sft-wrap">
<div class="sft-logo"><a href="<?php echo $home_url; ?>"><?php echo $site_name; ?></a></div>
<?php
}

function sft_share_page_footer(): void {
	?>
<div class="sft-footer">Secured by WP Secure File Transfer Pro &mdash; <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></div>
</div>
<?php wp_footer(); ?>
</body></html>
	<?php
}

function sft_share_page_error( string $title, string $message ): void {
	echo '<div class="sft-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p></div>';
}

// ─── File download endpoint ───────────────────────────────────────────────────

function sft_handle_file_download( int $file_id ): void {
	$token = sanitize_text_field( $_GET['dt'] ?? '' );

	if ( ! $token ) {
		wp_die( 'Invalid download request.', 403 );
	}

	$session = sft_get_download_session( $token );
	if ( ! $session ) {
		wp_die( 'Download session expired or invalid. Please verify your identity again.', 403 );
	}

	$share = sft_get_share( (int) $session['share_id'] );
	if ( ! $share || ! sft_share_is_accessible( $share ) ) {
		wp_die( 'This share link is no longer available.', 403 );
	}

	$file = sft_get_file( $file_id );
	if ( ! $file || (int) $file->vault_id !== (int) $share->vault_id ) {
		wp_die( 'File not found in this vault.', 404 );
	}

	$vault = sft_get_vault( (int) $share->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	sft_increment_download_count( (int) $share->id );
	sft_serve_file( $file, $vault, (int) $share->id, false );
}

// ─── AJAX: request OTP ────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_sft_request_otp', 'sft_ajax_request_otp' );
add_action( 'wp_ajax_sft_request_otp',        'sft_ajax_request_otp' );

function sft_ajax_request_otp(): void {
	check_ajax_referer( 'sft_public_nonce', '_wpnonce' );

	$share_id = (int) ( $_POST['share_id'] ?? 0 );
	$email    = sanitize_email( $_POST['email'] ?? '' );

	if ( ! $share_id || ! $email ) {
		wp_send_json_error( 'Invalid request.' );
	}

	$result = sft_send_otp( $share_id, $email );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'message' => 'Verification code sent.' ] );
}

// ─── AJAX: verify OTP ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_nopriv_sft_verify_otp', 'sft_ajax_verify_otp' );
add_action( 'wp_ajax_sft_verify_otp',        'sft_ajax_verify_otp' );

function sft_ajax_verify_otp(): void {
	check_ajax_referer( 'sft_public_nonce', '_wpnonce' );

	$share_id = (int) ( $_POST['share_id'] ?? 0 );
	$email    = sanitize_email( $_POST['email'] ?? '' );
	$otp      = preg_replace( '/\D/', '', $_POST['otp'] ?? '' );

	if ( ! $share_id || ! $email || strlen( $otp ) !== 6 ) {
		wp_send_json_error( 'Invalid request.' );
	}

	$result = sft_verify_otp_for_share( $share_id, $email, $otp );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$dl_token = sft_create_download_session( $share_id );

	wp_send_json_success( [ 'download_token' => $dl_token ] );
}

// ─── Shortcode: [sft_my_vaults] ───────────────────────────────────────────────

add_shortcode( 'sft_my_vaults', 'sft_render_my_vaults_shortcode' );

function sft_render_my_vaults_shortcode(): string {
	if ( ! sft_user_can_use() ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to manage your secure file vaults.', 'wp-sft-pro' ) . ' '
				. '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p>';
		}
		return '<p>' . esc_html__( 'You do not have permission to access the secure file vault.', 'wp-sft-pro' ) . '</p>';
	}

	$user_id  = get_current_user_id();
	$vaults   = sft_get_user_vaults( $user_id );
	$nonce    = wp_create_nonce( 'sft_user_nonce' );
	$ajax_url = admin_url( 'admin-ajax.php' );

	// Share form global limits (admins are exempt).
	$sc_is_admin        = sft_is_admin();
	$sc_allow_unlim_dl  = get_option( 'sft_allow_unlimited_downloads', '1' ) === '1';
	$sc_default_dl      = (int) get_option( 'sft_default_max_downloads', 0 );
	$sc_dl_ceiling      = (int) get_option( 'sft_max_download_limit', 0 );
	$sc_allow_no_expiry = get_option( 'sft_allow_no_expiry', '1' ) === '1';
	$sc_default_expiry  = (int) get_option( 'sft_default_expiry_days', 0 );
	$sc_max_expiry      = (int) get_option( 'sft_max_expiry_days', 0 );

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
<div class="sft-my-vaults" id="sft-my-vaults">
<style>
.sft-my-vaults *{box-sizing:border-box}
.sft-my-vaults{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1a1a2e;max-width:860px}
.sft-mv-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.sft-mv-header h2{margin:0;font-size:22px}
.sft-mv-btn{display:inline-block;padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:background .15s}
.sft-mv-btn-primary{background:#2271b1;color:#fff}
.sft-mv-btn-primary:hover{background:#135e96;color:#fff}
.sft-mv-btn-danger{background:#fff;color:#d63638;border:1px solid #d63638}
.sft-mv-btn-sm{padding:5px 10px;font-size:12px}
.sft-mv-vault{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:16px}
.sft-mv-vault-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
.sft-mv-vault-title{font-size:17px;font-weight:700;margin:0 0 4px}
.sft-mv-meta{font-size:12px;color:#888;margin:0 0 12px}
.sft-mv-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-left:6px}
.sft-badge-active{background:#d1e7dd;color:#0a3622}
.sft-badge-expired,.sft-badge-revoked{background:#f8d7da;color:#58151c}
.sft-badge-archived{background:#e2e3e5;color:#41464b}
.sft-mv-section{margin-top:12px;padding-top:12px;border-top:1px solid #f0f2f5}
.sft-mv-section h4{margin:0 0 8px;font-size:13px;font-weight:700;text-transform:uppercase;color:#888;letter-spacing:.5px}
.sft-mv-file-list,.sft-mv-share-list{list-style:none;padding:0;margin:0}
.sft-mv-file-list li,.sft-mv-share-list li{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f8f9fa;font-size:13px}
.sft-mv-file-list li:last-child,.sft-mv-share-list li:last-child{border-bottom:none}
.sft-mv-file-name,.sft-mv-share-email{flex:1;word-break:break-all}
.sft-mv-file-size{color:#aaa;font-size:11px;white-space:nowrap}
.sft-mv-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;display:flex;align-items:center;justify-content:center}
.sft-mv-modal{background:#fff;border-radius:10px;padding:28px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;z-index:9999}
.sft-mv-modal h3{margin:0 0 16px;font-size:18px}
.sft-mv-modal label{display:block;font-size:13px;font-weight:600;margin:12px 0 4px}
.sft-mv-modal input,.sft-mv-modal textarea,.sft-mv-modal select{width:100%;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px;font-size:14px}
.sft-mv-modal .sft-mv-actions{display:flex;gap:10px;margin-top:20px}
.sft-mv-alert{padding:8px 12px;border-radius:6px;margin-bottom:12px;font-size:13px}
.sft-mv-alert-error{background:#fef0f0;border:1px solid #f5c6cb;color:#721c24}
.sft-mv-alert-success{background:#d1e7dd;border:1px solid #a3cfbb;color:#0a3622}
.sft-mv-empty{color:#888;font-size:13px;font-style:italic}
</style>

<div class="sft-mv-header">
	<h2>My Secure Vaults</h2>
	<button class="sft-mv-btn sft-mv-btn-primary" onclick="sftOpenNewVaultModal()">+ New Vault</button>
</div>

<div id="sft-mv-notice" style="display:none;margin-bottom:16px;"></div>

<?php if ( ! $vaults ) : ?>
	<p class="sft-mv-empty">You have no vaults yet. Click <strong>New Vault</strong> to create one.</p>
<?php else : ?>
	<?php foreach ( $vaults as $vault ) :
		$files      = sft_get_vault_files( (int) $vault->id );
		$shares     = sft_get_vault_shares( (int) $vault->id );
		$badge_class = 'sft-badge-' . esc_attr( $vault->status );
	?>
	<div class="sft-mv-vault" id="sft-vault-<?php echo (int) $vault->id; ?>">
		<div class="sft-mv-vault-head">
			<div>
				<div class="sft-mv-vault-title">
					<?php echo esc_html( $vault->name ); ?>
					<span class="sft-mv-badge <?php echo $badge_class; ?>"><?php echo esc_html( $vault->status ); ?></span>
				</div>
				<p class="sft-mv-meta">
					Created <?php echo esc_html( gmdate( 'M j, Y', strtotime( $vault->created_at ) ) ); ?>
					<?php if ( $vault->expires_at ) : ?>
						&bull; Expires <?php echo esc_html( gmdate( 'M j, Y', strtotime( $vault->expires_at ) ) ); ?>
					<?php endif; ?>
					&bull; <?php echo count( $files ); ?> file<?php echo count( $files ) !== 1 ? 's' : ''; ?>
				</p>
			</div>
			<div style="display:flex;gap:6px;flex-wrap:wrap">
				<?php if ( $vault->status === 'active' ) : ?>
					<button class="sft-mv-btn sft-mv-btn-primary sft-mv-btn-sm"
					        onclick="sftOpenUploadModal(<?php echo (int) $vault->id; ?>)">Upload File</button>
					<button class="sft-mv-btn sft-mv-btn-sm" style="background:#f0f2f5;color:#2271b1;border:1px solid #d0d5dd;"
					        onclick="sftOpenShareModal(<?php echo (int) $vault->id; ?>)">Share</button>
				<?php endif; ?>
				<button class="sft-mv-btn sft-mv-btn-danger sft-mv-btn-sm"
				        onclick="sftDeleteVault(<?php echo (int) $vault->id; ?>, '<?php echo esc_js( $vault->name ); ?>')">Delete</button>
			</div>
		</div>

		<?php if ( $files ) : ?>
		<div class="sft-mv-section">
			<h4>Files</h4>
			<ul class="sft-mv-file-list">
				<?php foreach ( $files as $f ) : ?>
				<li>
					<span class="sft-mv-file-name"><?php echo esc_html( $f->original_name ); ?></span>
					<span class="sft-mv-file-size"><?php echo esc_html( size_format( $f->file_size ) ); ?></span>
					<button class="sft-mv-btn sft-mv-btn-danger sft-mv-btn-sm"
					        onclick="sftDeleteFile(<?php echo (int) $f->id; ?>, <?php echo (int) $vault->id; ?>)">Remove</button>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( $shares ) : ?>
		<div class="sft-mv-section">
			<h4>Active Shares</h4>
			<ul class="sft-mv-share-list">
				<?php foreach ( $shares as $s ) : ?>
				<li>
					<span class="sft-mv-share-email"><?php echo esc_html( $s->recipient_email ); ?></span>
					<span class="sft-mv-badge sft-badge-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span>
					<span style="color:#aaa;font-size:11px"><?php echo (int) $s->download_count; ?> dl</span>
					<?php if ( in_array( $s->status, [ 'pending', 'active' ], true ) ) : ?>
						<button class="sft-mv-btn sft-mv-btn-danger sft-mv-btn-sm"
						        onclick="sftRevokeShare(<?php echo (int) $s->id; ?>, <?php echo (int) $vault->id; ?>)">Revoke</button>
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
<div id="sft-modal-vault" class="sft-mv-modal-overlay" style="display:none" onclick="sftCloseModal('sft-modal-vault')">
	<div class="sft-mv-modal" onclick="event.stopPropagation()">
		<h3>Create New Vault</h3>
		<div id="sft-vault-modal-error" class="sft-mv-alert sft-mv-alert-error" style="display:none"></div>
		<label>Vault Name *</label>
		<input type="text" id="sft-vault-name" placeholder="e.g. Q1 Financial Reports" maxlength="255">
		<label>Description</label>
		<textarea id="sft-vault-desc" rows="3" placeholder="Optional description..."></textarea>
		<label>Expiry Date (optional)</label>
		<input type="date" id="sft-vault-expires">
		<div class="sft-mv-actions">
			<button class="sft-mv-btn sft-mv-btn-primary" onclick="sftCreateVault()">Create Vault</button>
			<button class="sft-mv-btn" style="background:#f0f2f5;color:#333" onclick="sftCloseModal('sft-modal-vault')">Cancel</button>
		</div>
	</div>
</div>

<!-- Upload File Modal -->
<div id="sft-modal-upload" class="sft-mv-modal-overlay" style="display:none" onclick="sftCloseModal('sft-modal-upload')">
	<div class="sft-mv-modal" onclick="event.stopPropagation()">
		<h3>Upload File to Vault</h3>
		<div id="sft-upload-modal-error" class="sft-mv-alert sft-mv-alert-error" style="display:none"></div>
		<label>Select File (max <?php echo (int) get_option( 'sft_max_file_mb', 50 ); ?> MB)</label>
		<input type="file" id="sft-file-input">
		<div id="sft-upload-progress-wrap" style="display:none;margin-top:12px;">
			<div style="background:#e2e3e5;border-radius:4px;overflow:hidden;height:14px;">
				<div id="sft-upload-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width .2s;"></div>
			</div>
			<p id="sft-upload-progress-label" style="font-size:12px;color:#888;margin:4px 0 0;">Uploading…</p>
		</div>
		<div class="sft-mv-actions">
			<button id="sft-upload-btn" class="sft-mv-btn sft-mv-btn-primary" onclick="sftUploadFile()">Encrypt &amp; Upload</button>
			<button id="sft-upload-cancel-btn" class="sft-mv-btn" style="background:#f0f2f5;color:#333" onclick="sftCloseModal('sft-modal-upload')">Cancel</button>
		</div>
	</div>
</div>

<!-- Share Modal -->
<div id="sft-modal-share" class="sft-mv-modal-overlay" style="display:none" onclick="sftCloseModal('sft-modal-share')">
	<div class="sft-mv-modal" onclick="event.stopPropagation()">
		<h3>Share Vault</h3>
		<div id="sft-share-modal-error" class="sft-mv-alert sft-mv-alert-error" style="display:none"></div>
		<label>Recipient Email *</label>
		<input type="email" id="sft-share-email" placeholder="recipient@example.com">
		<label>
			Download Limit
			<?php echo ( $sc_is_admin || $sc_allow_unlim_dl ) ? '(0 = unlimited)' : ''; ?>
		</label>
		<input type="number" id="sft-share-maxdl" value="<?php echo $sc_default_dl; ?>"
		       min="<?php echo $sc_dl_min; ?>"
		       <?php echo $sc_dl_max > 0 ? 'max="' . $sc_dl_max . '"' : ''; ?>>
		<label>
			Link Expires
			<?php echo $sc_expiry_required ? '<span style="color:#d63638;">*</span>' : '(optional)'; ?>
		</label>
		<input type="date" id="sft-share-expires"
		       min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
		       <?php echo $sc_expiry_required; ?>>
		<div class="sft-mv-actions">
			<button class="sft-mv-btn sft-mv-btn-primary" onclick="sftCreateShare()">Send Invite</button>
			<button class="sft-mv-btn" style="background:#f0f2f5;color:#333" onclick="sftCloseModal('sft-modal-share')">Cancel</button>
		</div>
	</div>
</div>

<script>
var sftUserData = {
	ajaxUrl:    <?php echo wp_json_encode( $ajax_url ); ?>,
	nonce:      <?php echo wp_json_encode( $nonce ); ?>,
	chunkSize:  <?php echo sft_chunk_size_bytes(); ?>,
	activeVaultId: null,
	shareLimits: <?php echo $sc_js_defaults; ?>
};

function sftOpenNewVaultModal() {
	document.getElementById('sft-vault-name').value='';
	document.getElementById('sft-vault-desc').value='';
	document.getElementById('sft-vault-expires').value='';
	sftHideError2('sft-vault-modal-error');
	document.getElementById('sft-modal-vault').style.display='flex';
}
function sftOpenUploadModal(vaultId) {
	sftUserData.activeVaultId = vaultId;
	document.getElementById('sft-file-input').value='';
	document.getElementById('sft-upload-progress-wrap').style.display='none';
	document.getElementById('sft-upload-progress-bar').style.width='0%';
	document.getElementById('sft-upload-btn').disabled=false;
	document.getElementById('sft-upload-cancel-btn').disabled=false;
	sftHideError2('sft-upload-modal-error');
	document.getElementById('sft-modal-upload').style.display='flex';
}
function sftOpenShareModal(vaultId) {
	sftUserData.activeVaultId = vaultId;
	var lim = sftUserData.shareLimits;
	var dlEl = document.getElementById('sft-share-maxdl');
	var exEl = document.getElementById('sft-share-expires');
	document.getElementById('sft-share-email').value = '';
	dlEl.value = lim.defaultDl;
	dlEl.min   = lim.dlMin;
	if (lim.dlMax > 0) { dlEl.max = lim.dlMax; } else { dlEl.removeAttribute('max'); }
	exEl.value = lim.defaultExpiry ? lim.defaultExpiry.substring(0,10) : '';
	if (lim.expiryMax) { exEl.max = lim.expiryMax.substring(0,10); } else { exEl.removeAttribute('max'); }
	if (lim.expiryRequired) { exEl.setAttribute('required',''); } else { exEl.removeAttribute('required'); }
	sftHideError2('sft-share-modal-error');
	document.getElementById('sft-modal-share').style.display='flex';
}
function sftCloseModal(id) { document.getElementById(id).style.display='none'; }

function sftCreateVault() {
	var name    = document.getElementById('sft-vault-name').value.trim();
	var desc    = document.getElementById('sft-vault-desc').value.trim();
	var expires = document.getElementById('sft-vault-expires').value;
	if (!name) { sftShowError2('sft-vault-modal-error','Vault name is required.'); return; }
	sftUserPost({ action:'sft_ajax_create_vault', name:name, desc:desc, expires_at:expires, _wpnonce:sftUserData.nonce })
		.then(function(r) {
			if (r.success) { sftCloseModal('sft-modal-vault'); sftShowNotice('Vault created. Reloading…','success'); setTimeout(function(){ location.reload(); },1200); }
			else { sftShowError2('sft-vault-modal-error', r.data||'Error creating vault.'); }
		});
}

function sftGenerateUploadId() {
	return Array.from(crypto.getRandomValues(new Uint8Array(16)))
		.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
}

async function sftUploadFile() {
	var input = document.getElementById('sft-file-input');
	sftHideError2('sft-upload-modal-error');
	if (!input.files.length) { sftShowError2('sft-upload-modal-error','Please select a file.'); return; }

	var file = input.files[0];
	var btn  = document.getElementById('sft-upload-btn');
	var wrap = document.getElementById('sft-upload-progress-wrap');
	var bar  = document.getElementById('sft-upload-progress-bar');
	var lbl  = document.getElementById('sft-upload-progress-label');

	btn.disabled = true;
	document.getElementById('sft-upload-cancel-btn').disabled = true;
	wrap.style.display = '';

	var CHUNK   = sftUserData.chunkSize;
	var total   = Math.ceil(file.size / CHUNK) || 1;
	var uid     = sftGenerateUploadId();

	try {
		for (var i = 0; i < total; i++) {
			var start = i * CHUNK;
			var fd    = new FormData();
			fd.append('action',       'sft_upload_chunk');
			fd.append('_wpnonce',     sftUserData.nonce);
			fd.append('vault_id',     sftUserData.activeVaultId);
			fd.append('upload_id',    uid);
			fd.append('chunk_index',  i);
			fd.append('total_chunks', total);
			fd.append('file_name',    file.name);
			fd.append('total_size',   file.size);
			fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);

			var r = await fetch(sftUserData.ajaxUrl, {method:'POST', body:fd});
			var j = await r.json();
			if (!j.success) throw new Error(j.data || 'Upload failed.');

			var pct = Math.round((i + 1) / total * 100);
			bar.style.width = pct + '%';
			lbl.textContent = j.data.complete ? 'Encrypting & saving…' : 'Uploading ' + pct + '%…';
		}
		sftCloseModal('sft-modal-upload');
		sftShowNotice('File encrypted and uploaded. Reloading…', 'success');
		setTimeout(function(){ location.reload(); }, 1200);
	} catch(e) {
		btn.disabled = false;
		document.getElementById('sft-upload-cancel-btn').disabled = false;
		wrap.style.display = 'none';
		sftShowError2('sft-upload-modal-error', e.message);
	}
}

function sftCreateShare() {
	var email      = document.getElementById('sft-share-email').value.trim();
	var maxdl      = document.getElementById('sft-share-maxdl').value;
	var expiresRaw = document.getElementById('sft-share-expires').value;
	var expires    = expiresRaw ? expiresRaw + ' 23:59:59' : '';
	if (!email) { sftShowError2('sft-share-modal-error','Recipient email is required.'); return; }
	sftUserPost({ action:'sft_ajax_create_share', vault_id:sftUserData.activeVaultId, email:email, max_downloads:maxdl, expires_at:expires, _wpnonce:sftUserData.nonce })
		.then(function(r) {
			if (r.success) { sftCloseModal('sft-modal-share'); sftShowNotice('Share invite sent to '+email+'.','success'); setTimeout(function(){ location.reload(); },1500); }
			else { sftShowError2('sft-share-modal-error', r.data||'Error creating share.'); }
		});
}

function sftDeleteFile(fileId, vaultId) {
	if (!confirm('Permanently delete this file? This cannot be undone.')) return;
	sftUserPost({ action:'sft_ajax_delete_file', file_id:fileId, vault_id:vaultId, _wpnonce:sftUserData.nonce })
		.then(function(r) {
			if (r.success) { sftShowNotice('File deleted.','success'); setTimeout(function(){ location.reload(); },800); }
			else { sftShowNotice(r.data||'Error deleting file.','error'); }
		});
}

function sftDeleteVault(vaultId, name) {
	if (!confirm('Permanently delete vault "'+name+'" and all its files? This cannot be undone.')) return;
	sftUserPost({ action:'sft_ajax_delete_vault', vault_id:vaultId, _wpnonce:sftUserData.nonce })
		.then(function(r) {
			if (r.success) { sftShowNotice('Vault deleted. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { sftShowNotice(r.data||'Error deleting vault.','error'); }
		});
}

function sftRevokeShare(shareId, vaultId) {
	if (!confirm('Revoke this share? The recipient will immediately lose access.')) return;
	sftUserPost({ action:'sft_ajax_revoke_share', share_id:shareId, vault_id:vaultId, _wpnonce:sftUserData.nonce })
		.then(function(r) {
			if (r.success) { sftShowNotice('Share revoked. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { sftShowNotice(r.data||'Error revoking share.','error'); }
		});
}

function sftUserPost(data) {
	var body = new URLSearchParams();
	Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
	return fetch(sftUserData.ajaxUrl,{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded'}}).then(function(r){return r.json();});
}
function sftShowError2(id, msg) { var el=document.getElementById(id); el.textContent=msg; el.style.display=''; }
function sftHideError2(id) { document.getElementById(id).style.display='none'; }
function sftShowNotice(msg,type) {
	var el=document.getElementById('sft-mv-notice');
	el.className='sft-mv-alert sft-mv-alert-'+(type==='success'?'success':'error');
	el.textContent=msg; el.style.display='';
	setTimeout(function(){ el.style.display='none'; },4000);
}
</script>
</div>
	<?php
	return ob_get_clean();
}

// ─── AJAX: chunked file upload ────────────────────────────────────────────────

add_action( 'wp_ajax_sft_upload_chunk', 'sft_ajax_upload_chunk_handler' );

function sft_ajax_upload_chunk_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'Access denied.' );
	}

	$vault_id      = (int) ( $_POST['vault_id']     ?? 0 );
	$upload_id     = preg_replace( '/[^a-f0-9]/', '', $_POST['upload_id'] ?? '' );
	$chunk_index   = (int) ( $_POST['chunk_index']  ?? 0 );
	$total_chunks  = (int) ( $_POST['total_chunks'] ?? 0 );
	$original_name = sanitize_file_name( $_POST['file_name']   ?? '' );
	$total_size    = (int) ( $_POST['total_size']   ?? 0 );

	if ( ! $upload_id || strlen( $upload_id ) < 8 || ! $original_name
		|| $total_chunks < 1 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
		wp_send_json_error( 'Invalid parameters.' );
	}

	// Validate vault ownership.
	$user_id = get_current_user_id();
	$vault   = sft_get_vault( $vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		wp_send_json_error( 'Vault not found or not active.' );
	}
	if ( (int) $vault->owner_id !== $user_id && ! sft_is_admin() ) {
		wp_send_json_error( 'Access denied.' );
	}

	// Enforce total-size limit early so we fail before writing chunks.
	$max_mb = (int) get_option( 'sft_max_file_mb', 50 );
	if ( $total_size > $max_mb * 1024 * 1024 ) {
		wp_send_json_error( "File exceeds the {$max_mb} MB limit." );
	}

	if ( empty( $_FILES['chunk'] ) || (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'Chunk upload failed — check server upload limits.' );
	}

	// Write chunk to temp directory.
	$chunks_base = sft_ensure_chunks_dir();
	$upload_dir  = $chunks_base . $upload_id . '/';
	if ( ! is_dir( $upload_dir ) ) {
		wp_mkdir_p( $upload_dir );
	}

	if ( ! move_uploaded_file( $_FILES['chunk']['tmp_name'], $upload_dir . $chunk_index . '.part' ) ) {
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

	// Encrypt and store; clean up temp file regardless of outcome.
	$result = sft_encrypt_and_store_file( $vault_id, $assembled, $original_name, $total_size, $user_id );
	@unlink( $assembled );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'file_id' => $result, 'complete' => true ] );
}

// ─── AJAX: authenticated user vault actions ───────────────────────────────────

add_action( 'wp_ajax_sft_ajax_create_vault',  'sft_ajax_create_vault_handler' );
add_action( 'wp_ajax_sft_ajax_upload_file',   'sft_ajax_upload_file_handler' );
add_action( 'wp_ajax_sft_ajax_create_share',  'sft_ajax_create_share_handler' );
add_action( 'wp_ajax_sft_ajax_delete_file',   'sft_ajax_delete_file_handler' );
add_action( 'wp_ajax_sft_ajax_delete_vault',  'sft_ajax_delete_vault_handler' );
add_action( 'wp_ajax_sft_ajax_revoke_share',  'sft_ajax_revoke_share_handler' );

function sft_ajax_create_vault_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id    = get_current_user_id();
	$name       = sanitize_text_field( $_POST['name'] ?? '' );
	$desc       = sanitize_textarea_field( $_POST['desc'] ?? '' );
	$expires_at = sanitize_text_field( $_POST['expires_at'] ?? '' );

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

	$vault_id = sft_create_vault( $user_id, $name, $desc, $expires_mysql );

	if ( ! $vault_id ) {
		wp_send_json_error( 'Failed to create vault.' );
	}

	wp_send_json_success( [ 'vault_id' => $vault_id ] );
}

function sft_ajax_upload_file_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
	$vault    = sft_get_vault( $vault_id );

	if ( ! $vault || (int) $vault->owner_id !== $user_id ) {
		// Admins may also upload to any vault.
		if ( ! sft_is_admin() || ! $vault ) {
			wp_send_json_error( 'Vault not found or access denied.' );
		}
	}

	if ( empty( $_FILES['sft_file'] ) ) {
		wp_send_json_error( 'No file received.' );
	}

	$result = sft_upload_file_to_vault( $vault_id, $_FILES['sft_file'], $user_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'file_id' => $result ] );
}

function sft_ajax_create_share_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id       = get_current_user_id();
	$vault_id      = (int) ( $_POST['vault_id'] ?? 0 );
	$email         = sanitize_email( $_POST['email'] ?? '' );
	$max_downloads = max( 0, (int) ( $_POST['max_downloads'] ?? 0 ) );
	$expires_at    = sanitize_text_field( $_POST['expires_at'] ?? '' );

	$vault = sft_get_vault( $vault_id );
	if ( ! $vault || (int) $vault->owner_id !== $user_id ) {
		if ( ! sft_is_admin() || ! $vault ) {
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

	$result = sft_create_share( $vault_id, $user_id, $email, $max_downloads, $expires_mysql );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [ 'share_id' => $result ] );
}

function sft_ajax_delete_file_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$file_id  = (int) ( $_POST['file_id'] ?? 0 );
	$file     = sft_get_file( $file_id );

	if ( ! $file ) {
		wp_send_json_error( 'File not found.' );
	}

	$vault = sft_get_vault( (int) $file->vault_id );
	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! sft_is_admin() ) ) {
		wp_send_json_error( 'Access denied.' );
	}

	sft_delete_file( $file_id, $user_id );
	wp_send_json_success();
}

function sft_ajax_delete_vault_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
	$vault    = sft_get_vault( $vault_id );

	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! sft_is_admin() ) ) {
		wp_send_json_error( 'Vault not found or access denied.' );
	}

	sft_delete_vault( $vault_id );
	wp_send_json_success();
}

function sft_ajax_revoke_share_handler(): void {
	check_ajax_referer( 'sft_user_nonce', '_wpnonce' );

	if ( ! sft_user_can_use() ) {
		wp_send_json_error( 'You do not have permission to use the vault.' );
	}

	$user_id  = get_current_user_id();
	$share_id = (int) ( $_POST['share_id'] ?? 0 );
	$share    = sft_get_share( $share_id );

	if ( ! $share ) {
		wp_send_json_error( 'Share not found.' );
	}

	$vault = sft_get_vault( (int) $share->vault_id );
	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! sft_is_admin() ) ) {
		wp_send_json_error( 'Access denied.' );
	}

	sft_revoke_share( $share_id, $user_id );
	wp_send_json_success();
}
