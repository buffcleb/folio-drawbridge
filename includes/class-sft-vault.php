<?php
/**
 * Vault and file management for WP Secure File Transfer Pro.
 *
 * A "vault" is a named container owned by a WordPress user. Files uploaded
 * to a vault are AES-256-CBC encrypted before being written to disk.
 * The vault record holds a random salt used to derive the AES key from the
 * site-wide master key — so each vault has a unique encryption key.
 *
 * Vault statuses: active | expired | revoked | archived
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Vault CRUD ───────────────────────────────────────────────────────────────

/**
 * Creates a new vault and writes the audit event.
 *
 * @param int    $owner_id    WP user ID of the vault owner.
 * @param string $name        Vault display name (max 255 chars).
 * @param string $description Optional description.
 * @param string $expires_at  MySQL datetime or empty string for no expiry.
 * @return int|false New vault ID, or false on failure.
 */
function sft_create_vault( int $owner_id, string $name, string $description = '', string $expires_at = '' ) {
	global $wpdb;

	$now = current_time( 'mysql', true );

	$result = $wpdb->insert(
		"{$wpdb->prefix}sft_vaults",
		[
			'owner_id'    => $owner_id,
			'name'        => sanitize_text_field( $name ),
			'description' => sanitize_textarea_field( $description ),
			'vault_salt'  => sft_generate_vault_salt(),
			'status'      => 'active',
			'expires_at'  => $expires_at ?: null,
			'created_at'  => $now,
			'updated_at'  => $now,
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( ! $result ) {
		return false;
	}

	$vault_id = (int) $wpdb->insert_id;

	sft_log(
		SFT_EVT_VAULT_CREATED,
		$vault_id,
		null,
		[ 'name' => $name, 'owner_id' => $owner_id, 'expires_at' => $expires_at ?: 'never' ]
	);

	return $vault_id;
}

/**
 * Returns a single vault row, or null if not found.
 */
function sft_get_vault( int $vault_id ): ?object {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sft_vaults WHERE id = %d", $vault_id )
	);

	return $row ?: null;
}

/**
 * Returns all vaults owned by $owner_id, ordered newest first.
 *
 * @param int   $owner_id
 * @param array $args  Optional: status (string), per_page (int), paged (int).
 */
function sft_get_user_vaults( int $owner_id, array $args = [] ): array {
	global $wpdb;

	$status   = sanitize_key( $args['status'] ?? '' );
	$per_page = (int) ( $args['per_page'] ?? 0 ); // 0 = no limit
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

	$where  = [ 'owner_id = %d' ];
	$values = [ $owner_id ];

	if ( $status ) {
		$where[]  = 'status = %s';
		$values[] = $status;
	}

	$where_sql = 'WHERE ' . implode( ' AND ', $where );
	$limit_sql = $per_page > 0 ? $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, ( $paged - 1 ) * $per_page ) : '';

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sft_vaults {$where_sql} ORDER BY created_at DESC {$limit_sql}", $values )
	) ?: [];
}

/**
 * Returns all vaults (admin view) with optional filtering and pagination.
 */
function sft_get_all_vaults( array $args = [] ): array {
	global $wpdb;

	[ $where_sql, $values, $limit_sql ] = sft_vaults_query_parts( $args );

	$sql = "SELECT v.*, u.user_login as owner_login FROM {$wpdb->prefix}sft_vaults v
	        LEFT JOIN {$wpdb->users} u ON u.ID = v.owner_id
	        {$where_sql} ORDER BY v.created_at DESC {$limit_sql}";

	return $values
		? ( $wpdb->get_results( $wpdb->prepare( $sql, $values ) ) ?: [] )
		: ( $wpdb->get_results( $sql ) ?: [] );
}

function sft_count_all_vaults( array $args = [] ): int {
	global $wpdb;

	[ $where_sql, $values ] = sft_vaults_query_parts( $args );

	$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sft_vaults v {$where_sql}";

	return (int) ( $values
		? $wpdb->get_var( $wpdb->prepare( $sql, $values ) )
		: $wpdb->get_var( $sql ) );
}

/** @internal */
function sft_vaults_query_parts( array $args ): array {
	$where  = [];
	$values = [];

	if ( ! empty( $args['status'] ) ) {
		$where[]  = 'v.status = %s';
		$values[] = sanitize_key( $args['status'] );
	}
	if ( ! empty( $args['owner_id'] ) ) {
		$where[]  = 'v.owner_id = %d';
		$values[] = (int) $args['owner_id'];
	}
	if ( ! empty( $args['search'] ) ) {
		$where[]  = 'v.name LIKE %s';
		global $wpdb;
		$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
	}

	$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

	$per_page = (int) ( $args['per_page'] ?? 25 );
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$limit_sql = $per_page > 0 ? "LIMIT {$per_page} OFFSET " . ( ( $paged - 1 ) * $per_page ) : '';

	return [ $where_sql, $values, $limit_sql ];
}

/**
 * Updates vault status and writes an audit event.
 */
function sft_update_vault_status( int $vault_id, string $new_status, ?int $actor_id = null ): bool {
	global $wpdb;

	$allowed = [ 'active', 'expired', 'revoked', 'archived' ];
	if ( ! in_array( $new_status, $allowed, true ) ) {
		return false;
	}

	$result = $wpdb->update(
		"{$wpdb->prefix}sft_vaults",
		[ 'status' => $new_status, 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $vault_id ],
		[ '%s', '%s' ],
		[ '%d' ]
	);

	if ( $result !== false ) {
		sft_log( SFT_EVT_VAULT_STATUS, $vault_id, null, [ 'new_status' => $new_status ], $actor_id );
	}

	return $result !== false;
}

/**
 * Updates a vault's expiry date. Pass an empty string to remove the expiry.
 */
function sft_update_vault_expiry( int $vault_id, string $expires_at, int $actor_id ): bool {
	global $wpdb;

	$result = $wpdb->update(
		"{$wpdb->prefix}sft_vaults",
		[
			'expires_at' => $expires_at ?: null,
			'updated_at' => current_time( 'mysql', true ),
		],
		[ 'id' => $vault_id ],
		[ '%s', '%s' ],
		[ '%d' ]
	);

	if ( $result !== false ) {
		sft_log(
			SFT_EVT_VAULT_STATUS,
			$vault_id,
			null,
			[ 'action' => 'expiry_updated', 'expires_at' => $expires_at ?: 'never' ],
			$actor_id
		);
	}

	return $result !== false;
}

/**
 * Permanently deletes a vault, all its files (from disk + DB), and all shares.
 * Writes a single audit event before deletion.
 */
function sft_delete_vault( int $vault_id ): bool {
	global $wpdb;

	$vault = sft_get_vault( $vault_id );
	if ( ! $vault ) {
		return false;
	}

	sft_log( SFT_EVT_VAULT_DELETED, $vault_id, null, [ 'name' => $vault->name ] );

	// Delete all encrypted files from disk.
	$files = sft_get_vault_files( $vault_id );
	foreach ( $files as $file ) {
		$path = sft_vault_file_path( $vault_id, $file->stored_name );
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	// Remove the now-empty vault subdirectory.
	$subdir = SFT_VAULT_DIR . $vault_id . '/';
	if ( is_dir( $subdir ) ) {
		rmdir( $subdir );
	}

	// Cascade delete in dependency order.
	$wpdb->delete( "{$wpdb->prefix}sft_files",  [ 'vault_id' => $vault_id ], [ '%d' ] );
	$wpdb->delete( "{$wpdb->prefix}sft_shares", [ 'vault_id' => $vault_id ], [ '%d' ] );
	$wpdb->delete( "{$wpdb->prefix}sft_vaults", [ 'id'       => $vault_id ], [ '%d' ] );

	return true;
}

// ─── File management ──────────────────────────────────────────────────────────

/**
 * Encrypts and stores an uploaded file in the vault.
 *
 * Validates the $_FILES entry, then delegates to sft_encrypt_and_store_file().
 *
 * @param int   $vault_id    Target vault.
 * @param array $file        Single element from $_FILES.
 * @param int   $uploader_id WP user ID performing the upload.
 * @return int|WP_Error File ID on success, or WP_Error on failure.
 */
function sft_upload_file_to_vault( int $vault_id, array $file, int $uploader_id ) {
	if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
		return new WP_Error( 'upload_error', sft_upload_error_message( (int) $file['error'] ) );
	}

	return sft_encrypt_and_store_file(
		$vault_id,
		$file['tmp_name'],
		$file['name'],
		(int) $file['size'],
		$uploader_id
	);
}

/**
 * Core encrypt-and-store routine. Called by sft_upload_file_to_vault() for
 * normal single-POST uploads and by the chunk-assembly handler for large files.
 *
 * @param int    $vault_id      Target vault.
 * @param string $tmp_path      Absolute path to the plaintext source file.
 * @param string $original_name Original filename shown to users.
 * @param int    $file_size     File size in bytes (used for the DB record and limit check).
 * @param int    $uploader_id   WP user ID performing the upload.
 * @return int|WP_Error File ID on success, or WP_Error on failure.
 */
function sft_encrypt_and_store_file(
	int    $vault_id,
	string $tmp_path,
	string $original_name,
	int    $file_size,
	int    $uploader_id
) {
	global $wpdb;

	$vault = sft_get_vault( $vault_id );
	if ( ! $vault || $vault->status !== 'active' ) {
		return new WP_Error( 'invalid_vault', 'Vault not found or not active.' );
	}

	$max_mb    = (int) get_option( 'sft_max_file_mb', 50 );
	$max_bytes = $max_mb * 1024 * 1024;
	if ( $file_size > $max_bytes ) {
		return new WP_Error( 'file_too_large', "File exceeds the {$max_mb} MB limit." );
	}

	$vault_subdir = sft_ensure_vault_subdir( $vault_id );
	$stored_name  = sft_generate_token( 16 ) . '.enc';
	$dest_path    = $vault_subdir . $stored_name;
	$original     = sanitize_file_name( $original_name );

	// Detect MIME using the original filename for extension matching.
	$detected = wp_check_filetype_and_ext( $tmp_path, $original );
	$allowed  = wp_get_mime_types();
	$mime     = ( ! empty( $detected['type'] ) && in_array( $detected['type'], $allowed, true ) )
		? $detected['type']
		: 'application/octet-stream';

	$iv_hex = sft_encrypt_file( $tmp_path, $dest_path, $vault->vault_salt );
	if ( $iv_hex === false ) {
		return new WP_Error( 'encrypt_failed', 'File encryption failed.' );
	}

	$wpdb->insert(
		"{$wpdb->prefix}sft_files",
		[
			'vault_id'      => $vault_id,
			'original_name' => $original,
			'stored_name'   => $stored_name,
			'mime_type'     => $mime,
			'file_size'     => $file_size,
			'iv'            => $iv_hex,
			'uploaded_by'   => $uploader_id,
			'uploaded_at'   => current_time( 'mysql', true ),
		],
		[ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
	);

	$file_id = (int) $wpdb->insert_id;

	$wpdb->update(
		"{$wpdb->prefix}sft_vaults",
		[ 'updated_at' => current_time( 'mysql', true ) ],
		[ 'id' => $vault_id ],
		[ '%s' ],
		[ '%d' ]
	);

	sft_log(
		SFT_EVT_FILE_UPLOADED,
		$vault_id,
		null,
		[ 'file_id' => $file_id, 'original_name' => $original, 'size_bytes' => $file_size ],
		$uploader_id
	);

	return $file_id;
}

/**
 * Returns all file records for a vault.
 */
function sft_get_vault_files( int $vault_id ): array {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sft_files WHERE vault_id = %d ORDER BY uploaded_at DESC",
			$vault_id
		)
	) ?: [];
}

/**
 * Returns a single file record, or null.
 */
function sft_get_file( int $file_id ): ?object {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sft_files WHERE id = %d", $file_id )
	);

	return $row ?: null;
}

/**
 * Deletes a file record and its on-disk ciphertext.
 */
function sft_delete_file( int $file_id, int $actor_id ): bool {
	global $wpdb;

	$file = sft_get_file( $file_id );
	if ( ! $file ) {
		return false;
	}

	$path = sft_vault_file_path( (int) $file->vault_id, $file->stored_name );
	if ( file_exists( $path ) ) {
		unlink( $path );
	}

	sft_log(
		SFT_EVT_FILE_DELETED,
		(int) $file->vault_id,
		null,
		[ 'file_id' => $file_id, 'original_name' => $file->original_name ],
		$actor_id
	);

	$wpdb->delete( "{$wpdb->prefix}sft_files", [ 'id' => $file_id ], [ '%d' ] );

	return true;
}

// ─── File serving ─────────────────────────────────────────────────────────────

/**
 * Decrypts and streams a file to the browser, then exits.
 *
 * Logs either FILE_DOWNLOADED (external recipient) or FILE_SERVED_ADMIN (admin).
 *
 * @param object   $file      Row from sft_files.
 * @param object   $vault     Row from sft_vaults (for vault_salt).
 * @param int|null $share_id  Associated share ID (null for admin access).
 * @param bool     $is_admin  True when served by an admin action.
 */
function sft_serve_file( object $file, object $vault, ?int $share_id = null, bool $is_admin = false ): void {
	$path = sft_vault_file_path( (int) $vault->id, $file->stored_name );

	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		wp_die( 'File not found. Please contact the site administrator.' );
	}

	$event = $is_admin ? SFT_EVT_FILE_SERVED_ADMIN : SFT_EVT_FILE_DOWNLOADED;

	sft_log(
		$event,
		(int) $vault->id,
		$share_id,
		[
			'file_id'       => (int) $file->id,
			'original_name' => $file->original_name,
			'size_bytes'    => (int) $file->file_size,
		]
	);

	header( 'Content-Type: ' . $file->mime_type );
	header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file->original_name ) . '"' );
	header( 'Content-Length: ' . (int) $file->file_size );
	header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'X-Content-Type-Options: nosniff' );

	sft_stream_decrypt_file( $path, $vault->vault_salt, $file->iv, (int) $file->file_size );
	exit;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns a safe MIME type validated against the actual file contents.
 */
function sft_safe_mime( string $tmp_path, string $supplied_type ): string {
	$allowed  = wp_get_mime_types();
	$detected = wp_check_filetype_and_ext( $tmp_path, basename( $tmp_path ) );

	if ( ! empty( $detected['type'] ) && array_search( $detected['type'], $allowed, true ) !== false ) {
		return $detected['type'];
	}

	if ( in_array( $supplied_type, $allowed, true ) ) {
		return $supplied_type;
	}

	return 'application/octet-stream';
}

// ─── Chunked upload helpers ───────────────────────────────────────────────────

/**
 * Returns the safe chunk size in bytes derived from the server's PHP ini limits.
 * 75% of the lower of upload_max_filesize and post_max_size, clamped to [256 KB, 4 MB].
 */
function sft_chunk_size_bytes(): int {
	$to_bytes = static function ( string $val ): int {
		$val  = trim( $val );
		$unit = strtolower( substr( $val, -1 ) );
		$num  = (int) $val;
		switch ( $unit ) {
			case 'g': return $num * 1073741824;
			case 'm': return $num * 1048576;
			case 'k': return $num * 1024;
		}
		return max( 1, $num );
	};

	$upload = $to_bytes( ini_get( 'upload_max_filesize' ) ?: '2M' );
	$post   = $to_bytes( ini_get( 'post_max_size' )       ?: '8M' );
	$safe   = (int) ( min( $upload, $post ) * 0.75 );

	return max( 262144, min( 4194304, $safe ) ); // 256 KB – 4 MB
}

/**
 * Creates and protects the chunk temp directory.
 * Returns the absolute path with trailing slash.
 */
function sft_ensure_chunks_dir(): string {
	$dir = WP_CONTENT_DIR . '/uploads/sft-chunks/';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$htaccess = $dir . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}

	$index = $dir . 'index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" );
	}

	return $dir;
}

/**
 * Deletes orphaned chunk upload directories older than $max_age seconds.
 * Called by the lifecycle cron to recover disk space from abandoned uploads.
 *
 * @return int Number of directories removed.
 */
function sft_cleanup_orphaned_chunks( int $max_age = 86400 ): int {
	$base  = WP_CONTENT_DIR . '/uploads/sft-chunks/';
	$count = 0;

	if ( ! is_dir( $base ) ) {
		return 0;
	}

	$cutoff = time() - $max_age;

	foreach ( new DirectoryIterator( $base ) as $item ) {
		if ( $item->isDot() || $item->isFile() ) {
			continue;
		}
		if ( $item->getMTime() < $cutoff ) {
			// Remove all .part files inside, then the directory itself.
			foreach ( glob( $item->getPathname() . '/*.part' ) as $part ) {
				unlink( $part );
			}
			@rmdir( $item->getPathname() );
			++$count;
		}
	}

	// Also remove orphaned .tmp assembled files.
	foreach ( glob( $base . '*.tmp' ) as $tmp ) {
		if ( filemtime( $tmp ) < $cutoff ) {
			unlink( $tmp );
		}
	}

	return $count;
}

function sft_upload_error_message( int $code ): string {
	$messages = [
		UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
		UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
		UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
		UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
		UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
		UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
		UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
	];

	return $messages[ $code ] ?? 'Unknown upload error.';
}

/**
 * Returns count of files in a vault.
 */
function sft_get_vault_file_count( int $vault_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sft_files WHERE vault_id = %d", $vault_id )
	);
}

/**
 * Returns total encrypted file size (bytes) for a vault.
 */
function sft_get_vault_total_size( int $vault_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COALESCE(SUM(file_size),0) FROM {$wpdb->prefix}sft_files WHERE vault_id = %d", $vault_id )
	);
}
