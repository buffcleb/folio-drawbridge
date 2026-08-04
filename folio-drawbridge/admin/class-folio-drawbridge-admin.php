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
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Load tab renderers ───────────────────────────────────────────────────────

require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/tabs/tab-dashboard.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/tabs/tab-vaults.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/tabs/tab-audit.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/tabs/tab-settings.php';
require_once FOLIO_DRAWBRIDGE_PLUGIN_DIR . 'admin/tabs/tab-users.php';

// ─── POST handler (admin_init — before any HTML output) ───────────────────────

add_action( 'admin_init', 'folio_drawbridge_handle_admin_post' );

function folio_drawbridge_handle_admin_post(): void {
	if ( ! isset( $_POST['folio_drawbridge_nonce'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'folio-drawbridge' ) {
		return;
	}
	if ( ! folio_drawbridge_is_admin() ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'folio-drawbridge' ) );
	}

	check_admin_referer( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' );

	$current_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector for rendering.

	// ── Settings save ────────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_save_settings'] ) ) {
		// Two-factor.
		$otp_ttl          = max( 5, min( 60, absint( wp_unslash( $_POST['folio_drawbridge_otp_ttl_minutes'] ?? 15 ) ) ) );
		$otp_max_attempts = max( 1, min( 20, absint( wp_unslash( $_POST['folio_drawbridge_otp_max_attempts'] ?? 5 ) ) ) );
		$otp_cooldown     = max( 0, min( 300, absint( wp_unslash( $_POST['folio_drawbridge_otp_cooldown_seconds'] ?? 60 ) ) ) );

		// Download limits.
		$allow_unlimited_downloads = isset( $_POST['folio_drawbridge_allow_unlimited_downloads'] ) ? '1' : '0';
		$default_max_downloads     = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_default_max_downloads'] ?? 0 ) ) );
		$max_download_limit        = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_max_download_limit'] ?? 0 ) ) );

		// Link expiration.
		$allow_no_expiry     = isset( $_POST['folio_drawbridge_allow_no_expiry'] ) ? '1' : '0';
		$default_expiry_days = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_default_expiry_days'] ?? 0 ) ) );
		$max_expiry_days     = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_max_expiry_days'] ?? 0 ) ) );

		// File uploads.
		$max_file_mb = max( 1, absint( wp_unslash( $_POST['folio_drawbridge_max_file_mb'] ?? 50 ) ) );

		// Audit log retention.
		$prune_enabled       = isset( $_POST['folio_drawbridge_audit_prune_enabled'] ) ? '1' : '0';
		$prune_days          = max( 30, absint( wp_unslash( $_POST['folio_drawbridge_audit_prune_days'] ?? 365 ) ) );

		// Data & privacy.
		$delete_on_uninstall = isset( $_POST['folio_drawbridge_delete_on_uninstall'] ) ? '1' : '0';

		// Notifications.
		$notify_on_download  = isset( $_POST['folio_drawbridge_notify_on_download'] ) ? '1' : '0';
		$expiry_warning_days = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_expiry_warning_days'] ?? 0 ) ) );

		// File type restrictions.
		$allowed_extensions = sanitize_text_field( wp_unslash( $_POST['folio_drawbridge_allowed_file_extensions'] ?? '' ) );

		// Storage quotas.
		$storage_quota_mb = max( 0, absint( wp_unslash( $_POST['folio_drawbridge_storage_quota_mb'] ?? 0 ) ) );

		// Email templates.
		$email_template_types = [ 'invite', 'otp', 'download_notification', 'expiry_warning' ];
		$email_template_data  = [];
		foreach ( $email_template_types as $type ) {
			$subject = sanitize_text_field( wp_unslash( $_POST[ "folio_drawbridge_email_{$type}_subject" ] ?? '' ) );
			$body    = sanitize_textarea_field( wp_unslash( $_POST[ "folio_drawbridge_email_{$type}_body" ] ?? '' ) );
			$email_template_data[ $type ] = compact( 'subject', 'body' );
		}

		// SIEM logging.
		$siem_enabled    = isset( $_POST['folio_drawbridge_siem_enabled'] ) ? '1' : '0';
		$siem_log_path   = sanitize_text_field( wp_unslash( $_POST['folio_drawbridge_siem_log_path'] ?? '' ) );
		// The rule itself lives with the writer (folio_drawbridge_siem_path_error), so the
		// check made here and the check made on every append can never drift.
		$siem_path_error = folio_drawbridge_siem_path_error( $siem_log_path );
		if ( $siem_path_error !== '' ) {
			$siem_log_path    = get_option( 'folio_drawbridge_siem_log_path', '' ); // keep previous value
			$siem_path_error .= ' Previous value retained.';
		}
		$siem_format = sanitize_key( wp_unslash( $_POST['folio_drawbridge_siem_format'] ?? 'json' ) );
		$siem_format = in_array( $siem_format, [ 'json', 'csv' ], true ) ? $siem_format : 'json';

		update_option( 'folio_drawbridge_otp_ttl_minutes',            $otp_ttl );
		update_option( 'folio_drawbridge_otp_max_attempts',            $otp_max_attempts );
		update_option( 'folio_drawbridge_otp_cooldown_seconds',        $otp_cooldown );
		update_option( 'folio_drawbridge_allow_unlimited_downloads',   $allow_unlimited_downloads );
		update_option( 'folio_drawbridge_default_max_downloads',       $default_max_downloads );
		update_option( 'folio_drawbridge_max_download_limit',          $max_download_limit );
		update_option( 'folio_drawbridge_allow_no_expiry',             $allow_no_expiry );
		update_option( 'folio_drawbridge_default_expiry_days',         $default_expiry_days );
		update_option( 'folio_drawbridge_max_expiry_days',             $max_expiry_days );
		update_option( 'folio_drawbridge_max_file_mb',                 $max_file_mb );
		update_option( 'folio_drawbridge_audit_prune_enabled',         $prune_enabled );
		update_option( 'folio_drawbridge_audit_prune_days',            $prune_days );
		update_option( 'folio_drawbridge_delete_on_uninstall',         $delete_on_uninstall );
		update_option( 'folio_drawbridge_siem_enabled',                $siem_enabled );
		update_option( 'folio_drawbridge_siem_log_path',               $siem_log_path );
		update_option( 'folio_drawbridge_siem_format',                 $siem_format );
		update_option( 'folio_drawbridge_notify_on_download',          $notify_on_download );
		update_option( 'folio_drawbridge_expiry_warning_days',         $expiry_warning_days );
		update_option( 'folio_drawbridge_allowed_file_extensions',     $allowed_extensions );
		update_option( 'folio_drawbridge_storage_quota_mb',            $storage_quota_mb );
		foreach ( $email_template_data as $type => $tmpl ) {
			update_option( "folio_drawbridge_email_{$type}_subject", $tmpl['subject'] );
			update_option( "folio_drawbridge_email_{$type}_body",    $tmpl['body'] );
		}

		folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null, [
			'otp_ttl_minutes'  => $otp_ttl,
			'max_file_mb'      => $max_file_mb,
			'otp_max_attempts' => $otp_max_attempts,
		] );

		$notice = 'Settings saved.';
		if ( isset( $_POST['folio_drawbridge_apply_to_existing_dl'] ) || isset( $_POST['folio_drawbridge_apply_to_existing_expiry'] ) ) {
			$enforced = folio_drawbridge_enforce_share_limits();
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

		folio_drawbridge_set_notice( $notice, $siem_path_error ? 'warning' : 'success' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Enforce share limits on existing shares ───────────────────────────────
	if ( isset( $_POST['folio_drawbridge_enforce_share_limits'] ) ) {
		$updated = folio_drawbridge_enforce_share_limits();
		folio_drawbridge_set_notice(
			sprintf( 'Share limits enforced. <strong>%d</strong> share%s updated.', $updated, $updated === 1 ? '' : 's' ),
			'success'
		);
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Manual audit prune ───────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_manual_prune'] ) ) {
		$days    = max( 1, absint( wp_unslash( $_POST['folio_drawbridge_prune_days_manual'] ?? 365 ) ) );
		$deleted = folio_drawbridge_prune_audit_log( $days );
		folio_drawbridge_set_notice( sprintf( 'Pruned <strong>%d</strong> audit log entr%s older than %d days.', $deleted, $deleted === 1 ? 'y' : 'ies', $days ), 'success' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: revoke share ──────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_revoke_share'] ) ) {
		$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		if ( $share_id ) {
			folio_drawbridge_revoke_share( $share_id, get_current_user_id() );
			folio_drawbridge_set_notice( 'Share revoked.', 'success' );
		}
		$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: resend share invite ────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_resend_share'] ) ) {
		$share_id = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		if ( $share_id ) {
			$result = folio_drawbridge_resend_share_invite( $share_id, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				folio_drawbridge_set_notice( 'Could not resend invite: ' . esc_html( $result->get_error_message() ), 'error' );
			} else {
				folio_drawbridge_set_notice( 'Invite email resent.', 'success' );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: change vault status ───────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_vault_status'] ) ) {
		$vault_id   = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		$new_status = sanitize_key( wp_unslash( $_POST['new_status'] ?? '' ) );
		if ( $vault_id && $new_status ) {
			folio_drawbridge_update_vault_status( $vault_id, $new_status, get_current_user_id() );
			folio_drawbridge_set_notice( 'Vault status updated to <strong>' . esc_html( $new_status ) . '</strong>.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: delete vault ───────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_delete_vault'] ) ) {
		$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		if ( $vault_id ) {
			folio_drawbridge_delete_vault( $vault_id );
			folio_drawbridge_set_notice( 'Vault permanently deleted.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: transfer vault ownership ──────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_transfer_vault'] ) ) {
		$vault_id   = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		$new_login  = sanitize_text_field( wp_unslash( $_POST['new_owner_login'] ?? '' ) );
		$new_user   = $new_login ? ( get_user_by( 'login', $new_login ) ?: get_user_by( 'email', $new_login ) ) : null;
		$redirect   = add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) );

		if ( ! $vault_id || ! $new_user ) {
			folio_drawbridge_set_notice( 'User not found: "' . esc_html( $new_login ) . '". Check the login name or email and try again.', 'error' );
		} else {
			$result = folio_drawbridge_transfer_vault( $vault_id, (int) $new_user->ID, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				folio_drawbridge_set_notice( $result->get_error_message(), 'error' );
			} else {
				folio_drawbridge_set_notice( 'Vault transferred to ' . esc_html( $new_user->user_login ) . '.', 'success' );
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Admin: delete file ────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_delete_file'] ) ) {
		$file_id  = absint( wp_unslash( $_POST['file_id'] ?? 0 ) );
		$vault_id = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		if ( $file_id ) {
			folio_drawbridge_delete_file( $file_id, get_current_user_id() );
			folio_drawbridge_set_notice( 'File deleted.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Grant vault (User) access ────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_grant_user'] ) ) {
		$user_id = absint( wp_unslash( $_POST['folio_drawbridge_user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'folio_drawbridge_use_vaults', true );
			folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'grant_vault_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			folio_drawbridge_set_notice( 'Vault access granted to <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Grant Drawbridge Admin access ───────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_grant_drawbridge_admin'] ) ) {
		$user_id = absint( wp_unslash( $_POST['folio_drawbridge_user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'folio_drawbridge_manage_vaults', true );
			$user->add_cap( 'folio_drawbridge_use_vaults', true );
			folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'grant_drawbridge_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			folio_drawbridge_set_notice( 'Drawbridge Admin access granted to <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Promote vault user to Drawbridge Admin ─────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_promote_drawbridge_admin'] ) ) {
		$user_id = absint( wp_unslash( $_POST['folio_drawbridge_user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'folio_drawbridge_manage_vaults', true );
			folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'promote_to_drawbridge_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			folio_drawbridge_set_notice( '<strong>' . esc_html( $user->display_name ) . '</strong> promoted to Drawbridge Admin.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Demote Drawbridge Admin to vault user ──────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_demote_drawbridge_admin'] ) ) {
		$user_id = absint( wp_unslash( $_POST['folio_drawbridge_user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( 'folio_drawbridge_manage_vaults' );
			folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'demote_drawbridge_admin', 'target_user' => $user->user_login ],
				get_current_user_id() );
			folio_drawbridge_set_notice( '<strong>' . esc_html( $user->display_name ) . '</strong> demoted to Vault User.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Revoke all Drawbridge access from a user ────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_revoke_user'] ) ) {
		$user_id = absint( wp_unslash( $_POST['folio_drawbridge_user_id'] ?? 0 ) );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( 'folio_drawbridge_use_vaults' );
			$user->remove_cap( 'folio_drawbridge_manage_vaults' );
			folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'revoke_all_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			folio_drawbridge_set_notice( 'All Drawbridge access revoked for <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: edit vault expiry ─────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_edit_vault_expiry'] ) ) {
		$vault_id   = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		$raw_date   = sanitize_text_field( wp_unslash( $_POST['vault_new_expires'] ?? '' ) );
		$expires_at = $raw_date ? $raw_date . ' 23:59:59' : '';
		if ( $vault_id ) {
			folio_drawbridge_update_vault_expiry( $vault_id, $expires_at, get_current_user_id() );
			folio_drawbridge_set_notice( $expires_at ? 'Vault expiry updated.' : 'Vault expiry cleared.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: edit vault name/description ──────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_edit_vault_meta'] ) ) {
		$vault_id    = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['vault_new_name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['vault_new_description'] ?? '' ) );
		if ( $vault_id ) {
			$result = folio_drawbridge_update_vault_meta( $vault_id, $name, $description, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				folio_drawbridge_set_notice( $result->get_error_message(), 'error' );
			} else {
				folio_drawbridge_set_notice( 'Vault name and description updated.', 'success' );
			}
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: edit share ────────────────────────────────────────────────────
	if ( isset( $_POST['folio_drawbridge_admin_edit_share'] ) ) {
		$share_id      = absint( wp_unslash( $_POST['share_id'] ?? 0 ) );
		$vault_id      = absint( wp_unslash( $_POST['vault_id'] ?? 0 ) );
		$max_downloads = max( 0, absint( wp_unslash( $_POST['share_max_downloads'] ?? 0 ) ) );
		$raw_date      = sanitize_text_field( wp_unslash( $_POST['share_new_expires'] ?? '' ) );
		$expires_at    = $raw_date ? $raw_date . ' 23:59:59' : '';
		if ( $share_id ) {
			folio_drawbridge_update_share( $share_id, $max_downloads, $expires_at, get_current_user_id() );
			folio_drawbridge_set_notice( 'Share updated.', 'success' );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}
}

// ─── Menu registration ────────────────────────────────────────────────────────

add_action( 'admin_menu', 'folio_drawbridge_register_admin_menu' );

function folio_drawbridge_register_admin_menu(): void {
	// folio_drawbridge_admin, not manage_options: delegated Drawbridge admins are non-administrator
	// users who must still reach this panel. WordPress administrators receive
	// folio_drawbridge_admin implicitly via the user_has_cap filter in the main plugin file.
	Folio_Drawbridge_Hub::ensure_parent( 'folio_drawbridge_manage_vaults' );

	$hook = add_submenu_page(
		Folio_Drawbridge_Hub::SLUG,
		'Folio Drawbridge',
		'Drawbridge',
		'folio_drawbridge_manage_vaults',
		'folio-drawbridge',
		'folio_drawbridge_admin_page'
	);

	if ( $hook ) {
		add_action( "load-{$hook}", 'folio_drawbridge_register_admin_help_tabs' );
	}
}

// ─── Contextual help ──────────────────────────────────────────────────────────

function folio_drawbridge_register_admin_help_tabs(): void {
	$screen = get_current_screen();
	$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector for contextual help.

	switch ( $tab ) {

		case 'dashboard':
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-dash-overview',
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
				'id'      => 'folio-drawbridge-dash-security',
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
				'id'      => 'folio-drawbridge-vaults-browse',
				'title'   => 'Browsing Vaults',
				'content' =>
					'<p>The Vaults tab lists every vault created on this site, across all users.</p>' .
					'<p>Use the <strong>filter panel</strong> on the left to narrow the list by status (active, expired, revoked, archived) or by searching the vault name. Use the filter URL to bookmark a specific filtered view.</p>' .
					'<p>Click any sortable column header (Name, Status, Created, Expires) to sort the list. Click again to reverse direction. Sort state is preserved in the URL.</p>' .
					'<p>Click a vault name or the <strong>Inspect</strong> button to open the vault inspector, which shows all files, shares, and the vault\'s own audit trail.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-vaults-actions',
				'title'   => 'Vault Actions',
				'content' =>
					'<p>Inside the vault inspector you can:</p>' .
					'<ul>' .
					'<li><strong>Download any file</strong> — admin downloads are fully decrypted on the fly and logged in the audit trail.</li>' .
					'<li><strong>Download All as ZIP</strong> — decrypts all vault files and bundles them into a single ZIP archive for download. Requires the PHP ZipArchive extension.</li>' .
					'<li><strong>Delete a file</strong> — permanently removes the encrypted file from disk and the database.</li>' .
					'<li><strong>Edit a share</strong> — update the download limit or expiry date on a pending or active share without revoking and recreating it.</li>' .
					'<li><strong>Revoke a share</strong> — immediately blocks the recipient from accessing the vault, even if they have an active download session.</li>' .
					'<li><strong>Edit vault expiry</strong> — change or clear the vault\'s expiry date inline.</li>' .
					'<li><strong>Edit name &amp; description</strong> — rename the vault or update its description without affecting files or shares.</li>' .
					'<li><strong>Transfer ownership</strong> — reassign the vault to any user who already has Vault User or Drawbridge Admin access. The original owner loses access; the new owner immediately sees it in their vault list.</li>' .
					'<li><strong>Change vault status</strong> — set a vault to active, expired, revoked, or archived. Non-active vaults cannot be shared or uploaded to.</li>' .
					'<li><strong>Delete vault</strong> — permanently removes all files, shares, and the vault record. This cannot be undone.</li>' .
					'</ul>' .
					'<p>All tables inside the inspector are sortable by clicking column headers. All admin actions are recorded in the audit log with your user account and IP address.</p>',
			] );
			break;

		case 'audit':
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-audit-filter',
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
				'id'      => 'folio-drawbridge-audit-export',
				'title'   => 'Exporting & Pruning',
				'content' =>
					'<p><strong>Export to CSV</strong> downloads the current filtered result set as a CSV file. The export respects all active filters, so you can export a targeted subset of events.</p>' .
					'<p><strong>Manual Prune</strong> permanently deletes all audit entries older than the number of days you specify. Use this to comply with data-retention policies or to keep the table size manageable.</p>' .
					'<p>Automatic pruning can also be configured in the <strong>Settings</strong> tab under Audit Log Retention — it runs hourly via WP-Cron.</p>',
			] );
			break;

		case 'users':
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-users-roles',
				'title'   => 'Access Roles',
				'content' =>
					'<p>There are two levels of access below WordPress administrator:</p>' .
					'<ul>' .
					'<li><strong>Drawbridge Admin</strong> — full access to the Folio Drawbridge admin panel: all tabs, the vault inspector, audit log export, settings, and the Users tab. Does not require WordPress administrator privileges.</li>' .
					'<li><strong>Vault User</strong> — access to <strong>My Vaults</strong> only. Can create vaults, upload and delete files, create and revoke share links, and view their own activity log. Has no visibility into other users\' vaults or any admin panel tabs.</li>' .
					'</ul>' .
					'<p>WordPress administrators (<em>manage_options</em>) always have full Drawbridge Admin access implicitly and do not appear in either list.</p>' .
					'<p>Columns in both tables are sortable by clicking the column header. Click the number in the <strong>Vaults</strong> column to expand a list of that user\'s vaults — each links straight to the vault inspector.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-users-grant',
				'title'   => 'Granting & Promoting',
				'content' =>
					'<p>Search for any non-administrator user by their WordPress username or email address. The search panel shows the user\'s current Drawbridge status and presents contextual action buttons:</p>' .
					'<ul>' .
					'<li><strong>Grant Vault Access</strong> — gives the user Vault User access. They immediately see <strong>My Vaults</strong> in their wp-admin sidebar.</li>' .
					'<li><strong>Grant Drawbridge Admin Access</strong> — gives the user full Drawbridge Admin access without promoting them to WordPress administrator.</li>' .
					'<li><strong>Promote to Drawbridge Admin</strong> — upgrades an existing Vault User to Drawbridge Admin.</li>' .
					'</ul>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-users-revoke',
				'title'   => 'Demoting & Revoking',
				'content' =>
					'<p>Actions available from the Drawbridge Admins and Vault Users tables:</p>' .
					'<ul>' .
					'<li><strong>Demote to User</strong> — removes Drawbridge Admin access but retains Vault User access. The user keeps their vaults.</li>' .
					'<li><strong>Revoke</strong> / <strong>Remove All</strong> — removes all Drawbridge access (both capabilities). Existing vaults and files are preserved; the user simply cannot log in to manage them. Administrators can still inspect the vaults from the Vaults tab.</li>' .
					'</ul>' .
					'<p>All grant, promote, demote, and revoke actions are recorded in the audit log.</p>',
			] );
			break;

		case 'settings':
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-twofactor',
				'title'   => 'Two-Factor Verification',
				'content' =>
					'<p>These settings control the one-time code (OTP) sent to share recipients as the second factor of authentication before they can download files.</p>' .
					'<p><strong>OTP Validity</strong> — how many minutes a verification code remains valid after it is emailed. Shorter values reduce the window of opportunity if an email is intercepted; longer values are more forgiving if email delivery is slow. Range: 5–60 minutes.</p>' .
					'<p><strong>Max Verification Attempts</strong> — the number of incorrect codes a recipient can enter before the code is invalidated and they must request a new one. Lower values reduce brute-force risk. Range: 1–10.</p>' .
					'<p><strong>OTP Cooldown</strong> — minimum number of seconds a recipient must wait before they can request a new verification code. This prevents automated code-request flooding. Set to 0 to disable the cooldown.</p>' .'<p>Independently of this setting, a fixed ceiling of 10 codes per share per hour always applies. Because the attempt limit resets with each new code, that ceiling is what keeps guessing a six-digit code impractical even when the cooldown is 0.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-dl-limits',
				'title'   => 'Download Limits',
				'content' =>
					'<p>These settings cap how many times a single share link can be used to collect a vault. All limits apply only to non-administrator users — administrators are always exempt.</p>' .'<p><strong>What counts as one download:</strong> one successful verification. After entering their one-time code the recipient may retrieve every file in the vault — individually or as a ZIP — for the life of that download session. Files are not counted separately, so a limit of 1 on a ten-file vault still delivers all ten files, once.</p>' .
					'<ul>' .
					'<li><strong>Allow Unlimited Downloads</strong> — when unchecked, every share must be given a finite download count. Users cannot leave this field blank.</li>' .
					'<li><strong>Default Download Limit</strong> — the value pre-filled in the share creation form. Set to 0 for no pre-fill (useful when unlimited is allowed and most shares are intended to be unlimited).</li>' .
					'<li><strong>Maximum Download Limit</strong> — the hard ceiling users cannot exceed when entering a limit. Set to 0 to impose no ceiling. This does not affect shares set to unlimited when unlimited is permitted.</li>' .
					'</ul>' .
					'<p>When you change these values, a checkbox appears offering to retroactively apply the new limits to existing active and pending shares that currently exceed them. Shares already within the limits and administrator shares are always skipped.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-expiration',
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
				'id'      => 'folio-drawbridge-settings-uploads',
				'title'   => 'File Uploads',
				'content' =>
					'<p><strong>Maximum File Size</strong> — the plugin-level ceiling on uploaded files, in megabytes.</p>' .
					'<p>Unlike a standard WordPress file upload, this plugin splits files into small chunks on the client before sending them to the server. Each chunk is sized to fit within your server\'s <code>upload_max_filesize</code> and <code>post_max_size</code> PHP limits, and the chunks are reassembled into the complete file server-side. This means the plugin-level maximum can safely <strong>exceed</strong> those server limits — for example, you can accept 2 GB files even if <code>upload_max_filesize</code> is set to 8M.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-siem',
				'title'   => 'SIEM Logging',
				'content' =>
					'<p>When enabled, every audit event is appended to a log file on the server in addition to being stored in the database. This allows external security information and event management (SIEM) tools such as Splunk, Datadog, or the ELK stack to ingest plugin activity in real time.</p>' .
					'<p><strong>Log File Path</strong> — the absolute path to the log file. The directory must exist and the web server process must have write permission. The file is created automatically on first write.</p>' .'<p>The path must sit <strong>outside</strong> the WordPress directory and must not use an executable or web-servable extension (<code>.php</code>, <code>.html</code>, and similar are rejected). Audit entries contain text supplied by unauthenticated visitors, so a log inside the web root could be requested — or executed — over HTTP.</p>' .
					'<p><strong>Log Format</strong></p>' .
					'<ul>' .
					'<li><em>JSON</em> — one JSON object per line (NDJSON / JSON Lines format). Each line is a complete, self-contained event and can be streamed directly into most log aggregators.</li>' .
					'<li><em>CSV</em> — a comma-separated file with a header row written once when the file is first created. Suitable for ingestion into spreadsheet tools or systems that prefer flat tabular data.</li>' .
					'</ul>' .
					'<p>Both formats include: timestamp (UTC), event type, vault ID, share ID, actor ID, IP address, event details, and site URL.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-audit-retention',
				'title'   => 'Audit Log Retention',
				'content' =>
					'<p>The audit log grows over time. These settings help manage its size.</p>' .
					'<p><strong>Auto-Prune</strong> — when enabled, the hourly WP-Cron lifecycle job automatically deletes audit entries older than the configured retention window. Useful for compliance with data-retention policies.</p>' .
					'<p><strong>Retention Window</strong> — entries older than this many days are deleted when auto-prune runs. Minimum 30 days.</p>' .
					'<p>You can also prune manually at any time from the <strong>Audit Log</strong> tab using the Manual Prune panel in the filter sidebar. The manual prune respects the same day threshold but runs immediately rather than waiting for cron.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-key',
				'title'   => 'Encryption Key',
				'content' =>
					'<p>The master encryption key is the root secret from which every vault\'s unique per-vault encryption key is derived. All files are encrypted with AES-256-CBC. The key must be a 64-character hexadecimal string (32 raw bytes).</p>' .
					'<p>The most secure configuration is to define the key as a PHP constant in <code>wp-config.php</code> so it is never stored in the database:</p>' .
					'<pre><code>define( \'FOLIO_DRAWBRIDGE_MASTER_KEY\', \'your-64-hex-char-key\' );</code></pre>' .
					'<p>Use the <strong>Generate New Key</strong> button to produce a cryptographically secure key server-side. The key is shown once and never stored by the plugin — copy it immediately into <code>wp-config.php</code>.</p>' .
					'<p><strong>Warning:</strong> Replacing an existing key will permanently break decryption of all files already uploaded. Only generate a new key on a fresh installation with no uploaded files.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-notifications',
				'title'   => 'Notifications',
				'content' =>
					'<p>These settings control automated email alerts sent to vault owners.</p>' .
					'<ul>' .
					'<li><strong>Download Notifications</strong> — when enabled, the vault owner receives an email each time a recipient successfully downloads a file. The notification includes the file name, share link details, and the recipient\'s IP address.</li>' .
					'<li><strong>Expiry Warning Emails</strong> — when enabled, vault owners receive an advance warning before a share link expires. Set the number of days in advance the warning is sent (e.g. 3 days before expiry). Each share receives at most one warning, regardless of how many cron cycles run before expiry.</li>' .
					'</ul>' .
					'<p>Both notification types use the customisable email templates in the <strong>Email Templates</strong> section below.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-filetypes',
				'title'   => 'File Type Restrictions',
				'content' =>
					'<p><strong>Allowed File Extensions</strong> — a comma-separated list of extensions that vault users are permitted to upload (e.g. <code>pdf, docx, xlsx, png</code>). Leave blank to allow all file types.</p>' .
					'<p>The check is performed after all chunks have been reassembled into the complete file, before encryption and storage. Files that fail the check are deleted from the temporary assembly area and an error is returned to the uploader.</p>' .
					'<p>This setting applies to vault users only. WordPress administrators are not restricted by it.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-quotas',
				'title'   => 'Storage Quotas',
				'content' =>
					'<p><strong>Per-User Storage Quota (MB)</strong> — the maximum total encrypted storage a single vault user may consume across all their vaults. Set to 0 for no limit.</p>' .
					'<p>Quota is calculated as the sum of all encrypted file sizes stored across every vault owned by the user. The check runs at upload time; if adding the new file would push the user over quota, the upload is rejected and the assembled temp file is discarded.</p>' .
					'<p>Administrators are not subject to quotas.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-templates',
				'title'   => 'Email Templates',
				'content' =>
					'<p>Customise the subject line and body of every automated email sent by the plugin. Four templates are available:</p>' .
					'<ul>' .
					'<li><strong>Share Invitation</strong> — sent to the recipient when a vault owner creates a share link.</li>' .
					'<li><strong>OTP Verification</strong> — sent to the recipient with their one-time verification code when they attempt to access a vault.</li>' .
					'<li><strong>Download Notification</strong> — sent to the vault owner when a recipient downloads a file (requires Download Notifications to be enabled).</li>' .
					'<li><strong>Share Expiry Warning</strong> — sent to the vault owner before a share link expires (requires Expiry Warning Emails to be enabled).</li>' .
					'</ul>' .
					'<p>Templates support placeholder tokens in <code>{curly_braces}</code>. Available tokens vary by template and are listed beneath each body field. Tokens that are not replaced (e.g. a misspelled placeholder) are left as-is in the sent email.</p>' .
					'<p>Leave the subject or body blank to restore the built-in default for that field.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'folio-drawbridge-settings-data',
				'title'   => 'Data & Privacy / Storage',
				'content' =>
					'<p><strong>Delete all plugin data on uninstall</strong> — when checked, removing the plugin from the Plugins screen permanently drops all five database tables, deletes all encrypted files from disk, and removes all plugin options and transients. This is irreversible. Leave unchecked if you want to preserve data across a reinstall.</p>' .
					'<p><strong>Encrypted file storage</strong> — shows the directory where encrypted vault files are written (<code>wp-content/uploads/folio-drawbridge-vaults/</code>). The directory is protected by an <code>.htaccess</code> file that blocks direct HTTP access. Files are never served directly — all downloads go through PHP, which decrypts them on the fly.</p>' .
					'<p>The storage status indicator confirms whether the directory exists, is protected by <code>.htaccess</code>, and is writable by the web server.</p>',
			] );
			break;
	}

	$screen->set_help_sidebar(
		'<p><strong>Folio Drawbridge</strong></p>' .
		'<p>Version ' . FOLIO_DRAWBRIDGE_VERSION . '</p>' .
		'<hr>' .
		'<p>Encrypted vault storage with two-factor external sharing and full audit logging.</p>'
	);
}

// ─── Admin asset enqueueing ───────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'folio_drawbridge_enqueue_admin_assets' );

function folio_drawbridge_enqueue_admin_assets( string $hook ): void {
	// The page lives under the shared Folio parent menu, so the hook is
	// '{parent}_page_folio-drawbridge' (e.g. 'folio_page_folio-drawbridge') rather
	// than 'toplevel_page_folio-drawbridge'. Match on the suffix so the parent can
	// vary, deriving the length from the string rather than hardcoding it — a
	// literal offset silently stops matching the moment the slug changes.
	$suffix = '_page_folio-drawbridge';
	if ( substr( $hook, -strlen( $suffix ) ) !== $suffix ) {
		return;
	}

	// Admin file download (multipart) — handled inline; we also need to handle
	// admin vault file serving via a direct AJAX action.
	add_action( 'admin_head', 'folio_drawbridge_admin_inline_js' );

	wp_register_style( 'folio-drawbridge-admin', false, [], FOLIO_DRAWBRIDGE_VERSION );
	wp_enqueue_style( 'folio-drawbridge-admin' );

	wp_add_inline_style( 'folio-drawbridge-admin', '
		/* ── Buttons ── */
		.folio-drawbridge-btn { background:#fff; border:1px solid #ccd0d4; padding:5px 12px; border-radius:4px;
		           cursor:pointer; font-size:12px; color:#2271b1; text-decoration:none; display:inline-block; }
		.folio-drawbridge-btn:hover { background:#f0f6fb; color:#2271b1; }
		.folio-drawbridge-danger { color:#d63638; border-color:#d63638; }
		.folio-drawbridge-danger:hover { background:#fef0f0; }
		.folio-drawbridge-primary { background:#2271b1; color:#fff; border-color:#2271b1; }
		.folio-drawbridge-primary:hover { background:#135e96; color:#fff; }

		/* ── Cards ── */
		.folio-drawbridge-card { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:20px; }

		/* ── Stat cards (dashboard) ── */
		.folio-drawbridge-stats { display:flex; gap:16px; flex-wrap:wrap; margin-top:20px; }
		.folio-drawbridge-stat { flex:1; min-width:130px; background:#fff; border:1px solid #ccd0d4;
		            border-radius:4px; padding:16px; text-align:center; }
		.folio-drawbridge-stat-num { font-size:32px; font-weight:700; line-height:1.2; color:#2271b1; }
		.folio-drawbridge-stat-label { font-size:12px; color:#666; margin-top:4px; }

		/* ── Status badges ── */
		.folio-drawbridge-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
		.folio-drawbridge-badge-active  { background:#d1e7dd; color:#0a3622; }
		.folio-drawbridge-badge-expired,.folio-drawbridge-badge-revoked,.folio-drawbridge-badge-limit_reached { background:#f8d7da; color:#58151c; }
		.folio-drawbridge-badge-archived,.folio-drawbridge-badge-pending { background:#e2e3e5; color:#41464b; }

		/* ── Tables ── */
		.folio-drawbridge-table { width:100%; border-collapse:collapse; }
		.folio-drawbridge-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #ddd; font-size:12px; }
		.folio-drawbridge-table td { padding:8px 10px; border-bottom:1px solid #f0f2f5; font-size:13px; vertical-align:middle; }
		.folio-drawbridge-table tr:hover td { background:#f9fafc; }

		/* ── Vault inspector header ── */
		.folio-drawbridge-vault-inspector { margin-top:20px; }
		.folio-drawbridge-vault-inspector h2 { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
		.folio-drawbridge-vault-meta { color:#888; font-size:13px; margin:0 0 16px; }

		/* ── Pagination ── */
		.folio-drawbridge-pagination { display:flex; align-items:center; justify-content:center; gap:4px; margin-top:16px; }
		.folio-drawbridge-pagination a, .folio-drawbridge-pagination span { display:inline-flex; align-items:center; justify-content:center;
		    min-width:32px; height:32px; padding:0 8px; border:1px solid #ccd0d4; border-radius:6px;
		    font-size:13px; text-decoration:none; color:#2271b1; background:#fff; transition:background .15s; }
		.folio-drawbridge-pagination .current { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
		.folio-drawbridge-pagination a:hover { background:#f0f6fb; }
		.folio-drawbridge-pagination .dots { border:none; background:none; color:#999; }

		/* ── Filter panel ── */
		.folio-drawbridge-filter-wrap { display:flex; gap:20px; align-items:flex-start; margin-top:20px; }
		.folio-drawbridge-filter-panel { flex:0 0 220px; }
		.folio-drawbridge-filter-body { flex:1; min-width:0; }

		/* ── Sortable columns ── */
		.folio-drawbridge-table th a { text-decoration:none; color:inherit; white-space:nowrap; }
		.folio-drawbridge-table th[data-sortable] { cursor:pointer; user-select:none; }
		.folio-drawbridge-sort-ind { font-size:10px; color:#bbb; margin-left:3px; }
		.folio-drawbridge-sort-ind.active { color:#2271b1; }
	' );
}

function folio_drawbridge_admin_inline_js(): void {
	?>
	<script>
	function folioDrawbridgeAdminDownload(fileId) {
		var url = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>'
			+ '?action=folio_drawbridge_admin_download&file_id=' + fileId
			+ '&_wpnonce=' + encodeURIComponent('<?php echo esc_js( wp_create_nonce( 'folio_drawbridge_admin_download' ) ); ?>');
		window.location.href = url;
	}

	/**
	 * Client-side table sort. Keeps data-subrow rows paired with their parent.
	 * Call after DOM ready: folioDrawbridgeSortTable('my-table-id')
	 */
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
				// Reset all indicators.
				headers.forEach(function(h) {
					var i = h.querySelector('.folio-drawbridge-sort-ind');
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

add_action( 'wp_ajax_folio_drawbridge_admin_download', 'folio_drawbridge_admin_download' );

function folio_drawbridge_admin_download(): void {
	if ( ! folio_drawbridge_is_admin() ) {
		wp_die( 'Access denied.', 403 );
	}

	check_ajax_referer( 'folio_drawbridge_admin_download', '_wpnonce' );

	$file_id = absint( wp_unslash( $_GET['file_id'] ?? 0 ) );
	$file    = folio_drawbridge_get_file( $file_id );

	if ( ! $file ) {
		wp_die( 'File not found.', 404 );
	}

	$vault = folio_drawbridge_get_vault( (int) $file->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	// Log admin vault access before serving.
	folio_drawbridge_log( FOLIO_DRAWBRIDGE_EVT_ADMIN_VAULT_ACCESS, (int) $vault->id, null,
		[ 'file_id' => $file_id, 'original_name' => $file->original_name ],
		get_current_user_id()
	);

	folio_drawbridge_serve_file( $file, $vault, null, true );
}

// ─── Encryption key preview generator ────────────────────────────────────────

add_action( 'wp_ajax_folio_drawbridge_generate_key_preview', 'folio_drawbridge_generate_key_preview' );

function folio_drawbridge_generate_key_preview(): void {
	if ( ! folio_drawbridge_is_admin() ) {
		wp_send_json_error( 'Access denied.', 403 );
	}

	check_ajax_referer( 'folio_drawbridge_generate_key_preview', '_wpnonce' );

	// Generate 32 cryptographically secure random bytes → 64-char hex string.
	// Never stored — only returned for the admin to copy into wp-config.php.
	$key = bin2hex( random_bytes( 32 ) );

	wp_send_json_success( [ 'key' => $key ] );
}

// ─── Admin notice helpers ─────────────────────────────────────────────────────

function folio_drawbridge_set_notice( string $message, string $type = 'success' ): void {
	set_transient( 'folio_drawbridge_admin_notice_' . get_current_user_id(), compact( 'message', 'type' ), 30 );
}

function folio_drawbridge_show_notice(): void {
	$key    = 'folio_drawbridge_admin_notice_' . get_current_user_id();
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
		$notice['message'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-composed HTML; user-supplied parts are escaped by folio_drawbridge_set_notice() callers before storage.
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
function folio_drawbridge_sortable_th( string $label, string $col, string $cur_col, string $cur_order, array $url_args, bool $nosort = false ): string {
	if ( $nosort ) {
		return '<th>' . esc_html( $label ) . '</th>';
	}
	$active    = $cur_col === $col;
	$new_order = ( $active && $cur_order === 'ASC' ) ? 'DESC' : 'ASC';
	$url       = add_query_arg( array_merge( $url_args, [ 'orderby' => $col, 'order' => $new_order ] ), admin_url( 'admin.php' ) );
	$indicator = $active
		? '<span class="folio-drawbridge-sort-ind" style="color:#2271b1;"> ' . ( $cur_order === 'ASC' ? '↑' : '↓' ) . '</span>'
		: '<span class="folio-drawbridge-sort-ind"> ↕</span>';
	return '<th><a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;white-space:nowrap;">'
		. esc_html( $label ) . $indicator . '</a></th>';
}

// ─── Shared pagination helper ─────────────────────────────────────────────────

function folio_drawbridge_render_pagination( int $current, int $total_pages, array $extra_args = [] ): void {
	if ( $total_pages <= 1 ) {
		return;
	}

	$base = array_merge( [ 'page' => 'folio-drawbridge' ], $extra_args );

	echo '<div class="folio-drawbridge-pagination">';

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
			echo '<span class="current">' . (int) $p . '</span>';
		} else {
			$url = add_query_arg( array_merge( $base, [ 'paged' => $p ] ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '">' . (int) $p . '</a>';
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

function folio_drawbridge_admin_page(): void {
	if ( ! folio_drawbridge_is_admin() ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'folio-drawbridge' ) );
	}

	$current_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector for rendering.

	folio_drawbridge_show_notice();

	echo '<div class="wrap"><h1>Folio Drawbridge</h1>';
	echo '<h2 class="nav-tab-wrapper">';

	$tabs = [
		'dashboard' => 'Dashboard',
		'vaults'    => 'Vaults',
		'audit'     => 'Audit Log',
		'users'     => 'Users',
		'settings'  => 'Settings',
	];

	foreach ( $tabs as $slug => $label ) {
		$url   = add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => $slug ], admin_url( 'admin.php' ) );
		$class = $current_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
	}

	echo '</h2>';

	switch ( $current_tab ) {
		case 'vaults':
			folio_drawbridge_render_tab_vaults();
			break;
		case 'audit':
			folio_drawbridge_render_tab_audit();
			break;
		case 'users':
			folio_drawbridge_render_tab_users();
			break;
		case 'settings':
			folio_drawbridge_render_tab_settings();
			break;
		default:
			folio_drawbridge_render_tab_dashboard();
	}

	echo '</div>';
}
