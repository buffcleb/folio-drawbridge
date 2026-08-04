# Architecture Reference

---

## File Structure

```
folio-drawbridge/
├── folio-drawbridge.php     # Plugin entry point, constants, bootstrap
├── uninstall.php                       # Clean removal — drops tables, deletes files
├── README.md                           # Overview and changelog
├── docs/
│   ├── installation.md
│   ├── configuration.md
│   ├── user-guide.md
│   ├── admin-guide.md
│   ├── security.md
│   └── architecture.md                 # This file
├── includes/
│   ├── class-folio-drawbridge-db.php                # Schema creation, activation, path helpers, DB migration
│   ├── class-folio-drawbridge-crypto.php            # Encryption, OTP generation, token functions
│   ├── class-folio-drawbridge-audit.php             # Audit logging, SIEM write, query functions
│   ├── class-folio-drawbridge-vault.php             # Vault and file CRUD, chunked upload helpers, transfer, quota
│   ├── class-folio-drawbridge-share.php             # Share management and two-factor flow
│   ├── class-folio-drawbridge-lifecycle.php         # WP-Cron lifecycle tasks
│   ├── class-folio-drawbridge-notifications.php     # Email template engine, download notifications, expiry warnings
│   └── class-folio-drawbridge-frontend.php          # Public share page, shortcode, AJAX handlers, ZIP download
└── admin/
    ├── class-folio-drawbridge-admin.php             # Admin menu, POST handler, asset enqueue, help tabs
    ├── class-folio-drawbridge-user-dashboard.php    # My Vaults menu, POST handler, user help tabs
    ├── class-folio-drawbridge-dashboard-widgets.php # WordPress dashboard widgets
    ├── tabs/
    │   ├── tab-dashboard.php           # Admin Dashboard tab renderer
    │   ├── tab-vaults.php              # Vaults tab + inspector
    │   ├── tab-audit.php               # Audit Log tab renderer + CSV export
    │   ├── tab-users.php               # Users tab — grant/promote/demote/revoke
    │   └── tab-settings.php            # Settings tab — all plugin configuration
    └── user-views/
        ├── view-vault-list.php         # My Vaults list page
        └── view-vault-detail.php       # Vault detail — files, shares, activity log
```

---

## Database Schema

Five tables are created on activation using `dbDelta()`.

```sql
-- Vault containers
CREATE TABLE {prefix}folio_drawbridge_vaults (
    id          bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    name        varchar(255) NOT NULL,
    description text,
    owner_id    bigint(20)   UNSIGNED NOT NULL,
    salt        varchar(64)  NOT NULL,           -- hex, used to derive per-vault key
    status      varchar(20)  NOT NULL DEFAULT 'active',
    expires_at  datetime     DEFAULT NULL,
    created_at  datetime     NOT NULL,
    updated_at  datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY owner_id (owner_id),
    KEY status   (status)
);

-- Per-vault encrypted files
CREATE TABLE {prefix}folio_drawbridge_files (
    id            bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    vault_id      bigint(20)   UNSIGNED NOT NULL,
    original_name varchar(255) NOT NULL,
    stored_name   varchar(100) NOT NULL,         -- random hex filename
    mime_type     varchar(100) NOT NULL,
    file_size     bigint(20)   UNSIGNED NOT NULL,
    iv            varchar(64)  NOT NULL,          -- hex AES IV
    uploaded_by   bigint(20)   UNSIGNED NOT NULL,
    uploaded_at   datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY vault_id (vault_id)
);

-- Share links
CREATE TABLE {prefix}folio_drawbridge_shares (
    id                   bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    vault_id             bigint(20)   UNSIGNED NOT NULL,
    token                varchar(100) NOT NULL UNIQUE,
    recipient_email      varchar(255) NOT NULL,
    created_by           bigint(20)   UNSIGNED NOT NULL,
    status               varchar(20)  NOT NULL DEFAULT 'pending',
    max_downloads        int(11)      NOT NULL DEFAULT 0,       -- 0 = unlimited
    download_count       int(11)      NOT NULL DEFAULT 0,
    expires_at           datetime     DEFAULT NULL,
    last_accessed        datetime     DEFAULT NULL,
    created_at           datetime     NOT NULL,
    expiry_warning_sent  tinyint(1)   NOT NULL DEFAULT 0,       -- 1 after warning email sent
    PRIMARY KEY (id),
    KEY vault_id (vault_id),
    KEY token    (token)
);

-- OTP records for two-factor verification
CREATE TABLE {prefix}folio_drawbridge_otps (
    id           bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    share_id     bigint(20)   UNSIGNED NOT NULL,
    otp_hash     varchar(255) NOT NULL,           -- bcrypt via wp_hash_password
    expires_at   datetime     NOT NULL,
    attempts     int(11)      NOT NULL DEFAULT 0,
    used         tinyint(1)   NOT NULL DEFAULT 0,
    created_at   datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY share_id (share_id)
);

-- Immutable audit log
CREATE TABLE {prefix}folio_drawbridge_audit (
    id          bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type  varchar(60)  NOT NULL,
    vault_id    bigint(20)   UNSIGNED DEFAULT NULL,
    share_id    bigint(20)   UNSIGNED DEFAULT NULL,
    actor_id    bigint(20)   UNSIGNED DEFAULT NULL,   -- NULL = system/recipient
    ip_address  varchar(45)  NOT NULL DEFAULT '',
    details     text         DEFAULT NULL,            -- JSON key→value context
    created_at  datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY event_created (event_type, created_at),   -- composite: serves filtered + date-bounded counts
    KEY vault_id   (vault_id),
    KEY actor_id   (actor_id),
    KEY created_at (created_at)
);
```

---

## Encryption Flow

### Upload

```
User selects one or more files
→ Browser queues files; uploads sequentially
→ Each file split into chunks (sized to fit PHP limits)
→ Each chunk POST'd to wp-ajax.php?action=folio_drawbridge_upload_chunk
→ Server reassembles chunks into temp file
→ folio_drawbridge_is_allowed_file_type(): check extension against allowlist (rejects + unlinks if not permitted)
→ folio_drawbridge_get_user_storage_used(): check quota not exceeded (rejects + unlinks if over limit)
→ folio_drawbridge_encrypt_file_streaming():
    vault_key = HMAC-SHA256(master_key, vault_salt)
    iv        = random_bytes(16)
    read temp in 1MB blocks → openssl_encrypt → write .enc
→ Record (original_name, stored_name, iv, size, mime) in folio_drawbridge_files
→ Delete temp file
→ Log FOLIO_DRAWBRIDGE_EVT_FILE_UPLOADED in folio_drawbridge_audit
```

### Download

```
Request arrives at download handler (admin or frontend)
→ Validate session token / nonce
→ Check share is active, not expired, download count not exceeded
→ folio_drawbridge_serve_file():
    vault_key = HMAC-SHA256(master_key, vault_salt)
    read .enc in 1MB blocks → openssl_decrypt → output to browser
→ (download_count was already claimed when the session was issued)
→ folio_drawbridge_send_download_notification(): email vault owner if notifications enabled
→ Log FOLIO_DRAWBRIDGE_EVT_FILE_DOWNLOADED in folio_drawbridge_audit
```

### ZIP Bulk Download

```
Recipient clicks "Download All as ZIP"
→ wp-ajax.php?action=folio_drawbridge_zip_download
→ Validate download session token and share accessibility
→ For each file in vault:
    folio_drawbridge_decrypt_file_to_path(): decrypt .enc → temp plaintext file
→ ZipArchive::addFile() each temp file
→ $zip->close() (data written into archive)
→ Stream ZIP to browser
→ Unlink all temp plaintext files
```

---

## Two-Factor Share Flow

```
1. Vault owner creates share
   → folio_drawbridge_create_share(): generate 32-byte token, insert folio_drawbridge_shares record
   → send invite email with URL containing token

2. Recipient opens share URL
   → folio_drawbridge_render_share_page(): show email confirmation form

3. Recipient submits email
   → folio_drawbridge_send_otp_for_share(): validate email matches, generate OTP, hash + store,
     send plaintext OTP to recipient

4. Recipient submits OTP
   → folio_drawbridge_verify_otp_for_share(): verify hash, enforce attempt limit,
     mark OTP used, promote share to 'active'
   → folio_drawbridge_claim_share_access(): atomically claim one download against
     max_downloads — one verified access, not one per file
   → folio_drawbridge_create_download_session(): 32-byte token stored as transient

5. Recipient downloads files
   → Validate download session token
   → Decrypt and stream each file
   → Every file in the vault is retrievable for the life of the session
```

---

## WP-Cron Lifecycle (`folio_drawbridge_hourly_lifecycle`)

Runs hourly via `folio_drawbridge_lifecycle_tasks()`:

1. **Expire vaults** — sets vaults past `expires_at` to `expired` status.
2. **Expire shares** — sets shares past `expires_at` to `expired` status.
3. **Send expiry warnings** — emails vault owners for shares within the configured lead-time window that have not yet been warned (`expiry_warning_sent = 0`). Sets `expiry_warning_sent = 1` after sending.
4. **Clean OTPs** — deletes OTP records past `expires_at`.
5. **Prune chunks** — deletes orphaned chunk part files older than 24 hours.
6. **Auto-prune audit** — if enabled, deletes audit rows older than the retention window.

---

## Capability Model

| Capability | Granted by | Access |
|---|---|---|
| `manage_options` | WordPress core (Administrator role) | Implicit full Drawbridge access |
| `folio_drawbridge_manage_vaults` | Plugin Users tab | Full Drawbridge admin panel |
| `folio_drawbridge_use_vaults` | Plugin Users tab | My Vaults dashboard only |

`folio_drawbridge_is_admin()` returns `true` for either `manage_options` OR `folio_drawbridge_manage_vaults`. A `user_has_cap` filter ensures `folio_drawbridge_manage_vaults` users also pass `current_user_can('folio_drawbridge_manage_vaults')` checks consistently.

---

## Key Functions Reference

| Function | File | Purpose |
|---|---|---|
| `folio_drawbridge_is_admin(?int)` | folio-drawbridge.php | True if user has admin-level Drawbridge access |
| `folio_drawbridge_user_can_use(?int)` | class-folio-drawbridge-frontend.php | True if user has any Drawbridge vault access |
| `folio_drawbridge_format_date(string, string)` | folio-drawbridge.php | Convert UTC DB datetime to site timezone string |
| `folio_drawbridge_create_vault(...)` | class-folio-drawbridge-vault.php | Insert vault record, return vault ID |
| `folio_drawbridge_get_vault(int)` | class-folio-drawbridge-vault.php | Single vault row by ID |
| `folio_drawbridge_get_user_vaults(int, array)` | class-folio-drawbridge-vault.php | All vaults for owner with optional filter/sort/page |
| `folio_drawbridge_get_all_vaults(array)` | class-folio-drawbridge-vault.php | All vaults (admin) with filter/sort/page |
| `folio_drawbridge_update_vault_meta(int, string, string, int)` | class-folio-drawbridge-vault.php | Update vault name and description, log `vault_updated` |
| `folio_drawbridge_transfer_vault(int, int, int)` | class-folio-drawbridge-vault.php | Reassign vault owner, log `vault_transferred` |
| `folio_drawbridge_is_allowed_file_type(string)` | class-folio-drawbridge-vault.php | True if extension is permitted by the allowlist |
| `folio_drawbridge_get_user_storage_used(int)` | class-folio-drawbridge-vault.php | Total encrypted bytes across all vaults for a user |
| `folio_drawbridge_encrypt_file_streaming(string, string)` | class-folio-drawbridge-crypto.php | Stream-encrypt a temp file to .enc |
| `folio_drawbridge_decrypt_file_streaming(string, string, string)` | class-folio-drawbridge-crypto.php | Stream-decrypt .enc to output stream |
| `folio_drawbridge_decrypt_file_to_path(string, string, string, int, string)` | class-folio-drawbridge-crypto.php | Decrypt .enc to a temp file path (used for ZIP) |
| `folio_drawbridge_create_share(...)` | class-folio-drawbridge-share.php | Create share record, send invite email |
| `folio_drawbridge_send_otp(int)` | class-folio-drawbridge-share.php | Generate, hash, store, and email OTP (with cooldown check) |
| `folio_drawbridge_verify_otp_for_share(int, string)` | class-folio-drawbridge-share.php | Validate OTP, enforce attempt limit |
| `folio_drawbridge_log(string, ...)` | class-folio-drawbridge-audit.php | Insert audit row, optionally write to SIEM file |
| `folio_drawbridge_claim_share_access(int)` | class-folio-drawbridge-share.php | Atomically claim one download against a share's limit |
| `folio_drawbridge_share_is_live(object)` | class-folio-drawbridge-share.php | Share not revoked/expired (ignores download limit) |
| `folio_drawbridge_share_is_accessible(object)` | class-folio-drawbridge-share.php | Live **and** limit not reached — gates new accesses |
| `folio_drawbridge_share_display_state(object)` | class-folio-drawbridge-share.php | Real state for display: pending/active/limit_reached/expired/revoked |
| `folio_drawbridge_enforce_share_limits()` | class-folio-drawbridge-share.php | Retroactively apply global limits to existing shares |
| `folio_drawbridge_get_email_template(string)` | class-folio-drawbridge-notifications.php | Return subject + body from options or built-in defaults |
| `folio_drawbridge_render_email_template(string, array)` | class-folio-drawbridge-notifications.php | Replace `{token}` placeholders in a template string |
| `folio_drawbridge_send_download_notification(int, int, string)` | class-folio-drawbridge-notifications.php | Email vault owner on recipient file download |
| `folio_drawbridge_send_expiry_warning(object)` | class-folio-drawbridge-notifications.php | Email vault owner before share expiry; set warning flag |
| `folio_drawbridge_maybe_upgrade_db()` | class-folio-drawbridge-db.php | Run `dbDelta()` if `folio_drawbridge_db_version` option is outdated |
| `folio_drawbridge_sortable_th(...)` | class-folio-drawbridge-admin.php | Render server-side sortable `<th>` element |
| `folioDrawbridgeSortTable(tableId)` | Inline JS | Initialize client-side sort on a table |
