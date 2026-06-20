# Security Reference

This document describes the security architecture of Folio Drawbridge and the results of its internal security review.

---

## Cryptographic Design

### File Encryption

- **Algorithm:** AES-256-CBC via PHP `openssl_encrypt` / `openssl_decrypt`
- **IV:** 16 cryptographically random bytes per file, generated with `random_bytes(16)`, stored as hex in `sft_files.iv`
- **Master key:** 32 bytes (64 hex chars), defined as `SFT_MASTER_KEY` constant or stored in `wp_options`
- **Per-vault key derivation:** `HMAC-SHA256(master_key, vault_salt)` — each vault has a unique encryption key; compromising one vault's key does not expose others
- **Vault salt:** 16 cryptographically random bytes generated at vault creation time, stored in `sft_vaults.salt`

### Streaming Encryption

Files are processed in 1 MB chunks to avoid PHP memory exhaustion on large files. For AES-256-CBC, the chunk size is padded to align with the 16-byte block size.

### OTP Generation

- OTPs are 6-digit codes generated with `random_int(100000, 999999)` (cryptographically secure CSPRNG)
- The plaintext OTP is emailed to the recipient; the plugin stores only `wp_hash_password($otp)` in the database
- The hash is a bcrypt hash — brute-forcing the database does not reveal the OTP

### Download Session Tokens

- 32-byte tokens generated with `random_bytes(32)`
- Stored as `set_transient('sft_dl_' . hash('sha256', $token), ...)` — the raw token is never in the database
- 30-minute TTL; invalidated on vault/share revocation

---

## File Storage Security

- All encrypted files are written to `wp-content/uploads/sft-vaults/{vault_id}/{random}.enc`
- The directory root is protected by `.htaccess` (`Deny from all` / `<FilesMatch>` deny)
- Files are **never served directly** — all downloads route through PHP, which decrypts on the fly
- Chunked upload staging (`sft-chunks/`) is also `.htaccess` protected and cleaned up hourly by WP-Cron

---

## Application Security

### SQL Injection

All database queries use `$wpdb->prepare()` with parameterized values. Dynamic `ORDER BY` clauses use an explicit column whitelist with strict (`===`) comparison — SQL column names cannot be parameterized in prepared statements, so whitelisting is the correct approach:

```php
$allowed_cols = [ 'name', 'status', 'created_at', 'expires_at' ];
$orderby = in_array( $args['orderby'] ?? '', $allowed_cols, true ) ? $args['orderby'] : 'created_at';
```

Limit/offset values are cast to `int` before interpolation.

### Cross-Site Scripting (XSS)

All user-controlled data rendered in HTML uses WordPress escaping functions:
- `esc_html()` for text content
- `esc_attr()` for HTML attributes
- `esc_url()` for URLs
- `wp_kses()` for the limited HTML allowed in notices

### CSRF Protection

Every form submission and AJAX action is protected by a WordPress nonce:
- Admin forms: `check_admin_referer('sft_admin_action', 'sft_nonce')`
- User dashboard forms: `check_admin_referer('sft_user_dashboard_action', 'sft_user_nonce')`
- AJAX: `check_ajax_referer('sft_...')`

### Authorization

- Admin panel: `sft_is_admin()` — returns `true` for `manage_options` or `sft_admin` capability
- User dashboard: `sft_user_can_use()` — returns `true` for any of `manage_options`, `sft_admin`, `use_sft_vaults`
- Vault ownership assertions: every user dashboard action verifies the target vault is owned by the current user (or that the user is an admin) before proceeding

### File Upload Validation

- MIME type validation via `wp_check_filetype_and_ext()` before accepting a chunk
- Extension checked against site-configured allowed MIME types
- Administrator-configurable extension allowlist (`sft_allowed_file_extensions`) enforced at chunk-finalize time — after all chunks are assembled and before encryption. Rejected files are unlinked immediately; no partial encrypted output is written.
- Per-user storage quota checked at the same finalize step; uploads that would exceed the quota are rejected and the assembled temp file is discarded
- Files are stored under random names with `.enc` extension, not the original filename
- Original filename is stored only in the database, never used for file system operations

### Path Traversal

- SIEM log path is validated to be absolute and must not contain `..` segments before being stored
- Encrypted file paths are constructed from database-stored integer IDs and random hex strings — no user-provided path components

### Direct File Access

All PHP files begin with:
```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

### OTP Rate Limiting

A configurable cooldown (`sft_otp_cooldown_seconds`, default 60) is enforced before a new OTP is issued for a share. The most recent unused OTP's `created_at` is compared to the current time; if within the cooldown window, a `WP_Error` is returned and no new code is generated or stored. The check runs before the "expire previous OTPs" step so a legitimate recipient is not accidentally locked out by a rapid second request.

### Open Redirects

All redirects use `wp_redirect()` to URLs constructed with `add_query_arg()` and `admin_url()` — no user-controlled redirect targets.

### Clipboard API

The encryption key copy button uses the modern `navigator.clipboard.writeText()` API with a fallback to the deprecated `execCommand('copy')` for older browsers.

---

## Audit Trail Immutability

The `sft_audit` table has no `UPDATE` or `DELETE` triggers, and the plugin provides no endpoint to edit or delete individual audit rows. The only removal mechanism is the bulk prune (admin-only, logged), which removes rows by age — not by content.

The WordPress database user typically has full DML rights, so database-level immutability requires additional controls outside the plugin (e.g. read-only DB replica for log shipping, OS-level file permissions).

---

## Known Limitations

### Chunk Assembly Race Condition (Low Risk)

Between the check "all chunk parts exist" and the assembly loop, a concurrent request or same-server process could theoretically modify or delete a chunk file (TOCTOU). Exploitation requires same-server access or a crafted concurrent upload sequence. Chunk filenames are prefixed with a per-upload random UUID, making accidental collision unlikely.

**Mitigation:** Chunk files are only readable/writable by the web server user. The upload directory is `.htaccess` protected. A future version may add `flock()` during assembly.

### Download Session TTL

The 30-minute download session TTL is hardcoded. If a recipient's session token is intercepted, they have a 30-minute window. Token exposure requires either access to the WordPress transient table or a man-in-the-middle capable of reading HTTPS traffic.

### Email as Second Factor

The two-factor flow relies on email deliverability. If an attacker controls the recipient's email account, they can complete verification. This is a design-level limitation shared by all email-based 2FA systems.

---

## Security Review Summary (v1.2.0)

| Finding | Severity | Status |
|---|---|---|
| SIEM log path not validated as absolute | Medium | Fixed in 1.1.0 |
| Key copy used deprecated `execCommand` | Low | Fixed in 1.1.0 |
| Users tab help text described old single-tier access | Informational | Fixed in 1.1.0 |
| No cooldown between OTP requests — recipients could flood codes | Low | Fixed in 1.2.0 (configurable cooldown) |
| File type not enforced server-side at finalize step | Low | Fixed in 1.2.0 (extension allowlist) |
| No server-side storage cap per user | Low | Fixed in 1.2.0 (per-user quota) |
| Chunk assembly TOCTOU | Low | Documented, mitigated by directory permissions |
| Hardcoded download session TTL | Low | Documented, acceptable default |
| SQL ORDER BY whitelist pattern | Informational | Design correct; documented |
