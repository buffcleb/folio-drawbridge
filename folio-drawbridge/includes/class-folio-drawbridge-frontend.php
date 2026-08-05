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
		// Registered here rather than unconditionally on wp_enqueue_scripts: that
		// hook fires from inside wp_head(), which this page does call, so assets
		// still print — but enqueueing globally would put share-page CSS on every
		// front-end request.
		add_action( 'wp_enqueue_scripts', 'folio_drawbridge_enqueue_share_assets' );
		folio_drawbridge_render_share_page( sanitize_text_field( $share_token ) );
		exit;
	}

	if ( $file_id ) {
		folio_drawbridge_handle_file_download( (int) $file_id );
		exit;
	}
}

/**
 * Loads the share page's stylesheet and script.
 *
 * The page builds its own document but calls wp_head() and wp_footer(), so the
 * normal enqueue pipeline works. Data the script cannot derive is localised;
 * the download-session token is not among it, because it only exists after the
 * recipient passes OTP verification and arrives in that AJAX response.
 */
function folio_drawbridge_enqueue_share_assets(): void {
	wp_enqueue_style(
		'folio-drawbridge-share',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'public/css/share.css',
		[],
		FOLIO_DRAWBRIDGE_VERSION
	);

	wp_enqueue_script(
		'folio-drawbridge-share',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'public/js/share.js',
		[],
		FOLIO_DRAWBRIDGE_VERSION,
		true
	);

	$share = folio_drawbridge_get_share_by_token( sanitize_text_field( (string) get_query_var( 'folio_drawbridge_share' ) ) );

	wp_localize_script(
		'folio-drawbridge-share',
		'folioDrawbridgeData',
		[
			'ajaxUrl'  => folio_drawbridge_root_relative_url( admin_url( 'admin-ajax.php' ) ),
			'homeBase' => folio_drawbridge_root_relative_url( home_url( '/' ) ),
			'nonce'    => wp_create_nonce( 'folio_drawbridge_public_nonce' ),
			'shareId'  => $share ? (int) $share->id : 0,
		]
	);
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
</head>
<body>
<div class="folio-drawbridge-wrap">
<div class="folio-drawbridge-logo"><a href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
<?php
}

function folio_drawbridge_share_page_footer(): void {
	?>
<?php
	// Only the site's own identity appears here. Guideline 10 requires any
	// "Powered By" credit on a public-facing page to be optional and to default
	// to not showing, and a recipient gains nothing from being told which plugin
	// served the page — whereas knowing whose site sent them the files is real
	// context for deciding whether to trust the download.
	?>
<div class="folio-drawbridge-footer"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></div>
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

	// Enqueued from inside the shortcode so the assets load only on pages that
	// actually render it. WordPress prints assets requested this late in the
	// footer, which is where this script wants to be anyway.
	wp_enqueue_style(
		'folio-drawbridge-vaults',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'public/css/vaults.css',
		[],
		FOLIO_DRAWBRIDGE_VERSION
	);

	wp_enqueue_script(
		'folio-drawbridge-vaults',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'public/js/vaults.js',
		[],
		FOLIO_DRAWBRIDGE_VERSION,
		true
	);

	wp_localize_script(
		'folio-drawbridge-vaults',
		'folioDrawbridgeUserData',
		[
			'ajaxUrl'      => $ajax_url,
			'nonce'        => $nonce,
			'chunkSize'    => folio_drawbridge_chunk_size_bytes(),
			// Share-form limits, pre-resolved so the form can apply them without
			// another request.
			'shareLimits'  => [
				'defaultDl'      => $sc_default_dl,
				'dlMin'          => $sc_dl_min,
				'dlMax'          => $sc_dl_max,
				'expiryRequired' => (bool) $sc_expiry_required,
				'defaultExpiry'  => $sc_default_expiry > 0 ? gmdate( 'Y-m-d\TH:i', strtotime( "+{$sc_default_expiry} days" ) ) : '',
				'expiryMax'      => $sc_expiry_max_ts > 0 ? gmdate( 'Y-m-d\TH:i', $sc_expiry_max_ts ) : '',
			],
		]
	);

	ob_start();
	?>
<div class="folio-drawbridge-vaults" id="folio-drawbridge-vaults">

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
