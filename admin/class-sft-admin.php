<?php
/**
 * Admin panel — menu registration, asset enqueueing, POST handler, and tab dispatcher.
 *
 * The admin panel is restricted to users with `manage_options`. It provides:
 *   Dashboard  — vault/share/file stats at a glance
 *   Vaults     — browse every user's vaults; click into a vault to see its files,
 *                shares, and audit trail; admins can download any file (logged)
 *   Audit Log  — filterable, paginated event log for all plugin activity
 *   Settings   — OTP TTL, max file size, audit retention, and data deletion policy
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Load tab renderers ───────────────────────────────────────────────────────

require_once SFT_DIR . 'admin/tabs/tab-dashboard.php';
require_once SFT_DIR . 'admin/tabs/tab-vaults.php';
require_once SFT_DIR . 'admin/tabs/tab-audit.php';
require_once SFT_DIR . 'admin/tabs/tab-settings.php';
require_once SFT_DIR . 'admin/tabs/tab-users.php';

// ─── POST handler (admin_init — before any HTML output) ───────────────────────

add_action( 'admin_init', 'sft_handle_admin_post' );

function sft_handle_admin_post(): void {
	if ( ! isset( $_POST['sft_nonce'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sft-pro' ) {
		return;
	}
	if ( ! sft_is_admin() ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-sft-pro' ) );
	}

	check_admin_referer( 'sft_admin_action', 'sft_nonce' );

	$current_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	// ── Settings save ────────────────────────────────────────────────────────
	if ( isset( $_POST['sft_save_settings'] ) ) {
		// Two-factor.
		$otp_ttl          = max( 5, min( 60, (int) ( $_POST['sft_otp_ttl_minutes'] ?? 15 ) ) );
		$otp_max_attempts = max( 1, min( 20, (int) ( $_POST['sft_otp_max_attempts'] ?? 5 ) ) );

		// Download limits.
		$allow_unlimited_downloads = isset( $_POST['sft_allow_unlimited_downloads'] ) ? '1' : '0';
		$default_max_downloads     = max( 0, (int) ( $_POST['sft_default_max_downloads'] ?? 0 ) );
		$max_download_limit        = max( 0, (int) ( $_POST['sft_max_download_limit'] ?? 0 ) );

		// Link expiration.
		$allow_no_expiry     = isset( $_POST['sft_allow_no_expiry'] ) ? '1' : '0';
		$default_expiry_days = max( 0, (int) ( $_POST['sft_default_expiry_days'] ?? 0 ) );
		$max_expiry_days     = max( 0, (int) ( $_POST['sft_max_expiry_days'] ?? 0 ) );

		// File uploads.
		$max_file_mb = max( 1, (int) ( $_POST['sft_max_file_mb'] ?? 50 ) );

		// Audit log retention.
		$prune_enabled       = isset( $_POST['sft_audit_prune_enabled'] ) ? '1' : '0';
		$prune_days          = max( 30, (int) ( $_POST['sft_audit_prune_days'] ?? 365 ) );

		// Data & privacy.
		$delete_on_uninstall = isset( $_POST['sft_delete_on_uninstall'] ) ? '1' : '0';

		// SIEM logging.
		$siem_enabled    = isset( $_POST['sft_siem_enabled'] ) ? '1' : '0';
		$siem_log_path   = sanitize_text_field( $_POST['sft_siem_log_path'] ?? '' );
		$siem_path_error = '';
		if ( $siem_log_path !== '' ) {
			// Require absolute path and block path-traversal sequences.
			if ( ! path_is_absolute( $siem_log_path ) || strpos( $siem_log_path, '..' ) !== false ) {
				$siem_log_path   = get_option( 'sft_siem_log_path', '' ); // keep previous value
				$siem_path_error = 'SIEM log path must be an absolute path with no ".." segments. Previous value retained.';
			}
		}
		$siem_format   = in_array( $_POST['sft_siem_format'] ?? 'json', [ 'json', 'csv' ], true )
			? sanitize_key( $_POST['sft_siem_format'] )
			: 'json';

		update_option( 'sft_otp_ttl_minutes',            $otp_ttl );
		update_option( 'sft_otp_max_attempts',            $otp_max_attempts );
		update_option( 'sft_allow_unlimited_downloads',   $allow_unlimited_downloads );
		update_option( 'sft_default_max_downloads',       $default_max_downloads );
		update_option( 'sft_max_download_limit',          $max_download_limit );
		update_option( 'sft_allow_no_expiry',             $allow_no_expiry );
		update_option( 'sft_default_expiry_days',         $default_expiry_days );
		update_option( 'sft_max_expiry_days',             $max_expiry_days );
		update_option( 'sft_max_file_mb',                 $max_file_mb );
		update_option( 'sft_audit_prune_enabled',         $prune_enabled );
		update_option( 'sft_audit_prune_days',            $prune_days );
		update_option( 'sft_delete_on_uninstall',         $delete_on_uninstall );
		update_option( 'sft_siem_enabled',                $siem_enabled );
		update_option( 'sft_siem_log_path',               $siem_log_path );
		update_option( 'sft_siem_format',                 $siem_format );

		sft_log( SFT_EVT_SETTINGS_SAVED, null, null, [
			'otp_ttl_minutes'  => $otp_ttl,
			'max_file_mb'      => $max_file_mb,
			'otp_max_attempts' => $otp_max_attempts,
		] );

		$notice = 'Settings saved.';
		if ( isset( $_POST['sft_apply_to_existing_dl'] ) || isset( $_POST['sft_apply_to_existing_expiry'] ) ) {
			$enforced = sft_enforce_share_limits();
			if ( $enforced > 0 ) {
				$notice .= sprintf(
					' <strong>%d</strong> existing share%s updated to match the new limits.',
					$enforced,
					$enforced === 1 ? '' : 's'
				);
			}
		}
		if ( $siem_path_error ) {
			$notice .= ' ' . $siem_path_error;
		}

		sft_set_notice( $notice, $siem_path_error ? 'warning' : 'success' );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Enforce share limits on existing shares ───────────────────────────────
	if ( isset( $_POST['sft_enforce_share_limits'] ) ) {
		$updated = sft_enforce_share_limits();
		sft_set_notice(
			sprintf( 'Share limits enforced. <strong>%d</strong> share%s updated.', $updated, $updated === 1 ? '' : 's' ),
			'success'
		);
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Manual audit prune ───────────────────────────────────────────────────
	if ( isset( $_POST['sft_manual_prune'] ) ) {
		$days    = max( 1, (int) ( $_POST['sft_prune_days_manual'] ?? 365 ) );
		$deleted = sft_prune_audit_log( $days );
		sft_set_notice( sprintf( 'Pruned <strong>%d</strong> audit log entr%s older than %d days.', $deleted, $deleted === 1 ? 'y' : 'ies', $days ), 'success' );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: revoke share ──────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_revoke_share'] ) ) {
		$share_id = (int) ( $_POST['share_id'] ?? 0 );
		if ( $share_id ) {
			sft_revoke_share( $share_id, get_current_user_id() );
			sft_set_notice( 'Share revoked.', 'success' );
		}
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: change vault status ───────────────────────────────────────────
	if ( isset( $_POST['sft_admin_vault_status'] ) ) {
		$vault_id   = (int) ( $_POST['vault_id'] ?? 0 );
		$new_status = sanitize_key( $_POST['new_status'] ?? '' );
		if ( $vault_id && $new_status ) {
			sft_update_vault_status( $vault_id, $new_status, get_current_user_id() );
			sft_set_notice( 'Vault status updated to <strong>' . esc_html( $new_status ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: delete vault ───────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_delete_vault'] ) ) {
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		if ( $vault_id ) {
			sft_delete_vault( $vault_id );
			sft_set_notice( 'Vault permanently deleted.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: delete file ────────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_delete_file'] ) ) {
		$file_id  = (int) ( $_POST['file_id'] ?? 0 );
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		if ( $file_id ) {
			sft_delete_file( $file_id, get_current_user_id() );
			sft_set_notice( 'File deleted.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Grant vault (User) access ────────────────────────────────────────────
	if ( isset( $_POST['sft_grant_user'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'use_sft_vaults', true );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'grant_vault_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( 'Vault access granted to <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Grant SFT Admin access ───────────────────────────────────────────────
	if ( isset( $_POST['sft_grant_sft_admin'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'sft_admin', true );
			$user->add_cap( 'use_sft_vaults', true );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'grant_sft_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( 'SFT Admin access granted to <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Promote vault user to SFT Admin ─────────────────────────────────────
	if ( isset( $_POST['sft_promote_sft_admin'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'sft_admin', true );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'promote_to_sft_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( '<strong>' . esc_html( $user->display_name ) . '</strong> promoted to SFT Admin.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Demote SFT Admin to vault user ──────────────────────────────────────
	if ( isset( $_POST['sft_demote_sft_admin'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( 'sft_admin' );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'demote_sft_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( '<strong>' . esc_html( $user->display_name ) . '</strong> demoted to Vault User.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Revoke all SFT access from a user ────────────────────────────────────
	if ( isset( $_POST['sft_revoke_user'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( 'use_sft_vaults' );
			$user->remove_cap( 'sft_admin' );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'revoke_all_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( 'All SFT access revoked for <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: edit vault expiry ─────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_edit_vault_expiry'] ) ) {
		$vault_id   = (int) ( $_POST['vault_id'] ?? 0 );
		$raw_date   = sanitize_text_field( $_POST['vault_new_expires'] ?? '' );
		$expires_at = $raw_date ? $raw_date . ' 23:59:59' : '';
		if ( $vault_id ) {
			sft_update_vault_expiry( $vault_id, $expires_at, get_current_user_id() );
			sft_set_notice( $expires_at ? 'Vault expiry updated.' : 'Vault expiry cleared.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: edit share ────────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_edit_share'] ) ) {
		$share_id      = (int) ( $_POST['share_id'] ?? 0 );
		$vault_id      = (int) ( $_POST['vault_id'] ?? 0 );
		$max_downloads = max( 0, (int) ( $_POST['share_max_downloads'] ?? 0 ) );
		$raw_date      = sanitize_text_field( $_POST['share_new_expires'] ?? '' );
		$expires_at    = $raw_date ? $raw_date . ' 23:59:59' : '';
		if ( $share_id ) {
			sft_update_share( $share_id, $max_downloads, $expires_at, get_current_user_id() );
			sft_set_notice( 'Share updated.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}
}

// ─── Menu registration ────────────────────────────────────────────────────────

add_action( 'admin_menu', 'sft_register_admin_menu' );

function sft_register_admin_menu(): void {
	$hook = add_menu_page(
		'WP Secure File Transfer Pro',
		'Secure Transfer',
		'sft_admin',
		'sft-pro',
		'sft_admin_page',
		'dashicons-lock',
		80
	);

	add_action( "load-{$hook}", 'sft_register_admin_help_tabs' );
}

// ─── Contextual help ──────────────────────────────────────────────────────────

function sft_register_admin_help_tabs(): void {
	$screen = get_current_screen();
	$tab    = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	switch ( $tab ) {

		case 'dashboard':
			$screen->add_help_tab( [
				'id'      => 'sft-dash-overview',
				'title'   => 'Dashboard Overview',
				'content' =>
					'<p>The Dashboard gives you a real-time summary of everything happening across all vaults.</p>' .
					'<ul>' .
					'<li><strong>Active Vaults</strong> — vaults currently in the active state.</li>' .
					'<li><strong>Encrypted Files / Total Size</strong> — all files stored across every vault.</li>' .
					'<li><strong>Active Shares</strong> — share links that are pending or active and not yet expired.</li>' .
					'<li><strong>Total Downloads</strong> — cumulative download count across all shares.</li>' .
					'<li><strong>OTP Failures</strong> — failed two-factor verification attempts in the last 30 days. Elevated counts may indicate a brute-force attempt.</li>' .
					'<li><strong>Audit Events</strong> — total rows in the audit log.</li>' .
					'</ul>' .
					'<p>The <strong>7-Day Download Activity</strong> sparkline shows daily download volume so you can spot unusual spikes.</p>' .
					'<p>The <strong>Recent Activity</strong> table lists the 10 most recent audit events site-wide.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-dash-security',
				'title'   => 'Security Status',
				'content' =>
					'<p>The Security Status card at the bottom of the Dashboard shows the current state of key security controls:</p>' .
					'<ul>' .
					'<li><strong>Key Source</strong> — <em>wp-config.php constant</em> is the most secure option. If it shows <em>database</em>, use the Settings tab to generate a key and move it to wp-config.php.</li>' .
					'<li><strong>Algorithm</strong> — AES-256-CBC with a unique IV per file.</li>' .
					'<li><strong>OTP TTL</strong> — how long a verification code is valid before it expires.</li>' .
					'<li><strong>Storage Path</strong> — where encrypted files are written. The directory should be .htaccess-protected.</li>' .
					'<li><strong>Cron</strong> — confirms the lifecycle cron job is scheduled. If it shows as missing, deactivate and reactivate the plugin.</li>' .
					'</ul>',
			] );
			break;

		case 'vaults':
			$screen->add_help_tab( [
				'id'      => 'sft-vaults-browse',
				'title'   => 'Browsing Vaults',
				'content' =>
					'<p>The Vaults tab lists every vault created on this site, across all users.</p>' .
					'<p>Use the <strong>filter panel</strong> on the left to narrow the list by status (active, expired, revoked, archived) or by searching the vault name. Use the filter URL to bookmark a specific filtered view.</p>' .
					'<p>Click any sortable column header (Name, Status, Created, Expires) to sort the list. Click again to reverse direction. Sort state is preserved in the URL.</p>' .
					'<p>Click a vault name or the <strong>Inspect</strong> button to open the vault inspector, which shows all files, shares, and the vault\'s own audit trail.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-vaults-actions',
				'title'   => 'Vault Actions',
				'content' =>
					'<p>Inside the vault inspector you can:</p>' .
					'<ul>' .
					'<li><strong>Download any file</strong> — admin downloads are fully decrypted on the fly and logged in the audit trail.</li>' .
					'<li><strong>Delete a file</strong> — permanently removes the encrypted file from disk and the database.</li>' .
					'<li><strong>Edit a share</strong> — update the download limit or expiry date on a pending or active share without revoking and recreating it.</li>' .
					'<li><strong>Revoke a share</strong> — immediately blocks the recipient from accessing the vault, even if they have an active download session.</li>' .
					'<li><strong>Edit vault expiry</strong> — change or clear the vault\'s expiry date inline.</li>' .
					'<li><strong>Change vault status</strong> — set a vault to active, expired, revoked, or archived. Non-active vaults cannot be shared or uploaded to.</li>' .
					'<li><strong>Delete vault</strong> — permanently removes all files, shares, and the vault record. This cannot be undone.</li>' .
					'</ul>' .
					'<p>All tables inside the inspector are sortable by clicking column headers. All admin actions are recorded in the audit log with your user account and IP address.</p>',
			] );
			break;

		case 'audit':
			$screen->add_help_tab( [
				'id'      => 'sft-audit-filter',
				'title'   => 'Filtering Events',
				'content' =>
					'<p>The Audit Log records every security-relevant action taken by users, recipients, and the system.</p>' .
					'<p>Use the filter panel to narrow results by:</p>' .
					'<ul>' .
					'<li><strong>Event Type</strong> — choose a specific event such as File Downloaded, OTP Verification Failed, or Share Revoked.</li>' .
					'<li><strong>Vault ID</strong> — show only events for a specific vault (the ID is visible in the Vaults tab URL).</li>' .
					'<li><strong>From / To</strong> — restrict the date range.</li>' .
					'<li><strong>Search Details</strong> — case-insensitive keyword search across the event detail data (e.g. an email address, file name, or status value).</li>' .
					'</ul>' .
					'<p>Dates and times are displayed in the site\'s configured timezone (Settings → General).</p>' .
					'<p>Click any sortable column header (Event, Vault, Share, Actor, Date/Time) to sort the results. Sort direction and all active filters are preserved in the URL, so you can bookmark specific views or share them with colleagues.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-audit-export',
				'title'   => 'Exporting & Pruning',
				'content' =>
					'<p><strong>Export to CSV</strong> downloads the current filtered result set as a CSV file. The export respects all active filters, so you can export a targeted subset of events.</p>' .
					'<p><strong>Manual Prune</strong> permanently deletes all audit entries older than the number of days you specify. Use this to comply with data-retention policies or to keep the table size manageable.</p>' .
					'<p>Automatic pruning can also be configured in the <strong>Settings</strong> tab under Audit Log Retention — it runs hourly via WP-Cron.</p>',
			] );
			break;

		case 'users':
			$screen->add_help_tab( [
				'id'      => 'sft-users-roles',
				'title'   => 'Access Roles',
				'content' =>
					'<p>There are two levels of access below WordPress administrator:</p>' .
					'<ul>' .
					'<li><strong>SFT Admin</strong> — full access to the Secure Transfer admin panel: all tabs, the vault inspector, audit log export, settings, and the Users tab. Does not require WordPress administrator privileges.</li>' .
					'<li><strong>Vault User</strong> — access to <strong>My Vaults</strong> only. Can create vaults, upload and delete files, create and revoke share links, and view their own activity log. Has no visibility into other users\' vaults or any admin panel tabs.</li>' .
					'</ul>' .
					'<p>WordPress administrators (<em>manage_options</em>) always have full SFT Admin access implicitly and do not appear in either list.</p>' .
					'<p>Columns in both tables are sortable by clicking the column header.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-users-grant',
				'title'   => 'Granting & Promoting',
				'content' =>
					'<p>Search for any non-administrator user by their WordPress username or email address. The search panel shows the user\'s current SFT status and presents contextual action buttons:</p>' .
					'<ul>' .
					'<li><strong>Grant Vault Access</strong> — gives the user Vault User access. They immediately see <strong>My Vaults</strong> in their wp-admin sidebar.</li>' .
					'<li><strong>Grant SFT Admin Access</strong> — gives the user full SFT Admin access without promoting them to WordPress administrator.</li>' .
					'<li><strong>Promote to SFT Admin</strong> — upgrades an existing Vault User to SFT Admin.</li>' .
					'</ul>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-users-revoke',
				'title'   => 'Demoting & Revoking',
				'content' =>
					'<p>Actions available from the SFT Admins and Vault Users tables:</p>' .
					'<ul>' .
					'<li><strong>Demote to User</strong> — removes SFT Admin access but retains Vault User access. The user keeps their vaults.</li>' .
					'<li><strong>Revoke</strong> / <strong>Remove All</strong> — removes all SFT access (both capabilities). Existing vaults and files are preserved; the user simply cannot log in to manage them. Administrators can still inspect the vaults from the Vaults tab.</li>' .
					'</ul>' .
					'<p>All grant, promote, demote, and revoke actions are recorded in the audit log.</p>',
			] );
			break;

		case 'settings':
			$screen->add_help_tab( [
				'id'      => 'sft-settings-twofactor',
				'title'   => 'Two-Factor Verification',
				'content' =>
					'<p>These settings control the one-time code (OTP) sent to share recipients as the second factor of authentication before they can download files.</p>' .
					'<p><strong>OTP Validity</strong> — how many minutes a verification code remains valid after it is emailed. Shorter values reduce the window of opportunity if an email is intercepted; longer values are more forgiving if email delivery is slow. Range: 5–60 minutes.</p>' .
					'<p><strong>Max Verification Attempts</strong> — the number of incorrect codes a recipient can enter before the code is invalidated and they must request a new one. Lower values reduce brute-force risk. Range: 1–10.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-dl-limits',
				'title'   => 'Download Limits',
				'content' =>
					'<p>These settings cap how many times a single share link can be used to download files. All limits apply only to non-administrator users — administrators are always exempt.</p>' .
					'<ul>' .
					'<li><strong>Allow Unlimited Downloads</strong> — when unchecked, every share must be given a finite download count. Users cannot leave this field blank.</li>' .
					'<li><strong>Default Download Limit</strong> — the value pre-filled in the share creation form. Set to 0 for no pre-fill (useful when unlimited is allowed and most shares are intended to be unlimited).</li>' .
					'<li><strong>Maximum Download Limit</strong> — the hard ceiling users cannot exceed when entering a limit. Set to 0 to impose no ceiling. This does not affect shares set to unlimited when unlimited is permitted.</li>' .
					'</ul>' .
					'<p>When you change these values, a checkbox appears offering to retroactively apply the new limits to existing active and pending shares that currently exceed them. Shares already within the limits and administrator shares are always skipped.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-expiration',
				'title'   => 'Link Expiration',
				'content' =>
					'<p>These settings control when share links automatically expire. All limits apply only to non-administrator users — administrators are always exempt.</p>' .
					'<ul>' .
					'<li><strong>Allow No Expiry</strong> — when unchecked, every share must be given an expiration date. Users cannot leave this field blank.</li>' .
					'<li><strong>Default Expiry</strong> — days from today pre-filled in the share creation form. Set to 0 for no pre-fill.</li>' .
					'<li><strong>Maximum Expiry</strong> — the furthest-out expiration date a user can set, expressed as days from today. Set to 0 for no ceiling.</li>' .
					'</ul>' .
					'<p>Expiry is always enforced at end-of-day (23:59:59 UTC) on the selected date.</p>' .
					'<p>When you change these values, a checkbox appears offering to retroactively apply the new limits to existing active and pending shares that currently exceed them.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-uploads',
				'title'   => 'File Uploads',
				'content' =>
					'<p><strong>Maximum File Size</strong> — the plugin-level ceiling on uploaded files, in megabytes.</p>' .
					'<p>Unlike a standard WordPress file upload, this plugin splits files into small chunks on the client before sending them to the server. Each chunk is sized to fit within your server\'s <code>upload_max_filesize</code> and <code>post_max_size</code> PHP limits, and the chunks are reassembled into the complete file server-side. This means the plugin-level maximum can safely <strong>exceed</strong> those server limits — for example, you can accept 2 GB files even if <code>upload_max_filesize</code> is set to 8M.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-siem',
				'title'   => 'SIEM Logging',
				'content' =>
					'<p>When enabled, every audit event is appended to a log file on the server in addition to being stored in the database. This allows external security information and event management (SIEM) tools such as Splunk, Datadog, or the ELK stack to ingest plugin activity in real time.</p>' .
					'<p><strong>Log File Path</strong> — the absolute path to the log file. The directory must exist and the web server process must have write permission. The file is created automatically on first write.</p>' .
					'<p><strong>Log Format</strong></p>' .
					'<ul>' .
					'<li><em>JSON</em> — one JSON object per line (NDJSON / JSON Lines format). Each line is a complete, self-contained event and can be streamed directly into most log aggregators.</li>' .
					'<li><em>CSV</em> — a comma-separated file with a header row written once when the file is first created. Suitable for ingestion into spreadsheet tools or systems that prefer flat tabular data.</li>' .
					'</ul>' .
					'<p>Both formats include: timestamp (UTC), event type, vault ID, share ID, actor ID, IP address, event details, and site URL.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-audit-retention',
				'title'   => 'Audit Log Retention',
				'content' =>
					'<p>The audit log grows over time. These settings help manage its size.</p>' .
					'<p><strong>Auto-Prune</strong> — when enabled, the hourly WP-Cron lifecycle job automatically deletes audit entries older than the configured retention window. Useful for compliance with data-retention policies.</p>' .
					'<p><strong>Retention Window</strong> — entries older than this many days are deleted when auto-prune runs. Minimum 30 days.</p>' .
					'<p>You can also prune manually at any time from the <strong>Audit Log</strong> tab using the Manual Prune panel in the filter sidebar. The manual prune respects the same day threshold but runs immediately rather than waiting for cron.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-key',
				'title'   => 'Encryption Key',
				'content' =>
					'<p>The master encryption key is the root secret from which every vault\'s unique per-vault encryption key is derived. All files are encrypted with AES-256-CBC. The key must be a 64-character hexadecimal string (32 raw bytes).</p>' .
					'<p>The most secure configuration is to define the key as a PHP constant in <code>wp-config.php</code> so it is never stored in the database:</p>' .
					'<pre><code>define( \'SFT_MASTER_KEY\', \'your-64-hex-char-key\' );</code></pre>' .
					'<p>Use the <strong>Generate New Key</strong> button to produce a cryptographically secure key server-side. The key is shown once and never stored by the plugin — copy it immediately into <code>wp-config.php</code>.</p>' .
					'<p><strong>Warning:</strong> Replacing an existing key will permanently break decryption of all files already uploaded. Only generate a new key on a fresh installation with no uploaded files.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-data',
				'title'   => 'Data & Privacy / Storage',
				'content' =>
					'<p><strong>Delete all plugin data on uninstall</strong> — when checked, removing the plugin from the Plugins screen permanently drops all five database tables, deletes all encrypted files from disk, and removes all plugin options and transients. This is irreversible. Leave unchecked if you want to preserve data across a reinstall.</p>' .
					'<p><strong>Encrypted file storage</strong> — shows the directory where encrypted vault files are written (<code>wp-content/uploads/sft-vaults/</code>). The directory is protected by an <code>.htaccess</code> file that blocks direct HTTP access. Files are never served directly — all downloads go through PHP, which decrypts them on the fly.</p>' .
					'<p>The storage status indicator confirms whether the directory exists, is protected by <code>.htaccess</code>, and is writable by the web server.</p>',
			] );
			break;
	}

	$screen->set_help_sidebar(
		'<p><strong>WP Secure File Transfer Pro</strong></p>' .
		'<p>Version ' . SFT_VERSION . '</p>' .
		'<hr>' .
		'<p>Encrypted vault storage with two-factor external sharing and full audit logging.</p>'
	);
}

// ─── Admin asset enqueueing ───────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'sft_enqueue_admin_assets' );

function sft_enqueue_admin_assets( string $hook ): void {
	if ( $hook !== 'toplevel_page_sft-pro' ) {
		return;
	}

	// Admin file download (multipart) — handled inline; we also need to handle
	// admin vault file serving via a direct AJAX action.
	add_action( 'admin_head', 'sft_admin_inline_js' );

	wp_register_style( 'sft-admin', false );
	wp_enqueue_style( 'sft-admin' );

	wp_add_inline_style( 'sft-admin', '
		/* ── Buttons ── */
		.sft-btn { background:#fff; border:1px solid #ccd0d4; padding:5px 12px; border-radius:4px;
		           cursor:pointer; font-size:12px; color:#2271b1; text-decoration:none; display:inline-block; }
		.sft-btn:hover { background:#f0f6fb; color:#2271b1; }
		.sft-danger { color:#d63638; border-color:#d63638; }
		.sft-danger:hover { background:#fef0f0; }
		.sft-primary { background:#2271b1; color:#fff; border-color:#2271b1; }
		.sft-primary:hover { background:#135e96; color:#fff; }

		/* ── Cards ── */
		.sft-card { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:20px; }

		/* ── Stat cards (dashboard) ── */
		.sft-stats { display:flex; gap:16px; flex-wrap:wrap; margin-top:20px; }
		.sft-stat { flex:1; min-width:130px; background:#fff; border:1px solid #ccd0d4;
		            border-radius:4px; padding:16px; text-align:center; }
		.sft-stat-num { font-size:32px; font-weight:700; line-height:1.2; color:#2271b1; }
		.sft-stat-label { font-size:12px; color:#666; margin-top:4px; }

		/* ── Status badges ── */
		.sft-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
		.sft-badge-active  { background:#d1e7dd; color:#0a3622; }
		.sft-badge-expired,.sft-badge-revoked { background:#f8d7da; color:#58151c; }
		.sft-badge-archived,.sft-badge-pending { background:#e2e3e5; color:#41464b; }

		/* ── Tables ── */
		.sft-table { width:100%; border-collapse:collapse; }
		.sft-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #ddd; font-size:12px; }
		.sft-table td { padding:8px 10px; border-bottom:1px solid #f0f2f5; font-size:13px; vertical-align:middle; }
		.sft-table tr:hover td { background:#f9fafc; }

		/* ── Vault inspector header ── */
		.sft-vault-inspector { margin-top:20px; }
		.sft-vault-inspector h2 { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
		.sft-vault-meta { color:#888; font-size:13px; margin:0 0 16px; }

		/* ── Pagination ── */
		.sft-pagination { display:flex; align-items:center; justify-content:center; gap:4px; margin-top:16px; }
		.sft-pagination a, .sft-pagination span { display:inline-flex; align-items:center; justify-content:center;
		    min-width:32px; height:32px; padding:0 8px; border:1px solid #ccd0d4; border-radius:6px;
		    font-size:13px; text-decoration:none; color:#2271b1; background:#fff; transition:background .15s; }
		.sft-pagination .current { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
		.sft-pagination a:hover { background:#f0f6fb; }
		.sft-pagination .dots { border:none; background:none; color:#999; }

		/* ── Filter panel ── */
		.sft-filter-wrap { display:flex; gap:20px; align-items:flex-start; margin-top:20px; }
		.sft-filter-panel { flex:0 0 220px; }
		.sft-filter-body { flex:1; min-width:0; }

		/* ── Sortable columns ── */
		.sft-table th a { text-decoration:none; color:inherit; white-space:nowrap; }
		.sft-table th[data-sortable] { cursor:pointer; user-select:none; }
		.sft-sort-ind { font-size:10px; color:#bbb; margin-left:3px; }
		.sft-sort-ind.active { color:#2271b1; }
	' );
}

function sft_admin_inline_js(): void {
	?>
	<script>
	function sftAdminDownload(fileId) {
		var url = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>'
			+ '?action=sft_admin_download&file_id=' + fileId
			+ '&_wpnonce=' + encodeURIComponent('<?php echo wp_create_nonce( 'sft_admin_download' ); ?>');
		window.location.href = url;
	}

	/**
	 * Client-side table sort. Keeps data-subrow rows paired with their parent.
	 * Call after DOM ready: sftSortTable('my-table-id')
	 */
	function sftSortTable(tableId) {
		var tbl = document.getElementById(tableId);
		if (!tbl) return;
		var headers = tbl.querySelectorAll('thead th');
		headers.forEach(function(th, colIdx) {
			if (th.dataset.nosort !== undefined) return;
			th.style.cursor = 'pointer';
			th.style.userSelect = 'none';
			var ind = document.createElement('span');
			ind.className = 'sft-sort-ind';
			ind.textContent = ' ↕';
			th.appendChild(ind);
			var asc = true;
			th.addEventListener('click', function() {
				// Reset all indicators.
				headers.forEach(function(h) {
					var i = h.querySelector('.sft-sort-ind');
					if (i) { i.textContent = ' ↕'; i.classList.remove('active'); }
				});
				ind.textContent = asc ? ' ↑' : ' ↓';
				ind.classList.add('active');

				// Collect primary rows (not data-subrow), each with its trailing sub-rows.
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

				// Sort groups by cell text, numeric if possible.
				groups.sort(function(a, b) {
					var ca = a.row.cells[colIdx] ? a.row.cells[colIdx].textContent.trim() : '';
					var cb = b.row.cells[colIdx] ? b.row.cells[colIdx].textContent.trim() : '';
					var na = parseFloat(ca.replace(/[^0-9.\-]/g, ''));
					var nb = parseFloat(cb.replace(/[^0-9.\-]/g, ''));
					if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
					return asc ? ca.localeCompare(cb) : cb.localeCompare(ca);
				});

				// Re-append rows in sorted order.
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

// ─── Admin file download ──────────────────────────────────────────────────────

add_action( 'wp_ajax_sft_admin_download', 'sft_ajax_admin_download' );

function sft_ajax_admin_download(): void {
	if ( ! sft_is_admin() ) {
		wp_die( 'Access denied.', 403 );
	}

	check_ajax_referer( 'sft_admin_download', '_wpnonce' );

	$file_id = (int) ( $_GET['file_id'] ?? 0 );
	$file    = sft_get_file( $file_id );

	if ( ! $file ) {
		wp_die( 'File not found.', 404 );
	}

	$vault = sft_get_vault( (int) $file->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	// Log admin vault access before serving.
	sft_log( SFT_EVT_ADMIN_VAULT_ACCESS, (int) $vault->id, null,
		[ 'file_id' => $file_id, 'original_name' => $file->original_name ],
		get_current_user_id()
	);

	sft_serve_file( $file, $vault, null, true );
}

// ─── Encryption key preview generator ────────────────────────────────────────

add_action( 'wp_ajax_sft_generate_key_preview', 'sft_ajax_generate_key_preview' );

function sft_ajax_generate_key_preview(): void {
	if ( ! sft_is_admin() ) {
		wp_send_json_error( 'Access denied.', 403 );
	}

	check_ajax_referer( 'sft_generate_key_preview', '_wpnonce' );

	// Generate 32 cryptographically secure random bytes → 64-char hex string.
	// Never stored — only returned for the admin to copy into wp-config.php.
	$key = bin2hex( random_bytes( 32 ) );

	wp_send_json_success( [ 'key' => $key ] );
}

// ─── Admin notice helpers ─────────────────────────────────────────────────────

function sft_set_notice( string $message, string $type = 'success' ): void {
	set_transient( 'sft_admin_notice_' . get_current_user_id(), compact( 'message', 'type' ), 30 );
}

function sft_show_notice(): void {
	$key    = 'sft_admin_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	$class = match ( $notice['type'] ) {
		'error'   => 'notice-error',
		'warning' => 'notice-warning',
		default   => 'notice-success',
	};
	printf(
		'<div class="notice %s is-dismissible" style="margin-top:15px;"><p>%s</p></div>',
		esc_attr( $class ),
		$notice['message'] // pre-escaped at set time
	);
}

// ─── Sortable column header helper ───────────────────────────────────────────

/**
 * Renders a server-side sortable <th> element.
 *
 * @param string $label       Column label.
 * @param string $col         orderby key sent in the URL.
 * @param string $cur_col     Current active orderby value.
 * @param string $cur_order   Current sort direction (ASC|DESC).
 * @param array  $url_args    Base query args (page, tab, filters, etc.) merged into the sort URL.
 * @param bool   $nosort      Pass true to render a plain unsortable <th>.
 */
function sft_sortable_th( string $label, string $col, string $cur_col, string $cur_order, array $url_args, bool $nosort = false ): string {
	if ( $nosort ) {
		return '<th>' . esc_html( $label ) . '</th>';
	}
	$active    = $cur_col === $col;
	$new_order = ( $active && $cur_order === 'ASC' ) ? 'DESC' : 'ASC';
	$url       = add_query_arg( array_merge( $url_args, [ 'orderby' => $col, 'order' => $new_order ] ), admin_url( 'admin.php' ) );
	$indicator = $active
		? '<span class="sft-sort-ind" style="color:#2271b1;"> ' . ( $cur_order === 'ASC' ? '↑' : '↓' ) . '</span>'
		: '<span class="sft-sort-ind"> ↕</span>';
	return '<th><a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;white-space:nowrap;">'
		. esc_html( $label ) . $indicator . '</a></th>';
}

// ─── Shared pagination helper ─────────────────────────────────────────────────

function sft_render_pagination( int $current, int $total_pages, array $extra_args = [] ): void {
	if ( $total_pages <= 1 ) {
		return;
	}

	$base = array_merge( [ 'page' => 'sft-pro' ], $extra_args );

	echo '<div class="sft-pagination">';

	if ( $current > 1 ) {
		$url = add_query_arg( array_merge( $base, [ 'paged' => $current - 1 ] ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '">&laquo;</a>';
	} else {
		echo '<span class="dots">&laquo;</span>';
	}

	for ( $p = 1; $p <= $total_pages; $p++ ) {
		$near  = abs( $p - $current ) <= 2;
		$edge  = $p === 1 || $p === $total_pages;
		if ( ! $near && ! $edge ) {
			if ( $p === 2 || $p === $total_pages - 1 ) {
				echo '<span class="dots">…</span>';
			}
			continue;
		}
		if ( $p === $current ) {
			echo '<span class="current">' . $p . '</span>';
		} else {
			$url = add_query_arg( array_merge( $base, [ 'paged' => $p ] ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '">' . $p . '</a>';
		}
	}

	if ( $current < $total_pages ) {
		$url = add_query_arg( array_merge( $base, [ 'paged' => $current + 1 ] ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '">&raquo;</a>';
	} else {
		echo '<span class="dots">&raquo;</span>';
	}

	echo '</div>';
}

// ─── Main admin page callback ─────────────────────────────────────────────────

function sft_admin_page(): void {
	if ( ! sft_is_admin() ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-sft-pro' ) );
	}

	$current_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	sft_show_notice();

	echo '<div class="wrap"><h1>WP Secure File Transfer Pro</h1>';
	echo '<h2 class="nav-tab-wrapper">';

	$tabs = [
		'dashboard' => 'Dashboard',
		'vaults'    => 'Vaults',
		'audit'     => 'Audit Log',
		'users'     => 'Users',
		'settings'  => 'Settings',
	];

	foreach ( $tabs as $slug => $label ) {
		$url   = add_query_arg( [ 'page' => 'sft-pro', 'tab' => $slug ], admin_url( 'admin.php' ) );
		$class = $current_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
	}

	echo '</h2>';

	switch ( $current_tab ) {
		case 'vaults':
			sft_render_tab_vaults();
			break;
		case 'audit':
			sft_render_tab_audit();
			break;
		case 'users':
			sft_render_tab_users();
			break;
		case 'settings':
			sft_render_tab_settings();
			break;
		default:
			sft_render_tab_dashboard();
	}

	echo '</div>';
}
