# WP Secure File Transfer Pro

**Version:** 1.0.2
**Requires WordPress:** 5.3+
**Requires PHP:** 7.4+
**License:** GPL-3.0-or-later

Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and super-admin vault oversight.

---

## Overview

WP Secure File Transfer Pro allows authenticated WordPress users to upload files into named **vaults**, where they are encrypted at rest using AES-256-CBC before being written to disk. Vault contents can be shared securely with external, unauthenticated recipients through a two-factor verification flow (invite email → one-time code). Every action across the plugin is recorded in an immutable audit log.

Administrators have full oversight of every vault on the site and can inspect files, shares, and audit trails for any user.

---

## Features

- **Encrypted vault storage** — AES-256-CBC encryption with a unique per-vault key derived from a site-wide master key. Encrypted files are stored outside the web-accessible document root.
- **Two-factor external sharing** — Recipients receive an invite link, confirm their email address, then verify a time-limited one-time code before gaining download access.
- **Chunked file upload** — Large files are split client-side and reassembled server-side, bypassing PHP's `upload_max_filesize` and `post_max_size` limits.
- **Role-based access** — Administrators grant or revoke the `use_sft_vaults` capability to individual non-administrator users via the admin Users tab.
- **User dashboard** — Users with vault access get a dedicated **My Vaults** panel in wp-admin to create vaults, upload files, manage shares, and review their activity log.
- **Global share limits** — Administrators configure default and maximum download counts and link expiration windows. Limits apply to all non-admin shares, with a one-click button to retroactively enforce them on existing shares.
- **Lifecycle management** — WP-Cron runs hourly to expire vaults and shares past their expiry date, clean up stale OTP records, prune orphaned upload chunks, and optionally auto-prune old audit entries.
- **Immutable audit log** — Every vault creation, file upload/download, share event, OTP attempt, admin access, and settings change is logged with actor, IP address, and timestamp. Filterable, exportable to CSV.
- **Super-admin vault inspector** — Administrators can browse every vault, download any file (logged), revoke shares, change vault status, and delete vaults from the admin panel.
- **Encryption key generator** — Server-side cryptographically secure key generation (via `random_bytes`) with a copy-to-clipboard modal for placing the key in `wp-config.php`.
- **Contextual help** — WordPress contextual help tabs on every admin and user dashboard screen.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.3 |
| PHP | 7.4 |
| PHP extensions | `openssl`, `mbstring` |
| MySQL / MariaDB | 5.6 / 10.0 |

---

## Installation

1. Upload the `wp-secure-file-transfer-pro` directory to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. On first activation the plugin:
   - Creates five database tables (`sft_vaults`, `sft_files`, `sft_shares`, `sft_otps`, `sft_audit`).
   - Creates `wp-content/uploads/sft-vaults/` with an `.htaccess` blocking direct access.
   - Schedules the hourly lifecycle cron event.
4. Navigate to **Secure Transfer → Settings** and generate an encryption master key (see [Encryption Key](#encryption-key)).

---

## Encryption Key

The master key is the root secret from which every vault's unique encryption key is derived. It must be a 64-character hexadecimal string (32 raw bytes).

**Recommended — store in `wp-config.php`:**

```php
define( 'SFT_MASTER_KEY', 'your-64-hex-character-key-here' );
```

Place this line before the `/* That's all, stop editing! */` comment. When the constant is defined, the key is never stored in the database.

**Fallback — database storage:**
If the constant is not defined the plugin auto-generates a key on first use and stores it in `wp_options` (autoload disabled). The Settings tab will show an advisory recommending you move the key to `wp-config.php`.

> **Warning:** Replacing the master key permanently breaks decryption of all previously encrypted files. Only generate a new key on a fresh installation with no uploaded files.

Use **Secure Transfer → Settings → Generate New Key** to produce a key. The key is generated server-side and never stored by the plugin — copy it immediately into `wp-config.php`.

---

## Configuration

All settings are under **Secure Transfer → Settings**.

### Two-Factor Verification

| Setting | Description | Default |
|---|---|---|
| OTP Validity | Minutes a one-time code remains valid | 15 |
| Max Verification Attempts | Failed attempts before the code is invalidated | 5 |

### Download Limits

| Setting | Description | Default |
|---|---|---|
| Allow Unlimited Downloads | Permit shares with no download cap | Yes |
| Default Download Limit | Pre-filled value in the share form (0 = unlimited) | 0 |
| Maximum Download Limit | Hard ceiling users cannot exceed (0 = no ceiling) | 0 |

Administrators are exempt from all download limit restrictions.

### Link Expiration

| Setting | Description | Default |
|---|---|---|
| Allow No Expiry | Permit shares with no expiration date | Yes |
| Default Expiry | Days from today pre-filled in the share form (0 = no pre-fill) | 0 |
| Maximum Expiry | Furthest-out expiration allowed in days (0 = no ceiling) | 0 |

Administrators are exempt from all expiration restrictions.

Use **Apply Limits to Existing Shares** to retroactively enforce the current settings on shares that exceed them. Shares already within limits and shares owned by administrators are not modified.

### File Uploads

| Setting | Description | Default |
|---|---|---|
| Maximum File Size (MB) | Plugin-level file size ceiling | 50 |

Files are uploaded in chunks computed from your server's actual PHP limits, so this ceiling can safely exceed `upload_max_filesize`.

### Audit Log Retention

| Setting | Description | Default |
|---|---|---|
| Auto-Prune | Delete old entries automatically via WP-Cron | Off |
| Retention Window | Entries older than this many days are pruned | 365 |

---

## Granting User Access

By default only administrators can use vault features. Two non-administrator user types exist:

| Type | Capability | Access |
|---|---|---|
| **SFT Admin** | `sft_admin` | Full Secure Transfer admin panel — all tabs, vault inspector, audit log |
| **User** | `use_sft_vaults` | My Vaults dashboard only — create, upload, share, revoke |

WordPress administrators (`manage_options`) always have full access to both and are not listed in the Users tab.

1. Go to **Secure Transfer → Users**.
2. Search for a user by login or email address.
3. Click **Grant Vault Access** or **Grant SFT Admin Access**.

Revoking access removes the capability but does not delete their vaults or files. An SFT Admin can be demoted to Vault User without losing their vaults.

---

## User Dashboard

Users with vault access manage their own vaults under **My Vaults** in wp-admin.

- **Create a vault** — give it a name, optional description, and optional expiry date.
- **Upload files** — files are encrypted on the server before storage. Chunked upload supports files larger than the server's PHP upload limit.
- **Share a vault** — enter a recipient email, optional download limit, and optional expiry. The recipient receives an invite email and must complete two-factor verification before downloading.
- **Revoke a share** — removes access immediately.
- **Activity log** — the last 20 events for each vault, scoped to that vault only.

The `[sft_my_vaults]` shortcode renders equivalent functionality on any front-end page.

---

## Admin Panel

Accessible at **Secure Transfer** (requires `manage_options`).

| Tab | Description |
|---|---|
| Dashboard | Real-time stats, 7-day download sparkline, recent activity, security status |
| Vaults | Browse all vaults; inspect files, shares, and audit trail for any vault; edit vault expiry and shares inline |
| Audit Log | Filterable, paginated event log; CSV export; manual prune; all timestamps in site timezone |
| Users | Manage SFT Admins and Vault Users; grant, promote, demote, and revoke access |
| Settings | All plugin configuration; encryption key management; SIEM log file output |

---

## Architecture

### File Storage

Encrypted files are written to `wp-content/uploads/sft-vaults/{vault_id}/{random}.enc`. The root directory is protected by `.htaccess` (`Deny from all`). Files are never served directly — all downloads go through PHP, which decrypts on the fly.

Chunked upload temporary files are stored in `wp-content/uploads/sft-chunks/` (also `.htaccess` protected) and cleaned up by the hourly cron.

### Encryption

- **Algorithm:** AES-256-CBC via PHP `openssl_encrypt`
- **Master key:** 32 bytes (64 hex chars), defined as a constant or stored in `wp_options`
- **Vault key:** `HMAC-SHA256(vault_salt, master_key)` — unique per vault
- **IV:** 16 random bytes per file, stored as hex in the database
- **Ciphertext:** written to disk as `.enc` files

### Two-Factor Share Flow

1. Vault owner creates a share → unique URL token generated → invite email sent.
2. Recipient opens the share URL → enters their email address.
3. `sft_send_otp()` validates the email matches the invite, generates a 6-digit OTP (via `random_int`), hashes it with `wp_hash_password`, and emails the plaintext code.
4. Recipient submits the OTP → `sft_verify_otp_for_share()` checks the hash, enforces the attempt limit, marks the OTP used.
5. A 32-byte download session token is issued as a WordPress transient (30-minute TTL).
6. Each file download validates the session token and share accessibility before decrypting and streaming the file.

### Database Tables

| Table | Purpose |
|---|---|
| `{prefix}sft_vaults` | Vault records with owner, status, salt, and expiry |
| `{prefix}sft_files` | File metadata (original name, stored name, IV, size, MIME) |
| `{prefix}sft_shares` | Share links with recipient, token, download count, and expiry |
| `{prefix}sft_otps` | OTP records with hash, attempt count, and expiry |
| `{prefix}sft_audit` | Immutable event log |

### File Structure

```
wp-secure-file-transfer-pro/
├── wp-secure-file-transfer-pro.php   # Plugin entry point and constants
├── uninstall.php                     # Clean removal of all data
├── includes/
│   ├── class-sft-db.php              # Schema, activation, path helpers
│   ├── class-sft-crypto.php          # Encryption, OTP, and token functions
│   ├── class-sft-audit.php           # Audit logging and query functions
│   ├── class-sft-vault.php           # Vault and file CRUD, chunked upload helpers
│   ├── class-sft-share.php           # Share management and two-factor flow
│   ├── class-sft-lifecycle.php       # WP-Cron lifecycle tasks
│   └── class-sft-frontend.php        # Public share page, shortcode, AJAX handlers
└── admin/
    ├── class-sft-admin.php           # Admin menu, POST handler, asset enqueue
    ├── class-sft-user-dashboard.php  # My Vaults menu and POST handler
    ├── tabs/
    │   ├── tab-dashboard.php
    │   ├── tab-vaults.php
    │   ├── tab-audit.php
    │   ├── tab-users.php
    │   └── tab-settings.php
    └── user-views/
        ├── view-vault-list.php
        └── view-vault-detail.php
```

---

## Uninstall

If **Delete all plugin data on uninstall** is enabled in Settings, removing the plugin will:

- Drop all five database tables.
- Recursively delete `wp-content/uploads/sft-vaults/` and all encrypted files.
- Delete all plugin options from `wp_options`.
- Delete all plugin transients.

This is irreversible. Disable the setting before uninstalling if you want to preserve data.

---

## Changelog

### 1.0.2
- **SFT Admin user type** — a new `sft_admin` capability grants non-WordPress-administrator users full access to the Secure Transfer admin panel. WordPress administrators continue to have full access implicitly. Promote, demote, and revoke from the redesigned Users tab.
- **Users tab redesign** — replaced WP role column with two sections: SFT Admins and Vault Users. Search panel shows current SFT status with contextual action buttons.
- **Timezone display** — all dates and times throughout the plugin (audit log, vault inspector, user dashboard, CSV export) now display in the site's configured timezone (Settings → General) rather than UTC.
- **SIEM logging** — new Settings option to write every audit event to an OS log file in JSON (NDJSON) or CSV format for ingestion by Splunk, Datadog, ELK, and other SIEM tools.
- **Admin vault expiry editing** — admins can edit a vault's expiry date inline from the vault inspector, matching the capability already available in the user dashboard.
- **Admin share editing** — admins can edit a share's download limit and expiry date inline from the vault inspector.

### 1.0.1
- **Streaming encryption/decryption** — AES-256-CBC encrypt and decrypt now process files in 1 MB chunks, eliminating PHP memory exhaustion on large files (e.g. 2+ GB).
- **Chunked file upload** — Large files are split client-side and reassembled server-side via AJAX, bypassing `upload_max_filesize` and `post_max_size` PHP limits. Both the admin vault panel and the `[sft_my_vaults]` shortcode support chunked upload with a live progress bar.
- **Download limits and link expiration** — Administrators can configure default and maximum download counts and expiry windows. Settings are enforced at share creation time and can be retroactively applied to existing shares via **Apply Limits to Existing Shares**.
- **Vault expiry editing** — Vault owners can now edit or clear a vault's expiry date from the vault detail page after creation.
- **Share editing** — Pending and active shares can now have their download limit and expiry date updated inline without revoking and recreating them.
- **Date picker** — Share and vault expiry fields now use a `date` input (full calendar picker) instead of a segmented `datetime-local` control. Expiries are applied at end-of-day (23:59:59 UTC).
- **OTP attempt limit setting** — Maximum verification attempts is now configurable in Settings (previously hard-coded).
- **Encryption key generator** — Server-side key generation with a copy-to-clipboard modal for placing the key in `wp-config.php`.
- **Audit log details filter** — The audit log can now be filtered by keyword against the details column.
- **Role-based access (Users tab)** — Administrators can grant and revoke the `use_sft_vaults` capability per user from the admin panel.
- **Bug fix** — `sft_max_download_limit` and `sft_max_expiry_days` settings were incorrectly clamped to a minimum of 1 when saved, preventing the "no ceiling" (0) value from being stored.
- **Bug fix** — Download limit ceiling no longer overrides a share explicitly set to unlimited (0) when unlimited downloads are permitted.

### 1.0.0
- Initial release.
- AES-256-CBC encrypted vault storage with per-vault HMAC-SHA256 key derivation.
- Two-factor external sharing (invite link → email verification → one-time code).
- Immutable audit log with CSV export and optional auto-pruning.
- Super-admin vault inspector with file download, share revocation, and vault status management.
- WP-Cron lifecycle management for expired vaults, shares, OTPs, and orphaned upload chunks.

---

## License

This plugin is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html) or later.
