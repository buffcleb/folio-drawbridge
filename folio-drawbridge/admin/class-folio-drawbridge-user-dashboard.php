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

	wp_register_style( 'folio-drawbridge-user-dash', false, [], FOLIO_DRAWBRIDGE_VERSION );
	wp_enqueue_style( 'folio-drawbridge-user-dash' );

	// Reuse the same shared CSS variables as the admin panel plus a few extras.
	wp_add_inline_style( 'folio-drawbridge-user-dash', '
		.folio-drawbridge-btn { background:#fff; border:1px solid #ccd0d4; padding:5px 12px; border-radius:4px;
		           cursor:pointer; font-size:12px; color:#2271b1; text-decoration:none; display:inline-block; }
		.folio-drawbridge-btn:hover { background:#f0f6fb; }
		.folio-drawbridge-danger { color:#d63638; border-color:#d63638; }
		.folio-drawbridge-danger:hover { background:#fef0f0; }
		.folio-drawbridge-primary { background:#2271b1; color:#fff; border-color:#2271b1; }
		.folio-drawbridge-primary:hover { background:#135e96; color:#fff; }
		.folio-drawbridge-card { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:20px; }
		.folio-drawbridge-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
		.folio-drawbridge-badge-active  { background:#d1e7dd; color:#0a3622; }
		.folio-drawbridge-badge-expired,.folio-drawbridge-badge-revoked,.folio-drawbridge-badge-limit_reached { background:#f8d7da; color:#58151c; }
		.folio-drawbridge-badge-archived,.folio-drawbridge-badge-pending { background:#e2e3e5; color:#41464b; }
		.folio-drawbridge-table { width:100%; border-collapse:collapse; }
		.folio-drawbridge-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #ddd; font-size:12px; }
		.folio-drawbridge-table td { padding:8px 10px; border-bottom:1px solid #f0f2f5; font-size:13px; vertical-align:middle; }
		.folio-drawbridge-table tr:hover td { background:#f9fafc; }
		.folio-drawbridge-form-row { margin-bottom:12px; }
		.folio-drawbridge-form-row label { display:block; font-weight:600; font-size:13px; margin-bottom:4px; }
		.folio-drawbridge-form-row input[type=text],
		.folio-drawbridge-form-row input[type=email],
		.folio-drawbridge-form-row input[type=date],
		.folio-drawbridge-form-row input[type=number],
		.folio-drawbridge-form-row input[type=datetime-local],
		.folio-drawbridge-form-row textarea,
		.folio-drawbridge-form-row select { width:100%; padding:7px 10px; border:1px solid #d0d5dd; border-radius:4px; font-size:13px; }
		.folio-drawbridge-form-actions { margin-top:16px; display:flex; gap:8px; }
		.folio-drawbridge-notice-success { background:#d1e7dd; border-left:4px solid #0a3622; padding:10px 14px; border-radius:4px; margin-top:15px; font-size:13px; }
		.folio-drawbridge-notice-error   { background:#f8d7da; border-left:4px solid #d63638; padding:10px 14px; border-radius:4px; margin-top:15px; font-size:13px; }

		/* ── Sortable columns ── */
		.folio-drawbridge-table th a { text-decoration:none; color:inherit; white-space:nowrap; }
		.folio-drawbridge-table th[data-sortable] { cursor:pointer; user-select:none; }
		.folio-drawbridge-sort-ind { font-size:10px; color:#bbb; margin-left:3px; }
		.folio-drawbridge-sort-ind.active { color:#2271b1; }

		/* ── Pagination ── */
		.folio-drawbridge-pagination { display:flex; align-items:center; justify-content:center; gap:4px; margin-top:16px; }
		.folio-drawbridge-pagination a, .folio-drawbridge-pagination span { display:inline-flex; align-items:center; justify-content:center;
		    min-width:32px; height:32px; padding:0 8px; border:1px solid #ccd0d4; border-radius:6px;
		    font-size:13px; text-decoration:none; color:#2271b1; background:#fff; }
		.folio-drawbridge-pagination .current { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
		.folio-drawbridge-pagination a:hover { background:#f0f6fb; }
		.folio-drawbridge-pagination .dots { border:none; background:none; color:#999; }
	' );

	add_action( 'admin_head', 'folio_drawbridge_user_dashboard_inline_js' );
}

function folio_drawbridge_user_dashboard_inline_js(): void {
	if ( sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) !== 'folio-drawbridge-vaults' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page gate for inline JS.
		return;
	}
	?>
	<script>
	function folioDrawbridgeSortTable(tableId) {
		var tbl = document.getElementById(tableId);
		if (!tbl) return;
		var headers = tbl.querySelectorAll('thead th');
		headers.forEach(function(th, colIdx) {
			if (th.dataset.nosort !== undefined) return;
			th.style.cursor = 'pointer';
			th.style.userSelect = 'none';
			var ind = document.createElement('span');
			ind.className = 'folio-drawbridge-sort-ind';
			ind.textContent = ' ↕';
			th.appendChild(ind);
			var asc = true;
			th.addEventListener('click', function() {
				headers.forEach(function(h) {
					var i = h.querySelector('.folio-drawbridge-sort-ind');
					if (i) { i.textContent = ' ↕'; i.classList.remove('active'); }
				});
				ind.textContent = asc ? ' ↑' : ' ↓';
				ind.classList.add('active');

				var tbody = tbl.querySelector('tbody') || tbl;
				var allRows = Array.from(tbody.querySelectorAll('tr'));
				var groups = [];
				allRows.forEach(function(row) {
					if (row.dataset.subrow !== undefined) {
						if (groups.length) groups[groups.length - 1].sub.push(row);
					} else {
						groups.push({ row: row, sub: [] });
					}
				});

				groups.sort(function(a, b) {
					var ca = a.row.cells[colIdx] ? a.row.cells[colIdx].textContent.trim() : '';
					var cb = b.row.cells[colIdx] ? b.row.cells[colIdx].textContent.trim() : '';
					var na = parseFloat(ca.replace(/[^0-9.\-]/g, ''));
					var nb = parseFloat(cb.replace(/[^0-9.\-]/g, ''));
					if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
					return asc ? ca.localeCompare(cb) : cb.localeCompare(ca);
				});

				groups.forEach(function(g) {
					tbody.appendChild(g.row);
					g.sub.forEach(function(s) { tbody.appendChild(s); });
				});

				asc = !asc;
			});
		});
	}
	</script>
	<?php
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
		$notice['message'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-composed HTML; user-supplied parts are escaped by folio_drawbridge_set_notice() callers before storage.
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
