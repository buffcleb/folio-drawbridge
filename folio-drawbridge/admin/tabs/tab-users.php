<?php
/**
 * Users tab — manage Drawbridge user types.
 *
 * Two user types exist within the plugin:
 *   Drawbridge Admin — non-WordPress-admin users granted the folio_drawbridge_admin capability.
 *                They get full access to the Folio Drawbridge admin panel.
 *   User       — users granted the folio_drawbridge_use_vaults capability.
 *                They can create vaults, upload files, and share them.
 *
 * WordPress administrators (manage_options) always have full access to both
 * the admin panel and vault features and are not listed here.
 *
 * @package Folio_Drawbridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- data lives in this plugin's custom tables; $wpdb with prepared statements is the supported API and result sets are request-scoped.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters here are read-only display filters and sort state; no state changes occur on GET.

/**
 * Renders the expandable vault-count cell content and the hidden sub-row
 * listing a user's vaults, each linking to the vault inspector.
 *
 * Echoes nothing when the user owns no vaults (plain "0" is printed by the caller).
 *
 * @param array $vaults  Vault rows (id, name, status) owned by the user.
 * @param int   $user_id Owner user ID — used to build unique element IDs.
 */
function folio_drawbridge_render_user_vaults_subrow( array $vaults, int $user_id ): void {
	?>
	<tr data-subrow id="folio-drawbridge-user-vaults-<?php echo (int) $user_id; ?>" style="display:none;">
		<td colspan="4" style="background:#f6f7f7;padding:10px 14px 10px 28px;">
			<?php foreach ( $vaults as $v ) :
				$inspect_url = add_query_arg(
					[ 'page' => 'folio-drawbridge', 'tab' => 'vaults', 'vault_id' => (int) $v->id ],
					admin_url( 'admin.php' )
				);
			?>
				<div style="padding:3px 0;font-size:13px;">
					<a href="<?php echo esc_url( $inspect_url ); ?>"><?php echo esc_html( $v->name ); ?></a>
					<span class="folio-drawbridge-badge folio-drawbridge-badge-<?php echo esc_attr( $v->status ); ?>" style="margin-left:8px;"><?php echo esc_html( $v->status ); ?></span>
				</div>
			<?php endforeach; ?>
		</td>
	</tr>
	<?php
}

function folio_drawbridge_render_tab_users(): void {
	global $wpdb;

	$search        = sanitize_text_field( wp_unslash( $_GET['folio_drawbridge_user_search'] ?? '' ) );
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

	// Drawbridge admin users (folio_drawbridge_admin cap, but NOT WP admins — they're always included).
	$folio_drawbridge_admin_users = get_users( [
		'capability' => 'folio_drawbridge_manage_vaults',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	] );
	// Exclude WP administrators (they have folio_drawbridge_admin via the filter but shouldn't be listed).
	$folio_drawbridge_admin_users = array_filter( $folio_drawbridge_admin_users, fn( $u ) => ! $u->has_cap( 'manage_options' ) );

	// Vault users (folio_drawbridge_use_vaults cap, not Drawbridge admins).
	$vault_users = get_users( [
		'capability' => 'folio_drawbridge_use_vaults',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	] );
	$vault_users = array_filter( $vault_users, fn( $u ) => ! $u->has_cap( 'manage_options' ) && ! $u->has_cap( 'folio_drawbridge_manage_vaults' ) );

	$tab_url = add_query_arg( [ 'page' => 'folio-drawbridge', 'tab' => 'users' ], admin_url( 'admin.php' ) );

	// One query for every listed user's vaults rather than one per user.
	$listed_ids      = array_map( 'intval', array_merge(
		wp_list_pluck( $folio_drawbridge_admin_users, 'ID' ),
		wp_list_pluck( $vault_users, 'ID' )
	) );
	$vaults_by_owner = folio_drawbridge_get_vaults_by_owner( $listed_ids );
	?>

	<div style="display:flex; gap:24px; align-items:flex-start; margin-top:20px; flex-wrap:wrap;">

		<!-- ── Left: search + grant panel ──────────────────────────────────── -->
		<div class="folio-drawbridge-card" style="flex:0 0 300px; margin-top:0;">
			<h3 style="margin-top:0;">Add User</h3>
			<p style="font-size:13px;color:#555;">Search by username or email to grant access.</p>

			<form method="get">
				<input type="hidden" name="page" value="folio-drawbridge">
				<input type="hidden" name="tab"  value="users">
				<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Username or Email</label>
				<input type="text" name="folio_drawbridge_user_search" value="<?php echo esc_attr( $search ); ?>"
				       style="width:100%;margin-bottom:8px;" placeholder="e.g. jsmith or j@example.com">
				<input type="submit" value="Search" class="button" style="width:100%;">
			</form>

			<?php if ( $search_error ) : ?>
				<p style="color:#d63638;font-size:13px;margin-top:10px;"><?php echo esc_html( $search_error ); ?></p>
			<?php endif; ?>

			<?php if ( $search_result ) :
				$is_wp_admin   = $search_result->has_cap( 'manage_options' );
				$is_drawbridge_admin  = ! $is_wp_admin && $search_result->has_cap( 'folio_drawbridge_manage_vaults' );
				$is_vault_user = ! $is_wp_admin && ! $is_drawbridge_admin && $search_result->has_cap( 'folio_drawbridge_use_vaults' );
			?>
				<div style="margin-top:14px;padding:12px;background:#f6f7f7;border-radius:4px;border:1px solid #ddd;">
					<strong><?php echo esc_html( $search_result->display_name ); ?></strong><br>
					<span style="font-size:12px;color:#888;"><?php echo esc_html( $search_result->user_email ); ?></span>

					<?php if ( $is_wp_admin ) : ?>
						<p style="font-size:12px;color:#2271b1;margin:8px 0 0;">WordPress administrator — always has full access.</p>

					<?php elseif ( $is_drawbridge_admin ) : ?>
						<p style="font-size:12px;font-weight:600;color:#0a3622;margin:8px 0 4px;">✓ Drawbridge Admin</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
							<input type="hidden" name="folio_drawbridge_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="folio_drawbridge_demote_drawbridge_admin" value="Demote to User" class="button" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="folio_drawbridge_revoke_user" value="Remove All Access" class="button folio-drawbridge-danger" style="width:100%;">
						</form>

					<?php elseif ( $is_vault_user ) : ?>
						<p style="font-size:12px;font-weight:600;color:#0a3622;margin:8px 0 4px;">✓ Vault User</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
							<input type="hidden" name="folio_drawbridge_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="folio_drawbridge_promote_drawbridge_admin" value="Promote to Drawbridge Admin" class="button" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="folio_drawbridge_revoke_user" value="Revoke Access" class="button folio-drawbridge-danger" style="width:100%;">
						</form>

					<?php else : ?>
						<p style="font-size:12px;color:#555;margin:8px 0 4px;">No Drawbridge access yet.</p>
						<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
							<input type="hidden" name="folio_drawbridge_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="folio_drawbridge_grant_user" value="Grant Vault Access" class="button button-primary" style="width:100%;margin-bottom:6px;">
							<input type="submit" name="folio_drawbridge_grant_drawbridge_admin" value="Grant Drawbridge Admin Access" class="button" style="width:100%;">
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- ── Right: user lists ────────────────────────────────────────────── -->
		<div style="flex:1; min-width:300px;">

			<!-- Drawbridge Admins -->
			<h3 style="margin-top:0;">Drawbridge Admins (<?php echo count( $folio_drawbridge_admin_users ); ?>)</h3>
			<p style="font-size:13px;color:#555;margin-top:-8px;margin-bottom:12px;">Full access to the Folio Drawbridge admin panel. Does not require WordPress administrator privileges.</p>

			<?php if ( ! $folio_drawbridge_admin_users ) : ?>
				<p style="color:#888;font-size:13px;margin-bottom:20px;">No Drawbridge admins designated yet.</p>
			<?php else : ?>
				<table id="folio-drawbridge-admins-table" data-folio-drawbridge-sortable class="folio-drawbridge-table widefat striped" style="margin-bottom:24px;">
					<thead><tr>
						<th>User</th>
						<th>Email</th>
						<th>Vaults</th>
						<th data-nosort>Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $folio_drawbridge_admin_users as $u ) :
						$user_vaults = $vaults_by_owner[ (int) $u->ID ] ?? [];
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ); ?></strong><br>
								<span style="font-size:11px;color:#888;"><?php echo esc_html( $u->user_login ); ?></span>
							</td>
							<td style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></td>
							<td style="font-size:13px;">
								<?php if ( $user_vaults ) : ?>
									<a href="#" class="folio-drawbridge-btn" onclick="return folioDrawbridgeToggleUserVaults(<?php echo (int) $u->ID; ?>, this);"><?php echo count( $user_vaults ); ?> vault<?php echo count( $user_vaults ) === 1 ? '' : 's'; ?> <span>▸</span></a>
								<?php else : ?>
									0
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
									<input type="hidden" name="folio_drawbridge_user_id" value="<?php echo (int) $u->ID; ?>">
									<input type="submit" name="folio_drawbridge_demote_drawbridge_admin" value="Demote to User" class="folio-drawbridge-btn" style="margin-right:4px;"
									       onclick="return confirm('Remove Drawbridge Admin access from <?php echo esc_js( $u->display_name ); ?>? They will retain vault user access.');">
									<input type="submit" name="folio_drawbridge_revoke_user" value="Remove All" class="folio-drawbridge-btn folio-drawbridge-danger"
									       onclick="return confirm('Remove all Drawbridge access from <?php echo esc_js( $u->display_name ); ?>?');">
								</form>
							</td>
						</tr>
						<?php if ( $user_vaults ) {
							folio_drawbridge_render_user_vaults_subrow( $user_vaults, (int) $u->ID );
						} ?>
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
				<table id="folio-drawbridge-vault-users-table" data-folio-drawbridge-sortable class="folio-drawbridge-table widefat striped">
					<thead><tr>
						<th>User</th>
						<th>Email</th>
						<th>Vaults</th>
						<th data-nosort>Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $vault_users as $u ) :
						$user_vaults = $vaults_by_owner[ (int) $u->ID ] ?? [];
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ); ?></strong><br>
								<span style="font-size:11px;color:#888;"><?php echo esc_html( $u->user_login ); ?></span>
							</td>
							<td style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></td>
							<td style="font-size:13px;">
								<?php if ( $user_vaults ) : ?>
									<a href="#" class="folio-drawbridge-btn" onclick="return folioDrawbridgeToggleUserVaults(<?php echo (int) $u->ID; ?>, this);"><?php echo count( $user_vaults ); ?> vault<?php echo count( $user_vaults ) === 1 ? '' : 's'; ?> <span>▸</span></a>
								<?php else : ?>
									0
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( $tab_url ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'folio_drawbridge_admin_action', 'folio_drawbridge_nonce' ); ?>
									<input type="hidden" name="folio_drawbridge_user_id" value="<?php echo (int) $u->ID; ?>">
									<input type="submit" name="folio_drawbridge_promote_drawbridge_admin" value="Make Drawbridge Admin" class="folio-drawbridge-btn" style="margin-right:4px;">
									<input type="submit" name="folio_drawbridge_revoke_user" value="Revoke" class="folio-drawbridge-btn folio-drawbridge-danger"
									       onclick="return confirm('Revoke vault access for <?php echo esc_js( $u->display_name ); ?>?');">
								</form>
							</td>
						</tr>
						<?php if ( $user_vaults ) {
							folio_drawbridge_render_user_vaults_subrow( $user_vaults, (int) $u->ID );
						} ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
