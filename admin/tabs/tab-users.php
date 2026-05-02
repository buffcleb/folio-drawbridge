<?php
/**
 * Users tab — manage SFT user types.
 *
 * Two user types exist within the plugin:
 *   SFT Admin — non-WordPress-admin users granted the sft_admin capability.
 *                They get full access to the Secure Transfer admin panel.
 *   User       — users granted the use_sft_vaults capability.
 *                They can create vaults, upload files, and share them.
 *
 * WordPress administrators (manage_options) always have full access to both
 * the admin panel and vault features and are not listed here.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_tab_users(): void {
	global $wpdb;

	$search        = sanitize_text_field( $_GET['sft_user_search'] ?? '' );
	$search_result = null;
	$search_error  = '';

	if ( $search ) {
		$found = get_user_by( 'login', $search ) ?: get_user_by( 'email', $search );
		if ( $found ) {
			$search_result = $found;
		} else {
			$search_error = 'No user found matching "' . esc_html( $search ) . '".';
		}
	}

	// SFT admin users (sft_admin cap, but NOT WP admins — they're always included).
	$sft_admin_users = get_users( [
		'capability' => 'sft_admin',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	] );
	// Exclude WP administrators (they have sft_admin via the filter but shouldn't be listed).
	$sft_admin_users = array_filter( $sft_admin_users, fn( $u ) => ! $u->has_cap( 'manage_options' ) );

	// Vault users (use_sft_vaults cap, not SFT admins).
	$vault_users = get_users( [
		'capability' => 'use_sft_vaults',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	] );
	$vault_users = array_filter( $vault_users, fn( $u ) => ! $u->has_cap( 'manage_options' ) && ! $u->has_cap( 'sft_admin' ) );

	$tab_url = add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) );
	?>

	<div style="display:flex; gap:24px; align-items:flex-start; margin-top:20px; flex-wrap:wrap;">

		<!-- ── Left: search + grant panel ──────────────────────────────────── -->
		<div class="sft-card" style="flex:0 0 300px; margin-top:0;">
			<h3 style="margin-top:0;">Add User</h3>
			<p style="font-size:13px;color:#555;">Search by username or email to grant access.</p>

			<form method="get">
				<input type="hidden" name="page" value="sft-pro">
				<input type="hidden" name="tab"  value="users">
				<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Username or Email</label>
				<input type="text" name="sft_user_search" value="<?php echo esc_attr( $search ); ?>"
				       style="width:100%;margin-bottom:8px;" placeholder="e.g. jsmith or j@example.com">
				<input type="submit" value="Search" class="button" style="width:100%;">
			</form>

			<?php if ( $search_error ) : ?>
				<p style="color:#d63638;font-size:13px;margin-top:10px;"><?php echo esc_html( $search_error ); ?></p>
			<?php endif; ?>

			<?php if ( $search_result ) :
				$is_wp_admin   = $search_result->has_cap( 'manage_options' );
				$is_sft_admin  = ! $is_wp_admin && $search_result->has_cap( 'sft_admin' );
				$is_vault_user = ! $is_wp_admin && ! $is_sft_admin && $search_result->has_cap( 'use_sft_vaults' );
			?>
				<div style="margin-top:14px;padding:12px;background:#f6f7f7;border-radius:4px;border:1px solid #ddd;">
					<strong><?php echo esc_html( $search_result->display_name ); ?></strong><br>
					<span style="font-size:12px;color:#888;"><?php echo esc_html( $search_result->user_email ); ?></span>

					<?php if ( $is_wp_admin ) : ?>
						<p style="font-size:12px;color:#2271b1;margin:8px 0 0;">WordPress administrator — always has full access.</p>

					<?php elseif ( $is_sft_admin ) : ?>
						<p style="font-size:12px;font-weight:600;color:#0a3622;margin:8px 0 4px;">✓ SFT Admin</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
							<input type="hidden" name="sft_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="sft_demote_sft_admin" value="Demote to User" class="button" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="sft_revoke_user" value="Remove All Access" class="button sft-danger" style="width:100%;">
						</form>

					<?php elseif ( $is_vault_user ) : ?>
						<p style="font-size:12px;font-weight:600;color:#0a3622;margin:8px 0 4px;">✓ Vault User</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
							<input type="hidden" name="sft_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="sft_promote_sft_admin" value="Promote to SFT Admin" class="button" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="sft_revoke_user" value="Revoke Access" class="button sft-danger" style="width:100%;">
						</form>

					<?php else : ?>
						<p style="font-size:12px;color:#555;margin:8px 0 4px;">No SFT access yet.</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
							<input type="hidden" name="sft_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="sft_grant_user" value="Grant Vault Access" class="button button-primary" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="sft_grant_sft_admin" value="Grant SFT Admin Access" class="button" style="width:100%;">
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- ── Right: user lists ────────────────────────────────────────────── -->
		<div style="flex:1; min-width:300px;">

			<!-- SFT Admins -->
			<h3 style="margin-top:0;">SFT Admins (<?php echo count( $sft_admin_users ); ?>)</h3>
			<p style="font-size:13px;color:#555;margin-top:-8px;margin-bottom:12px;">Full access to the Secure Transfer admin panel. Does not require WordPress administrator privileges.</p>

			<?php if ( ! $sft_admin_users ) : ?>
				<p style="color:#888;font-size:13px;margin-bottom:20px;">No SFT admins designated yet.</p>
			<?php else : ?>
				<table id="sft-admins-table" class="sft-table widefat striped" style="margin-bottom:24px;">
					<thead><tr>
						<th>User</th>
						<th>Email</th>
						<th>Vaults</th>
						<th data-nosort>Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $sft_admin_users as $u ) :
						$vault_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}sft_vaults WHERE owner_id = %d", $u->ID
						) );
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ); ?></strong><br>
								<span style="font-size:11px;color:#888;"><?php echo esc_html( $u->user_login ); ?></span>
							</td>
							<td style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></td>
							<td style="font-size:13px;"><?php echo $vault_count; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
									<input type="hidden" name="sft_user_id" value="<?php echo (int) $u->ID; ?>">
									<input type="submit" name="sft_demote_sft_admin" value="Demote to User" class="sft-btn" style="margin-right:4px;"
									       onclick="return confirm('Remove SFT Admin access from <?php echo esc_js( $u->display_name ); ?>? They will retain vault user access.');">
									<input type="submit" name="sft_revoke_user" value="Remove All" class="sft-btn sft-danger"
									       onclick="return confirm('Remove all SFT access from <?php echo esc_js( $u->display_name ); ?>?');">
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Vault Users -->
			<h3>Vault Users (<?php echo count( $vault_users ); ?>)</h3>
			<p style="font-size:13px;color:#555;margin-top:-8px;margin-bottom:12px;">Can create vaults, upload files, and share them. No access to the admin panel.</p>

			<?php if ( ! $vault_users ) : ?>
				<p style="color:#888;font-size:13px;">No users have vault access yet.</p>
			<?php else : ?>
				<table id="sft-vault-users-table" class="sft-table widefat striped">
					<thead><tr>
						<th>User</th>
						<th>Email</th>
						<th>Vaults</th>
						<th data-nosort>Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $vault_users as $u ) :
						$vault_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}sft_vaults WHERE owner_id = %d", $u->ID
						) );
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ); ?></strong><br>
								<span style="font-size:11px;color:#888;"><?php echo esc_html( $u->user_login ); ?></span>
							</td>
							<td style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></td>
							<td style="font-size:13px;"><?php echo $vault_count; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
									<input type="hidden" name="sft_user_id" value="<?php echo (int) $u->ID; ?>">
									<input type="submit" name="sft_promote_sft_admin" value="Make SFT Admin" class="sft-btn" style="margin-right:4px;">
									<input type="submit" name="sft_revoke_user" value="Revoke" class="sft-btn sft-danger"
									       onclick="return confirm('Revoke vault access for <?php echo esc_js( $u->display_name ); ?>?');">
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		sftSortTable('sft-admins-table');
		sftSortTable('sft-vault-users-table');
	});
	</script>
	<?php
}
