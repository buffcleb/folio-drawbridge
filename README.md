# WP Secure File Transfer Pro

**Version:** 1.0.2  
**Requires WordPress:** 5.3+  
**Requires PHP:** 7.4+  
**License:** GPL-3.0-or-later

Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and role-based vault oversight.

---

## What It Does

WP Secure File Transfer Pro lets authenticated WordPress users upload files into named **vaults**, where they are encrypted at rest using AES-256-CBC before being written to disk outside the webroot. Vault contents can be shared securely with external, unauthenticated recipients through a two-factor verification flow: invite email → email confirmation → one-time code. Every action across the plugin is recorded in an immutable audit log.

[[SCREENSHOT: Admin Dashboard tab showing stat cards, 7-day activity sparkline, Recent Activity table, and Security Status card]]

---

## Features at a Glance

| Feature | Description |
|---|---|
| **Encrypted vault storage** | AES-256-CBC with a unique per-vault key derived from a site-wide master key. Files stored outside the webroot. |
| **Two-factor external sharing** | Recipients receive an invite link, confirm their email, then verify a time-limited one-time code before downloading. |
| **Chunked file upload** | Files split client-side and reassembled server-side — bypasses PHP `upload_max_filesize` and `post_max_size` limits. |
| **Role-based access** | Two tiers of non-admin access: **SFT Admin** (full panel) and **Vault User** (My Vaults only). |
| **Sortable tables** | All tabular data supports clickable column sorting — server-side for paginated lists, client-side for inspector sub-tables. |
| **WordPress dashboard widgets** | Admin vault overview widget and personal My Vaults widget on the wp-admin home screen. |
| **Global share limits** | Configure default and maximum download counts and link expiration windows. Retroactively enforce on existing shares. |
| **Lifecycle management** | Hourly WP-Cron expires vaults and shares, prunes stale OTPs, cleans orphaned chunks, and optionally auto-prunes audit entries. |
| **Immutable audit log** | Every vault, file, share, OTP, and settings event logged with actor, IP, and timestamp. Filterable, sortable, exportable to CSV. |
| **Super-admin vault inspector** | Browse every vault, download any file (logged), revoke shares, edit vault expiry and share limits, change vault status, delete vaults. |
| **SIEM logging** | Append every audit event to an OS log file in JSON (NDJSON) or CSV format for Splunk, Datadog, ELK, and similar tools. |
| **Encryption key generator** | Server-side CSPRNG key generation with a copy-to-clipboard modal for placing the key in `wp-config.php`. |
| **Contextual help** | WordPress contextual help tabs on every admin panel tab and the user dashboard. |

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.3 |
| PHP | 7.4 |
| PHP extensions | `openssl`, `mbstring` |
| MySQL / MariaDB | 5.6 / 10.0 |

---

## Documentation

Detailed documentation is in the [`docs/`](docs/) directory:

| Guide | Description |
|---|---|
| [Installation](docs/installation.md) | Requirements, setup checklist, upgrade notes |
| [Configuration](docs/configuration.md) | All settings explained with defaults and ranges |
| [User Guide](docs/user-guide.md) | Creating vaults, uploading files, sharing, revoking |
| [Admin Guide](docs/admin-guide.md) | Dashboard, vault inspector, audit log, user management |
| [Security Reference](docs/security.md) | Cryptographic design, security controls, known limitations |
| [Architecture](docs/architecture.md) | File structure, DB schema, encryption flow, key functions |

---

## Quick Start

1. Upload `wp-secure-file-transfer-pro/` to `/wp-content/plugins/` and activate.
2. Go to **Secure Transfer → Settings** and generate a master encryption key.
3. Copy the key into `wp-config.php` as `define('SFT_MASTER_KEY', '...');`.
4. Confirm **Dashboard → Security Status** shows all green.
5. Go to **Secure Transfer → Users** to grant vault access to non-admin users.

[[SCREENSHOT: Security Status card showing all green indicators: key source, algorithm, OTP TTL, storage path, and cron status]]

---

## Encryption Key

The master key is the root secret from which every vault's unique encryption key is derived via HMAC-SHA256. It must be a 64-character hexadecimal string (32 raw bytes).

**Recommended — `wp-config.php`:**

```php
define( 'SFT_MASTER_KEY', 'your-64-hex-character-key-here' );
```

Place this line before the `/* That's all, stop editing! */` comment. When the constant is defined, the key is never stored in the database.

**Fallback — database:** If the constant is not defined, the plugin auto-generates a key on first use and stores it in `wp_options`. The Settings tab shows an advisory to move it to `wp-config.php`.

> **Warning:** Replacing an existing master key permanently breaks decryption of all previously uploaded files. Only generate a new key on a fresh installation with no uploaded files.

---

## Access Roles

| Role | Capability | Access |
|---|---|---|
| WordPress Admin | `manage_options` | Full access — always implicit |
| **SFT Admin** | `sft_admin` | Full Secure Transfer admin panel — all tabs, vault inspector, audit export, settings, Users tab |
| **Vault User** | `use_sft_vaults` | My Vaults only — create, upload, share, revoke |

WordPress administrators are always exempt from share limits and expiration restrictions. Grant and manage access at **Secure Transfer → Users**.

[[SCREENSHOT: Users tab showing SFT Admins section, Vault Users section, and the user search panel with contextual action buttons]]

---

## Two-Factor Share Flow

1. Vault owner creates a share → unique URL token generated → invite email sent.
2. Recipient opens share URL → enters their email address.
3. `sft_send_otp()` validates the email matches the invite, generates a 6-digit OTP (via `random_int`), hashes it with `wp_hash_password`, and emails the plaintext code.
4. Recipient submits the OTP → `sft_verify_otp_for_share()` checks the hash, enforces the attempt limit, marks the OTP used.
5. A 32-byte download session token is issued as a WordPress transient (30-minute TTL).
6. Each file download validates the session token and share accessibility before decrypting and streaming.

[[SCREENSHOT: Recipient email confirmation page showing the email input form with the vault name and sender displayed]]
[[SCREENSHOT: Recipient download page showing the file list for a vault with Download buttons and file sizes]]

---

## Admin Panel

Accessible at **Secure Transfer** (requires `manage_options` or `sft_admin` capability).

| Tab | Description |
|---|---|
| Dashboard | Real-time stats, 7-day download sparkline, recent activity, security status |
| Vaults | Browse all vaults; filter by status/name; inspect files, shares, and audit trail per vault; edit expiry and shares inline; sortable columns |
| Audit Log | Filterable, sortable, paginated event log; CSV export; manual prune; all timestamps in site timezone |
| Users | Manage SFT Admins and Vault Users; grant, promote, demote, revoke; user search |
| Settings | All plugin configuration; encryption key management; SIEM log output |

[[SCREENSHOT: Vaults tab with the filter panel and a vault table showing sortable column headers with active sort indicator on "Created"]]

---

## User Dashboard

Users with vault access manage their own vaults under **My Vaults** in wp-admin.

- **Create a vault** — name, optional description, optional expiry date.
- **Upload files** — chunked upload with progress bar; files encrypted before storage.
- **Share a vault** — enter recipient email, optional download limit, optional expiry; recipient completes two-factor verification.
- **Edit a share** — update download limit or expiry date on an existing share without revoking it.
- **Revoke a share** — removes access immediately.
- **Activity log** — last 20 events per vault, sortable.

The `[sft_my_vaults]` shortcode renders equivalent functionality on any front-end page.

[[SCREENSHOT: Vault detail page showing the Files section with two uploaded files, the Shares section with one active share, and the Share creation form]]

---

## WordPress Dashboard Widgets

Two widgets appear on the wp-admin home screen:

**Admin vault overview** (SFT Admins only):
- Total and active vault counts, file count, total encrypted storage size
- Active share count, downloads (7 days), OTP failures (30 days — highlighted red if > 0)
- Link to the admin panel

**My Vaults** (Vault Users):
- Personal vault count, active vault count, file count, active share count
- Last 5 activity events
- Link to My Vaults

[[SCREENSHOT: WordPress dashboard showing both widgets side by side — the admin overview widget and the personal My Vaults widget]]

---

## Architecture

### File Storage

Encrypted files are written to `wp-content/uploads/sft-vaults/{vault_id}/{random}.enc`. The root is protected by `.htaccess` (`Deny from all`). Files are never served directly — all downloads go through PHP, which decrypts on the fly.

Chunked upload staging files live in `wp-content/uploads/sft-chunks/` and are cleaned up by the hourly cron.

### Encryption

- **Algorithm:** AES-256-CBC via PHP `openssl_encrypt`
- **Master key:** 32 bytes (64 hex chars), constant or `wp_options`
- **Vault key:** `HMAC-SHA256(vault_salt, master_key)` — unique per vault
- **IV:** 16 random bytes per file, stored as hex in the database
- **Streaming:** 1 MB chunks to avoid PHP memory limits on large files

### Database Tables

| Table | Purpose |
|---|---|
| `{prefix}sft_vaults` | Vault records — owner, status, salt, expiry |
| `{prefix}sft_files` | File metadata — original name, stored name, IV, size, MIME |
| `{prefix}sft_shares` | Share links — recipient, token, download count, expiry |
| `{prefix}sft_otps` | OTP records — hash, attempt count, expiry |
| `{prefix}sft_audit` | Immutable event log |

---

## Configuration Summary

All settings at **Secure Transfer → Settings**. See [Configuration](docs/configuration.md) for full details.

| Section | Key Settings |
|---|---|
| Two-Factor Verification | OTP validity (5–60 min, default 15), max attempts (1–20, default 5) |
| Download Limits | Allow unlimited, default limit, maximum limit |
| Link Expiration | Allow no expiry, default days, maximum days |
| File Uploads | Maximum file size in MB (can exceed server's `upload_max_filesize`) |
| SIEM Logging | Enable, absolute log path, format (JSON / CSV) |
| Audit Log Retention | Auto-prune on/off, retention window in days |
| Data & Privacy | Delete all data on uninstall |

---

## Uninstall

Enable **Delete all plugin data on uninstall** in Settings before removing the plugin if you want all data removed. Deletion permanently drops all five database tables, deletes all encrypted files from disk, and removes all plugin options and transients. **This is irreversible.** Leave the setting disabled to preserve data across a reinstall.

---

## Changelog

### 1.0.2
- **SFT Admin user type** — new `sft_admin` capability grants non-WordPress-administrator users full access to the Secure Transfer admin panel. Promote, demote, and revoke from the redesigned Users tab.
- **Users tab redesign** — two sections (SFT Admins / Vault Users) with three-action contextual buttons per access tier.
- **Sortable tables** — clickable column sorting on all tabular data. Server-side sorting (with URL state) on paginated lists; client-side sorting on inspector and detail sub-tables. Edit sub-rows stay pinned to their parent during sort.
- **WordPress dashboard widgets** — admin vault overview widget and personal My Vaults widget on the wp-admin home screen.
- **Timezone display** — all dates and times throughout the plugin (audit log, vault inspector, user dashboard, CSV export) now display in the site's configured timezone (Settings → General) rather than UTC.
- **SIEM logging** — write every audit event to an OS log file in JSON (NDJSON) or CSV format for ingestion by Splunk, Datadog, ELK, and other SIEM tools.
- **Admin vault expiry editing** — admins can edit a vault's expiry date inline from the vault inspector.
- **Admin share editing** — admins can edit a share's download limit and expiry date inline from the vault inspector.
- **Contextual enforce checkboxes** — the separate "Apply Limits to Existing Shares" card is replaced by per-section checkboxes that only appear when the user has actually modified a setting in that section.
- **Settings help tabs** — added dedicated help sections for File Uploads, SIEM Logging, Audit Log Retention, Encryption Key, and Data & Privacy.
- **Security: SIEM path validation** — the log file path is now validated to be absolute and free of `..` traversal sequences before being stored.
- **Security: Clipboard API** — key copy button uses `navigator.clipboard.writeText()` with fallback for older browsers.

### 1.0.1
- **Streaming encryption/decryption** — AES-256-CBC encrypt and decrypt now process files in 1 MB chunks, eliminating PHP memory exhaustion on large files (e.g. 2+ GB).
- **Chunked file upload** — large files split client-side and reassembled server-side via AJAX, bypassing `upload_max_filesize` and `post_max_size` PHP limits. Live progress bar in both admin and shortcode contexts.
- **Download limits and link expiration** — configure default and maximum download counts and expiry windows. Retroactively apply to existing shares.
- **Vault expiry editing** — vault owners can edit or clear a vault's expiry date after creation.
- **Share editing** — pending and active shares can have their download limit and expiry date updated inline without revoking and recreating.
- **Date picker** — share and vault expiry fields use a `date` input (full calendar picker). Expiries applied at end-of-day (23:59:59 UTC).
- **OTP attempt limit setting** — maximum verification attempts now configurable (previously hard-coded at 5).
- **Encryption key generator** — server-side key generation with copy-to-clipboard modal.
- **Audit log details filter** — filter audit log by keyword against the details column.
- **Role-based access (Users tab)** — grant and revoke the `use_sft_vaults` capability per user from the admin panel.
- **Bug fix** — `sft_max_download_limit` and `sft_max_expiry_days` settings were incorrectly clamped to a minimum of 1, preventing the "no ceiling" (0) value from being stored.
- **Bug fix** — download limit ceiling no longer overrides a share explicitly set to unlimited when unlimited downloads are permitted.

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
