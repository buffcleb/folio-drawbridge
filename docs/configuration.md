# Configuration Guide

All plugin settings live under **Secure Transfer → Settings**. Settings are organized into six sections.

---

## Encryption Key

The master key is the root secret from which every vault's unique per-vault encryption key is derived using HMAC-SHA256. It must be a 64-character hexadecimal string (32 raw bytes).

### Recommended — `wp-config.php` constant

Place the constant before the `/* That's all, stop editing! */` comment:

```php
define( 'SFT_MASTER_KEY', 'your-64-hex-character-key-here' );
```

When the constant is defined, the key is never stored in the database and the Settings tab shows a confirmation.

### Generating a key

1. Go to **Secure Transfer → Settings → Encryption Key**.
2. Click **Generate New Key**.
3. Read the warning, check the acknowledgement checkbox, then click **Show Key**.
4. Copy the 64-character key immediately — the plugin never stores it.
5. Paste it as the `SFT_MASTER_KEY` constant in `wp-config.php`.

[[SCREENSHOT: Key generator modal with the generated key visible and Copy button highlighted]]

> **Warning:** Replacing an existing master key permanently breaks decryption of all previously uploaded files. Only generate a new key on a fresh installation with no uploaded files. If you need to rotate keys, you must decrypt and re-encrypt every file — there is no automated migration tool.

### Fallback — database storage

If `SFT_MASTER_KEY` is not defined the plugin auto-generates a key on first use and stores it in `wp_options` (autoload disabled). A yellow advisory banner appears in Settings recommending you move the key to `wp-config.php`.

---

## Two-Factor Verification

Controls the one-time code (OTP) sent to share recipients.

| Setting | Description | Default | Range |
|---|---|---|---|
| OTP Validity | Minutes a code remains valid after being emailed | 15 | 5–60 |
| Max Verification Attempts | Incorrect code entries before invalidation | 5 | 1–20 |

Shorter OTP validity reduces the exposure window if email is delayed or intercepted. Lower attempt limits reduce brute-force risk.

---

## Download Limits

Caps how many times a single share link can be used to download files. Administrators are always exempt.

| Setting | Description | Default |
|---|---|---|
| Allow Unlimited Downloads | When off, every share must have a finite download count | Yes (on) |
| Default Download Limit | Value pre-filled in the share creation form (0 = no pre-fill) | 0 |
| Maximum Download Limit | Hard ceiling users cannot exceed (0 = no ceiling) | 0 |

When you modify these values, a checkbox appears inside the section offering to retroactively enforce the new limits on all active and pending shares that currently exceed them. Shares already within limits and administrator shares are skipped.

---

## Link Expiration

Controls when share links automatically expire. Administrators are always exempt.

| Setting | Description | Default |
|---|---|---|
| Allow No Expiry | When off, every share must have an expiration date | Yes (on) |
| Default Expiry | Days from today pre-filled in the share form (0 = no pre-fill) | 0 |
| Maximum Expiry | Furthest-out expiration permitted in days (0 = no ceiling) | 0 |

Expiry is applied at end-of-day (23:59:59 site time) on the selected date.

Like download limits, a contextual checkbox appears when values change, offering to retroactively apply the new limits to existing shares.

[[SCREENSHOT: Settings page showing Link Expiration section with the amber "Apply to existing shares" checkbox visible after a value was changed]]

---

## File Uploads

| Setting | Description | Default |
|---|---|---|
| Maximum File Size (MB) | Plugin-level ceiling on uploaded file size | 50 |

Files are uploaded in chunks computed from the server's `upload_max_filesize` and `post_max_size` limits, so the plugin-level ceiling can safely **exceed** those server limits. For example, you can set this to `2048` (2 GB) even if `upload_max_filesize = 8M`. Each individual chunk is automatically sized to fit within PHP's limits.

---

## SIEM Logging

Writes every audit event to an OS-level log file for ingestion by SIEM tools (Splunk, Datadog, ELK, etc.).

| Setting | Description |
|---|---|
| Enable SIEM Log | Turns file-based logging on or off |
| Log File Path | Absolute path to the log file. Directory must exist and be writable. |
| Log Format | JSON (NDJSON — one object per line) or CSV |

Both formats include: `timestamp_utc`, `event`, `vault_id`, `share_id`, `actor_id`, `ip`, `details`, `site`.

**Requirements:**
- Path must be an absolute path (e.g. `/var/log/sft-events.json`).
- Path must not contain `..` segments.
- The directory must already exist; the file is created on first write.
- The web server process must have write permission to the directory.

If the path fails validation, the previous value is retained and a warning is shown. The invalid path is never saved.

[[SCREENSHOT: SIEM Logging section of Settings with Log File Path and Format fields filled in]]

---

## Audit Log Retention

| Setting | Description | Default |
|---|---|---|
| Auto-Prune | Automatically delete old entries via WP-Cron | Off |
| Retention Window | Entries older than this many days are pruned | 365 |

Manual pruning is also available from the **Audit Log** tab filter sidebar at any time.

---

## Data & Privacy / Storage

| Setting | Description |
|---|---|
| Delete all plugin data on uninstall | When enabled, removing the plugin drops all tables, files, and options. Irreversible. |

The storage status card shows the current path, whether `.htaccess` protection is in place, and whether the directory is writable.
