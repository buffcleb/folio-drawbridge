<?php
/**
 * Cryptographic primitives for WP Secure File Transfer Pro.
 *
 * Key derivation:
 *   Master key  — 32 random bytes stored as hex in wp_options (autoload=false),
 *                 or overridden by defining SFT_MASTER_KEY (64 hex chars) in
 *                 wp-config.php to keep the key out of the database entirely.
 *   Vault key   — HMAC-SHA256(vault_salt, master_key) → 32 bytes → AES-256 key.
 *                 Each vault has its own random 32-byte salt stored in the DB,
 *                 so a compromised vault record does not expose other vaults.
 *
 * File encryption:
 *   Algorithm   — AES-256-CBC via PHP's openssl extension.
 *   IV          — 16 random bytes generated per file, stored as hex in sft_files.
 *   Storage     — Ciphertext written to SFT_VAULT_DIR/{stored_name}; the dir is
 *                 protected by .htaccess (Deny from all) so files cannot be
 *                 downloaded directly — they must be served through PHP.
 *
 * 2FA OTP:
 *   Format      — 6-digit zero-padded integer, cryptographically random.
 *   Hashing     — wp_hash_password (bcrypt) so brute-force of the hash is slow.
 *   Verification — wp_check_password; caller must enforce attempt limits.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Master key ───────────────────────────────────────────────────────────────

/**
 * Returns the 32-byte binary master encryption key.
 *
 * Priority: SFT_MASTER_KEY constant → wp_options fallback (generated on first call).
 */
function sft_get_master_key(): string {
	if ( defined( 'SFT_MASTER_KEY' ) ) {
		$hex = SFT_MASTER_KEY;
		if ( strlen( $hex ) === 64 && ctype_xdigit( $hex ) ) {
			return hex2bin( $hex );
		}
		// Misconfigured constant — log and fall through to DB key.
		error_log( 'WP Secure File Transfer Pro: SFT_MASTER_KEY is defined but is not a valid 64-character hex string. Falling back to database key.' );
	}

	$hex = get_option( 'sft_master_key' );
	if ( ! $hex || strlen( $hex ) !== 64 ) {
		$hex = bin2hex( random_bytes( 32 ) );
		// autoload=false: this option is only fetched when actually needed.
		update_option( 'sft_master_key', $hex, false );
	}

	return hex2bin( $hex );
}

// ─── Vault key derivation ─────────────────────────────────────────────────────

/**
 * Derives the 32-byte AES-256 key for a specific vault.
 *
 * Uses HMAC-SHA256 so the vault salt is the "message" and the master key is
 * the "secret". Different salts → different keys → vault isolation.
 */
function sft_derive_vault_key( string $vault_salt ): string {
	return hash_hmac( 'sha256', $vault_salt, sft_get_master_key(), true );
}

/**
 * Generates a random 32-byte hex vault salt for use in key derivation.
 */
function sft_generate_vault_salt(): string {
	return bin2hex( random_bytes( 32 ) );
}

// ─── File encryption / decryption ─────────────────────────────────────────────

// Internal streaming chunk size: 1 MiB — always a multiple of the AES block (16 bytes).
define( 'SFT_STREAM_CHUNK', 1048576 );

/**
 * Encrypts $source_path and writes ciphertext to $dest_path using streaming
 * AES-256-CBC so that arbitrarily large files never have to reside in memory
 * all at once.
 *
 * Each intermediate chunk of SFT_STREAM_CHUNK bytes is encrypted without
 * padding (OPENSSL_ZERO_PADDING) so the ciphertext is the same size as the
 * plaintext. The final chunk receives standard PKCS7 padding applied manually
 * before encryption, ensuring the last ciphertext block is also a multiple of
 * 16 bytes. The CBC IV is forwarded between chunks by taking the last 16 bytes
 * of each ciphertext block.
 *
 * @return string|false Hex-encoded per-file IV on success, false on failure.
 */
function sft_encrypt_file( string $source_path, string $dest_path, string $vault_salt ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return false;
	}

	$in = @fopen( $source_path, 'rb' );
	if ( ! $in ) {
		return false;
	}

	$out = @fopen( $dest_path, 'wb' );
	if ( ! $out ) {
		fclose( $in );
		return false;
	}

	$key        = sft_derive_vault_key( $vault_salt );
	$iv         = random_bytes( 16 );
	$current_iv = $iv;
	$block      = 16;
	$success    = true;

	while ( ! feof( $in ) ) {
		$plain_chunk = fread( $in, SFT_STREAM_CHUNK );

		if ( $plain_chunk === false ) {
			$success = false;
			break;
		}

		// Apply PKCS7 padding to the final chunk.
		// This is required even when the chunk length is already a multiple of
		// the block size — a full padding block is appended in that case.
		if ( feof( $in ) ) {
			$pad         = $block - ( strlen( $plain_chunk ) % $block );
			$plain_chunk .= str_repeat( chr( $pad ), $pad );
		}

		$cipher_chunk = openssl_encrypt(
			$plain_chunk,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$current_iv
		);

		if ( $cipher_chunk === false ) {
			$success = false;
			break;
		}

		fwrite( $out, $cipher_chunk );

		// Last 16 bytes of the ciphertext become the IV for the next block.
		$current_iv = substr( $cipher_chunk, -$block );
	}

	fclose( $in );
	fclose( $out );

	if ( ! $success ) {
		@unlink( $dest_path );
		return false;
	}

	return bin2hex( $iv );
}

/**
 * Streams a decrypted file directly to PHP output (i.e. the browser).
 *
 * This is the streaming counterpart to sft_encrypt_file(). It must be called
 * AFTER all HTTP headers have been sent and before any other output. It never
 * holds more than two SFT_STREAM_CHUNK buffers in memory simultaneously.
 *
 * $plaintext_size (from the sft_files.file_size column) is required so the
 * function can determine the exact size of the final ciphertext block and
 * strip PKCS7 padding correctly, including the edge case where the original
 * file length is a multiple of SFT_STREAM_CHUNK.
 *
 * @param string $source_path   Absolute path to the .enc file on disk.
 * @param string $vault_salt    The owning vault's salt (for key derivation).
 * @param string $iv_hex        Hex-encoded IV from the sft_files record.
 * @param int    $plaintext_size Original file size in bytes (stored in DB).
 * @return bool True on success, false on failure.
 */
function sft_stream_decrypt_file(
	string $source_path,
	string $vault_salt,
	string $iv_hex,
	int    $plaintext_size
): bool {
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return false;
	}

	$in = @fopen( $source_path, 'rb' );
	if ( ! $in ) {
		return false;
	}

	$key   = sft_derive_vault_key( $vault_salt );
	$iv    = hex2bin( $iv_hex );
	$block = 16;

	// Determine how many intermediate (un-padded) chunks precede the final one.
	// Using ($plaintext_size - 1) ensures that an exact multiple of SFT_STREAM_CHUNK
	// gives one fewer intermediate chunk (the full last chunk carries the padding).
	$intermediate_chunks = (int) ( ( $plaintext_size - 1 ) / SFT_STREAM_CHUNK );

	// Compute the plaintext byte count in the last chunk (1–SFT_STREAM_CHUNK).
	$last_plain_bytes = $plaintext_size - $intermediate_chunks * SFT_STREAM_CHUNK;

	// Compute the corresponding ciphertext byte count for the last chunk.
	// PKCS7 pads to the next block boundary, with a mandatory full extra block
	// when the input is already block-aligned.
	$last_cipher_bytes = ( $last_plain_bytes % $block === 0 )
		? $last_plain_bytes + $block
		: (int) ( ceil( $last_plain_bytes / $block ) ) * $block;

	$current_iv = $iv;
	$success    = true;

	// Process intermediate chunks — no padding, ciphertext == SFT_STREAM_CHUNK bytes.
	for ( $i = 0; $i < $intermediate_chunks; $i++ ) {
		$cipher_chunk = fread( $in, SFT_STREAM_CHUNK );

		if ( $cipher_chunk === false || strlen( $cipher_chunk ) !== SFT_STREAM_CHUNK ) {
			$success = false;
			break;
		}

		$decrypted = openssl_decrypt(
			$cipher_chunk,
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
			$current_iv
		);

		if ( $decrypted === false ) {
			$success = false;
			break;
		}

		echo $decrypted;
		$current_iv = substr( $cipher_chunk, -$block );
	}

	// Process the final chunk — has PKCS7 padding that must be stripped.
	if ( $success ) {
		$cipher_chunk = fread( $in, $last_cipher_bytes );

		if ( $cipher_chunk === false || strlen( $cipher_chunk ) !== $last_cipher_bytes ) {
			$success = false;
		} else {
			$decrypted = openssl_decrypt(
				$cipher_chunk,
				'AES-256-CBC',
				$key,
				OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
				$current_iv
			);

			if ( $decrypted === false ) {
				$success = false;
			} else {
				// Strip PKCS7 padding.
				$pad = ord( $decrypted[-1] );
				echo substr( $decrypted, 0, -$pad );
			}
		}
	}

	fclose( $in );
	return $success;
}

/**
 * Decrypts a vault file and writes the plaintext to $dest_path on disk.
 * Used by the ZIP bulk-download handler to build an archive server-side.
 *
 * @param string $source_path    Path to the encrypted file.
 * @param string $vault_salt     Vault's salt for key derivation.
 * @param string $iv_hex         Per-file IV stored in the database.
 * @param int    $plaintext_size Unencrypted file size in bytes.
 * @param string $dest_path      Writable path for the decrypted output.
 * @return bool True on success.
 */
function sft_decrypt_file_to_path(
	string $source_path,
	string $vault_salt,
	string $iv_hex,
	int    $plaintext_size,
	string $dest_path
): bool {
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return false;
	}

	$in  = @fopen( $source_path, 'rb' );
	$out = @fopen( $dest_path, 'wb' );
	if ( ! $in || ! $out ) {
		if ( $in )  fclose( $in );
		if ( $out ) fclose( $out );
		return false;
	}

	$key   = sft_derive_vault_key( $vault_salt );
	$iv    = hex2bin( $iv_hex );
	$block = 16;

	$intermediate_chunks = (int) ( ( $plaintext_size - 1 ) / SFT_STREAM_CHUNK );
	$last_plain_bytes    = $plaintext_size - $intermediate_chunks * SFT_STREAM_CHUNK;
	$last_cipher_bytes   = ( $last_plain_bytes % $block === 0 )
		? $last_plain_bytes + $block
		: (int) ( ceil( $last_plain_bytes / $block ) ) * $block;

	$current_iv = $iv;
	$success    = true;

	for ( $i = 0; $i < $intermediate_chunks; $i++ ) {
		$cipher_chunk = fread( $in, SFT_STREAM_CHUNK );
		if ( $cipher_chunk === false || strlen( $cipher_chunk ) !== SFT_STREAM_CHUNK ) {
			$success = false;
			break;
		}
		$decrypted = openssl_decrypt(
			$cipher_chunk, 'AES-256-CBC', $key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv
		);
		if ( $decrypted === false ) {
			$success = false;
			break;
		}
		fwrite( $out, $decrypted );
		$current_iv = substr( $cipher_chunk, -$block );
	}

	if ( $success ) {
		$cipher_chunk = fread( $in, $last_cipher_bytes );
		if ( $cipher_chunk === false || strlen( $cipher_chunk ) !== $last_cipher_bytes ) {
			$success = false;
		} else {
			$decrypted = openssl_decrypt(
				$cipher_chunk, 'AES-256-CBC', $key,
				OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $current_iv
			);
			if ( $decrypted === false ) {
				$success = false;
			} else {
				$pad = ord( $decrypted[-1] );
				fwrite( $out, substr( $decrypted, 0, -$pad ) );
			}
		}
	}

	fclose( $in );
	fclose( $out );
	return $success;
}

// ─── Random token generation ──────────────────────────────────────────────────

/**
 * Returns a cryptographically random hex token of $bytes bytes length.
 * Default 32 bytes → 64 hex chars.
 */
function sft_generate_token( int $bytes = 32 ): string {
	return bin2hex( random_bytes( $bytes ) );
}

// ─── OTP (one-time password) ──────────────────────────────────────────────────

/**
 * Generates a cryptographically random 6-digit OTP string (zero-padded).
 */
function sft_generate_otp(): string {
	return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
}

/**
 * Returns a bcrypt hash of the OTP suitable for storage.
 */
function sft_hash_otp( string $otp ): string {
	return wp_hash_password( $otp );
}

/**
 * Verifies a plaintext OTP against its stored bcrypt hash.
 */
function sft_verify_otp( string $otp, string $hash ): bool {
	return (bool) wp_check_password( $otp, $hash );
}
