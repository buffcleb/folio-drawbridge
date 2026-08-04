<?php
/**
 * User dashboard — wp-admin panel scoped to the current user's own vaults.
 *
 * Registered as a top-level menu item visible to any user with the
 * folio_drawbridge_use_vaults capability (or manage_options). All queries are owner-scoped
 * so a user can never see or modify another user's data.
 *
 * Uses traditional form-submit + redirect (admin_init POST handler) rather
 * than AJAX, matching the main admin panel's pattern.
 *
 * Views:
 *   Default (?page=folio-drawbridge-vaults)            — vault list + create form
 *   Detail  (?page=folio-drawbridge-vaults&vault_id=N) — files, shares, vault audit log
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/user-views/view-vault-list.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/user-views/view-vault-detail.php';

// ─── Menu registration ────────────────────────────────────────────────────────

add_action( 'admin_menu', 'folio_drawbridge_register_user_dashboard_menu' );

function folio_drawbridge_register_user_dashboard_menu(): void {
	// Show to any user who can use vaults (including admins via folio_drawbridge_user_can_use).
	if ( ! folio_drawbridge_user_can_use() ) {
		return;
	}

	$hook = add_menu_page(
		'My Vaults',
		'My Vaults',
		'read', // WordPress minimum; our own capability check is in the callback.
		'folio-drawbridge-vaults',
		'folio_drawbridge_user_dashboard_page',
		'dashicons-portfolio',
		81
	);

	add_action( "load-{$hook}", 'folio_drawbridge_register_user_dashboard_help_tabs' );
}

// ─── Contextual help ──────────────────────────────────────────────────────────

function folio_drawbridge_register_user_dashboard_help_tabs(): void {
	$screen   = get_current_screen();
	$vault_id = absint( wp_unslash( $_GET['vault_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.

	if ( $vault_id > 0 ) {
		// Vault detail view.
		$screen->add_help_tab( [
			'id'      => 'folio-drawbridge-ud-files',
			'title'   => 'Files',
			'content' =>
				'<p>The <strong>Files</strong> section lists every file stored in this vault.</p>' .
				'<p>To add a file, click <strong>Encrypt &amp; Upload</strong>. The file is encrypted on the server before being written to disk — the original is never stored. Files can be up to ' . (int) get_option( 'folio_drawbridge_max_file_mb', 50 ) . ' MB.</p>' .
				'<p>To remove a file, click <strong>Delete</strong> next to it. Deletion is permanent and cannot be undone. The encrypted file is removed from the server immediately.</p>',
		] );
		$screen->add_help_tab( [
			'id'      => 'folio-drawbridge-ud-shares',
			'title'   => 'Shares',
			'content' =>
				'<p>A share gives an external recipient access to all files in this vault via a two-step verification process.</p>' .
				'<p><strong>How sharing works:</strong></p>' .
				'<ol>' .
				'<li>Enter the recipient\'s email address and click <strong>Send Invite</strong>. An invitation email is sent with a unique link.</li>' .
				'<li>The recipient opens the link and enters their email address to receive a one-time verification code.</li>' .
				'<li>They enter the code to confirm their identity and gain access to download the files.</li>' .
				'</ol>' .
				'<p><strong>Download Limit</strong> — set to 0 for unlimited, or enter a number to cap how many times the recipient may collect this vault.</p>' .'<p>One download is counted per successful verification, not per file: after entering their code the recipient can retrieve every file in the vault — individually or as a ZIP — for the life of that session. A limit of 1 therefore means they may collect the vault once.</p>' .
				'<p><strong>Link Expires</strong> — optional date and time after which the share link stops working. Leave blank for no expiry (if your administrator permits it).</p>' .
				'<p>To remove a recipient\'s access at any time, click <strong>Revoke</strong>. Revocation is immediate.</p>',
		] );
		$screen->add_help_tab( [
			'id'      => 'folio-drawbridge-ud-activity',
			'title'   => 'Activity Log',
			'content' =>
				'<p>The <strong>Activity Log</strong> shows the 20 most recent events for this vault — uploads, downloads, share creation, OTP verifications, and more.</p>' .
				'<p>Each entry records the event type, any relevant details, the IP address of the actor, and the date and time in the site\'s configured timezone. This log is read-only and cannot be edited or deleted by users.</p>' .
				'<p>Click any column header to sort. Your site administrator can view the full audit log across all vaults at <strong>Folio Drawbridge → Audit Log</strong>.</p>',
		] );
	} else {
		// Vault list view.
		$screen->add_help_tab( [
			'id'      => 'folio-drawbridge-ud-vaults',
			'title'   => 'Your Vaults',
			'content' =>
				'<p>A <strong>vault</strong> is an encrypted container you can fill with files and then share securely with people outside this site.</p>' .
				'<p>The vault list shows all of your vaults along with their status, file count, share count, creation date, and expiry date (if set).</p>' .
				'<p>Click any sortable column header (Name, Status, Created, Expires) to sort the list. Click again to reverse direction.</p>' .
				'<p>Click a vault name or the <strong>Open</strong> button to manage its files, shares, and activity log.</p>' .
				'<p><strong>Vault statuses:</strong></p>' .
				'<ul>' .
				'<li><em>Active</em> — files can be uploaded and shares can be created.</li>' .
				'<li><em>Expired</em> — the vault has passed its expiry date. Existing shares stop working. No new uploads or shares are possible.</li>' .
				'<li><em>Archived</em> — manually closed by an administrator. Behaves like expired.</li>' .
				'</ul>',
		] );
		$screen->add_help_tab( [
			'id'      => 'folio-drawbridge-ud-create',
			'title'   => 'Creating a Vault',
			'content' =>
				'<p>Use the <strong>Create New Vault</strong> form at the bottom of the page to set up a new vault.</p>' .
				'<ul>' .
				'<li><strong>Vault Name</strong> (required) — a short label to identify the vault, e.g. "Q1 Financial Reports" or "Onboarding Pack – Jane Smith".</li>' .
				'<li><strong>Description</strong> (optional) — a note visible only to you and administrators, to help remember the vault\'s purpose.</li>' .
				'<li><strong>Expiry Date</strong> (optional) — the date after which the vault and all its share links automatically stop working. Leave blank for no expiry.</li>' .
				'</ul>' .
				'<p>After the vault is created you will be taken straight to its detail page where you can upload files and create share links.</p>' .
				'<p><strong>Tip:</strong> A <em>My Vaults</em> summary widget on your WordPress dashboard home screen shows your vault counts and the last five activity events at a glance.</p>',
		] );
	}

	$screen->set_help_sidebar(
		'<p><strong>My Vaults</strong></p>' .
		'<p>Secure encrypted file storage with two-factor sharing.</p>' .
		'<hr>' .
		'<p>Contact your site administrator if you need access changes or have questions about a specific vault.</p>'
	);
}

// ─── Asset enqueueing ─────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'folio_drawbridge_enqueue_user_dashboard_assets' );

function folio_drawbridge_enqueue_user_dashboard_assets( string $hook ): void {
	if ( $hook !== 'toplevel_page_folio-drawbridge-vaults' ) {
		return;
	}

	wp_enqueue_style(
		'folio-drawbridge-user-dash',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'admin/css/user-dashboard.css',
		[],
		FOLIO_DRAWBRIDGE_VERSION
	);

	// Shared table sorting and the inline-edit toggles.
	wp_enqueue_script(
		'folio-drawbridge-admin',
		FOLIO_DRAWBRIDGE_PLUGIN_URL . 'admin/js/admin.js',
		[],
		FOLIO_DRAWBRIDGE_VERSION,
		true
	);

	// The chunked-upload client is only needed on a vault's detail view.
	$vault_id = absint( wp_unslash( $_GET['vault_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.
	if ( $vault_id > 0 ) {
		wp_enqueue_script(
			'folio-drawbridge-vault-detail',
			FOLIO_DRAWBRIDGE_PLUGIN_URL . 'admin/js/vault-detail.js',
			[ 'folio-drawbridge-admin' ],
			FOLIO_DRAWBRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'folio-drawbridge-vault-detail',
			'folioDrawbridgeUd',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'folio_drawbridge_user_nonce' ),
				'vaultId'   => $vault_id,
				'chunkSize' => folio_drawbridge_chunk_size_bytes(),
			]
		);
	}
}


// ─── POST handler ─────────────────────────────────────────────────────────────

add_action( 'admin_init', 'folio_drawbridge_handle_user_dashboard_post' );

function folio_drawbridge_handle_user_dashboard_post(): void {
	if ( ! isset( $_POST['folio_drawbridge_user_nonce'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'folio-drawbridge-vaults' ) {
		return;
	}
	if ( ! folio_drawbridge_user_can_use() ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'folio-drawbridge' ) );
	}

	check_admin_referer( 'folio_drawbridge_user_dashboard_action', 'folio_drawbridge_user_nonce' );

	$user_id  = get_current_user_id();
	$vault_id = absint( wp_unslash( $_GET['vault_id'] ?? $_POST['vault_id'] ?? 0 ) );

	// Helper: verify the vault belongs to this user (or user is admin).
	$assert_vault_owner = function( int $vid ) use ( $user_id ): object {
		$vault = folio_drawbridge_get_vault( $vid );
		if ( ! $vault ) {
			wp_die( 'Vault not found.' );
		}
		if ( (int) $vault->owner_id !== $user_id && ! folio_drawbridge_is_admin() ) {
			wp_die( 'Access denied.' );
		}
		return $vault;
	};

	$list_url   = add_query_arg( [ 'page' => 'folio-drawbridge-vaults' ], admin_url( 'admin.php' ) );
	$detail_url = fn( int $vid ) => add_query_arg( [ 'page' => 'folio-drawbridge-vaults', 'vault_id' => $vid ], admin_url( 'admin.php' ) );

	// ── Create vault ─────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_create_vault'] ) ) {
		$name    = sanitize_text_field( wp_unslash( $_POST['vault_name'] ?? '' ) );
		$desc    = sanitize_textarea_field( wp_unslash( $_POST['vault_desc'] ?? '' ) );
		$expires = sanitize_text_field( wp_unslash( $_POST['vault_expires'] ?? '' ) );

		if ( ! $name ) {
			folio_drawbridge_ud_set_notice( 'Vault name is required.', 'error' );
			wp_safe_redirect( $list_url );
			exit;
		}

		$expires_mysql = '';
		if ( $expires ) {
			$ts = strtotime( $expires );
			if ( $ts ) {
				$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$new_id = folio_drawbridge_create_vault( $user_id, $name, $desc, $expires_mysql );
		if ( $new_id ) {
			folio_drawbridge_ud_set_notice( 'Vault <strong>' . esc_html( $name ) . '</strong> created.', 'success' );
			wp_safe_redirect( $detail_url( $new_id ) );
		} else {
			folio_drawbridge_ud_set_notice( 'Could not create vault. Please try again.', 'error' );
			wp_safe_redirect( $list_url );
		}
		exit;
	}

	// ── Delete vault ─────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_delete_vault'] ) ) {
		$vault = $assert_vault_owner( $vault_id );
		folio_drawbridge_delete_vault( $vault_id );
		folio_drawbridge_ud_set_notice( 'Vault <strong>' . esc_html( $vault->name ) . '</strong> deleted.', 'success' );
		wp_safe_redirect( $list_url );
		exit;
	}

	// ── Upload file ───────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_upload_file'] ) ) {
		$assert_vault_owner( $vault_id );

		if ( empty( $_FILES['folio_drawbridge_upload']['name'] ) ) {
			folio_drawbridge_ud_set_notice( 'No file selected.', 'error' );
			wp_safe_redirect( $detail_url( $vault_id ) );
			exit;
		}

		$result = folio_drawbridge_upload_file_to_vault( $vault_id, $_FILES['folio_drawbridge_upload'], $user_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES entry is validated (error code) and its name sanitized inside folio_drawbridge_upload_file_to_vault().

		if ( is_wp_error( $result ) ) {
			folio_drawbridge_ud_set_notice( $result->get_error_message(), 'error' );
		} else {
			folio_drawbridge_ud_set_notice( 'File encrypted and uploaded.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Delete file ───────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_delete_file'] ) ) {
		$file_id = absint( wp_unslash( $_POST['file_id'] ?? 0 ) );
		$file    = folio_drawbridge_get_file( $file_id );
		if ( $file ) {
			$assert_vault_owner( (int) $file->vault_id );
			folio_drawbridge_delete_file( $file_id, $user_id );
			folio_drawbridge_ud_set_notice( 'File deleted.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Create share ──────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_create_share'] ) ) {
		$assert_vault_owner( $vault_id );

		$email        = sanitize_email( wp_unslash( $_POST['share_email'] ?? '' ) );
		$max_dl       = max( 0, absint( wp_unslash( $_POST['share_max_downloads'] ?? 0 ) ) );
		$expires       = sanitize_text_field( wp_unslash( $_POST['share_expires'] ?? '' ) );
		$expires_mysql = '';
		if ( $expires ) {
			$ts = strtotime( $expires . ' 23:59:59' );
			if ( $ts ) {
				$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$result = folio_drawbridge_create_share( $vault_id, $user_id, $email, $max_dl, $expires_mysql );

		if ( is_wp_error( $result ) ) {
			folio_drawbridge_ud_set_notice( $result->get_error_message(), 'error' );
		} else {
			folio_drawbridge_ud_set_notice( 'Share invite sent to <strong>' . esc_html( $email ) . '</strong>.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Revoke share ──────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_revoke_share'] ) ) {
		$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		$share    = folio_drawbridge_get_share( $share_id );
		if ( $share ) {
			$assert_vault_owner( (int) $share->vault_id );
			folio_drawbridge_revoke_share( $share_id, $user_id );
			folio_drawbridge_ud_set_notice( 'Share revoked.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Resend share invite ───────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_resend_share'] ) ) {
		$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		$share    = folio_drawbridge_get_share( $share_id );
		if ( $share ) {
			$assert_vault_owner( (int) $share->vault_id );
			$result = folio_drawbridge_resend_share_invite( $share_id, $user_id );
			if ( is_wp_error( $result ) ) {
				folio_drawbridge_ud_set_notice( 'Could not resend invite: ' . esc_html( $result->get_error_message() ), 'error' );
			} else {
				folio_drawbridge_ud_set_notice( 'Invite email resent to ' . esc_html( $share->recipient_email ) . '.', 'success' );
			}
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Edit share (download limit + expiry) ──────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_edit_share'] ) ) {
		$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		$share    = folio_drawbridge_get_share( $share_id );
		if ( $share ) {
			$assert_vault_owner( (int) $share->vault_id );
			$max_dl  = max( 0, absint( wp_unslash( $_POST['share_max_downloads'] ?? 0 ) ) );
			$expires = sanitize_text_field( wp_unslash( $_POST['share_new_expires'] ?? '' ) );
			$expires_mysql = '';
			if ( $expires ) {
				$ts = strtotime( $expires . ' 23:59:59' );
				if ( $ts ) {
					$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
				}
			}
			folio_drawbridge_update_share( $share_id, $max_dl, $expires_mysql, $user_id );
			folio_drawbridge_ud_set_notice( 'Share updated.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	// ── Edit vault expiry ─────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_ud_edit_vault_expiry'] ) ) {
		$assert_vault_owner( $vault_id );
		$expires = sanitize_text_field( wp_unslash( $_POST['vault_new_expires'] ?? '' ) );
		$expires_mysql = '';
		if ( $expires ) {
			$ts = strtotime( $expires . ' 23:59:59' );
			if ( $ts ) {
				$expires_mysql = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		folio_drawbridge_update_vault_expiry( $vault_id, $expires_mysql, $user_id );
		folio_drawbridge_ud_set_notice( 'Vault expiry updated.', 'success' );
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}

	if ( isset( $_POST['folio_drawbridge_ud_edit_vault_meta'] ) ) {
		$assert_vault_owner( $vault_id );
		$name        = sanitize_text_field( wp_unslash( $_POST['vault_new_name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['vault_new_description'] ?? '' ) );
		$result      = folio_drawbridge_update_vault_meta( $vault_id, $name, $description, $user_id );
		if ( is_wp_error( $result ) ) {
			folio_drawbridge_ud_set_notice( $result->get_error_message(), 'error' );
		} else {
			folio_drawbridge_ud_set_notice( 'Vault updated.', 'success' );
		}
		wp_safe_redirect( $detail_url( $vault_id ) );
		exit;
	}
}

// ─── Notice helpers ───────────────────────────────────────────────────────────

function folio_drawbridge_ud_set_notice( string $message, string $type = 'success' ): void {
	set_transient( 'folio_drawbridge_ud_notice_' . get_current_user_id(), compact( 'message', 'type' ), 30 );
}

function folio_drawbridge_ud_show_notice(): void {
	$key    = 'folio_drawbridge_ud_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	printf(
		'<div class="folio-drawbridge-notice-%s" style="margin-top:15px;"><p>%s</p></div>',
		esc_attr( $notice['type'] ),
		wp_kses( $notice['message'], folio_drawbridge_notice_allowed_html() )
	);
}

// ─── Main page callback ───────────────────────────────────────────────────────

function folio_drawbridge_user_dashboard_page(): void {
	if ( ! folio_drawbridge_user_can_use() ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'folio-drawbridge' ) );
	}

	$vault_id = absint( wp_unslash( $_GET['vault_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.

	echo '<div class="wrap"><h1>My Vaults</h1>';

	folio_drawbridge_ud_show_notice();

	if ( $vault_id > 0 ) {
		folio_drawbridge_render_user_vault_detail( $vault_id );
	} else {
		folio_drawbridge_render_user_vault_list();
	}

	echo '</div>';
}
