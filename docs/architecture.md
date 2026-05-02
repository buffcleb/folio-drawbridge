# Architecture Reference

---

## File Structure

```
wp-secure-file-transfer-pro/
├── wp-secure-file-transfer-pro.php     # Plugin entry point, constants, bootstrap
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
│   ├── class-sft-db.php                # Schema creation, activation, path helpers
│   ├── class-sft-crypto.php            # Encryption, OTP generation, token functions
│   ├── class-sft-audit.php             # Audit logging, SIEM write, query functions
│   ├── class-sft-vault.php             # Vault and file CRUD, chunked upload helpers
│   ├── class-sft-share.php             # Share management and two-factor flow
│   ├── class-sft-lifecycle.php         # WP-Cron lifecycle tasks
│   └── class-sft-frontend.php          # Public share page, shortcode, AJAX handlers
└── admin/
    ├── class-sft-admin.php             # Admin menu, POST handler, asset enqueue, help tabs
    ├── class-sft-user-dashboard.php    # My Vaults menu, POST handler, user help tabs
    ├── class-sft-dashboard-widgets.php # WordPress dashboard widgets
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
CREATE TABLE {prefix}sft_vaults (
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
CREATE TABLE {prefix}sft_files (
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
CREATE TABLE {prefix}sft_shares (
    id               bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    vault_id         bigint(20)   UNSIGNED NOT NULL,
    token            varchar(100) NOT NULL UNIQUE,
    recipient_email  varchar(255) NOT NULL,
    created_by       bigint(20)   UNSIGNED NOT NULL,
    status           varchar(20)  NOT NULL DEFAULT 'pending',
    max_downloads    int(11)      NOT NULL DEFAULT 0,   -- 0 = unlimited
    download_count   int(11)      NOT NULL DEFAULT 0,
    expires_at       datetime     DEFAULT NULL,
    last_accessed    datetime     DEFAULT NULL,
    created_at       datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY vault_id (vault_id),
    KEY token    (token)
);

-- OTP records for two-factor verification
CREATE TABLE {prefix}sft_otps (
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
CREATE TABLE {prefix}sft_audit (
    id          bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type  varchar(60)  NOT NULL,
    vault_id    bigint(20)   UNSIGNED DEFAULT NULL,
    share_id    bigint(20)   UNSIGNED DEFAULT NULL,
    actor_id    bigint(20)   UNSIGNED DEFAULT NULL,   -- NULL = system/recipient
    ip_address  varchar(45)  NOT NULL DEFAULT '',
    details     text         DEFAULT NULL,            -- JSON key→value context
    created_at  datetime     NOT NULL,
    PRIMARY KEY (id),
    KEY event_type (event_type),
    KEY vault_id   (vault_id),
    KEY actor_id   (actor_id),
    KEY created_at (created_at)
);
```

---

## Encryption Flow

### Upload

```
User selects file
→ Browser splits into chunks (sized to fit PHP limits)
→ Each chunk POST'd to wp-ajax.php?action=sft_upload_chunk
→ Server reassembles chunks into temp file
→ sft_encrypt_file_streaming():
    vault_key = HMAC-SHA256(master_key, vault_salt)
    iv        = random_bytes(16)
    read temp in 1MB blocks → openssl_encrypt → write .enc
→ Record (original_name, stored_name, iv, size, mime) in sft_files
→ Delete temp file
→ Log SFT_EVT_FILE_UPLOADED in sft_audit
```

### Download

```
Request arrives at download handler (admin or frontend)
→ Validate session token / nonce
→ Check share is active, not expired, download count not exceeded
→ sft_serve_file():
    vault_key = HMAC-SHA256(master_key, vault_salt)
    read .enc in 1MB blocks → openssl_decrypt → output to browser
→ Increment download_count on share
→ Log SFT_EVT_FILE_DOWNLOADED in sft_audit
```

---

## Two-Factor Share Flow

```
1. Vault owner creates share
   → sft_create_share(): generate 32-byte token, insert sft_shares record
   → send invite email with URL containing token

2. Recipient opens share URL
   → sft_render_share_page(): show email confirmation form

3. Recipient submits email
   → sft_send_otp_for_share(): validate email matches, generate OTP, hash + store,
     send plaintext OTP to recipient

4. Recipient submits OTP
   → sft_verify_otp_for_share(): verify hash, enforce attempt limit,
     mark OTP used, promote share to 'active'
   → sft_create_download_session(): 32-byte token stored as transient

5. Recipient downloads files
   → Validate download session token
   → Decrypt and stream each file
   → Increment download_count, check against max_downloads
```

---

## WP-Cron Lifecycle (`sft_hourly_lifecycle`)

Runs hourly via `sft_lifecycle_tasks()`:

1. **Expire vaults** — sets vaults past `expires_at` to `expired` status.
2. **Expire shares** — sets shares past `expires_at` to `expired` status.
3. **Clean OTPs** — deletes OTP records past `expires_at`.
4. **Prune chunks** — deletes orphaned chunk part files older than 24 hours.
5. **Auto-prune audit** — if enabled, deletes audit rows older than the retention window.

---

## Capability Model

| Capability | Granted by | Access |
|---|---|---|
| `manage_options` | WordPress core (Administrator role) | Implicit full SFT access |
| `sft_admin` | Plugin Users tab | Full SFT admin panel |
| `use_sft_vaults` | Plugin Users tab | My Vaults dashboard only |

`sft_is_admin()` returns `true` for either `manage_options` OR `sft_admin`. A `user_has_cap` filter ensures `sft_admin` users also pass `current_user_can('sft_admin')` checks consistently.

---

## Key Functions Reference

| Function | File | Purpose |
|---|---|---|
| `sft_is_admin(?int)` | wp-secure-file-transfer-pro.php | True if user has admin-level SFT access |
| `sft_user_can_use(?int)` | class-sft-frontend.php | True if user has any SFT vault access |
| `sft_format_date(string, string)` | wp-secure-file-transfer-pro.php | Convert UTC DB datetime to site timezone string |
| `sft_create_vault(...)` | class-sft-vault.php | Insert vault record, return vault ID |
| `sft_get_vault(int)` | class-sft-vault.php | Single vault row by ID |
| `sft_get_user_vaults(int, array)` | class-sft-vault.php | All vaults for owner with optional filter/sort/page |
| `sft_get_all_vaults(array)` | class-sft-vault.php | All vaults (admin) with filter/sort/page |
| `sft_encrypt_file_streaming(string, string)` | class-sft-crypto.php | Stream-encrypt a temp file to .enc |
| `sft_decrypt_file_streaming(string, string, string)` | class-sft-crypto.php | Stream-decrypt .enc to output stream |
| `sft_create_share(...)` | class-sft-share.php | Create share record, send invite email |
| `sft_send_otp(int)` | class-sft-share.php | Generate, hash, store, and email OTP |
| `sft_verify_otp_for_share(int, string)` | class-sft-share.php | Validate OTP, enforce attempt limit |
| `sft_log(string, ...)` | class-sft-audit.php | Insert audit row, optionally write to SIEM file |
| `sft_enforce_share_limits()` | class-sft-share.php | Retroactively apply global limits to existing shares |
| `sft_sortable_th(...)` | class-sft-admin.php | Render server-side sortable `<th>` element |
| `sftSortTable(tableId)` | Inline JS | Initialize client-side sort on a table |
