# Installation Guide

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.3 |
| PHP | 7.4 |
| PHP extensions | `openssl`, `mbstring` |
| MySQL / MariaDB | 5.6 / 10.0 |

Verify extensions are active before installing:

```php
// Add temporarily to functions.php — remove after checking.
var_dump( extension_loaded('openssl'), extension_loaded('mbstring') );
```

---

## Installing the Plugin

1. Upload the `wp-secure-file-transfer-pro` directory to `/wp-content/plugins/`.
2. In wp-admin navigate to **Plugins → Installed Plugins** and activate **WP Secure File Transfer Pro**.

On first activation the plugin automatically:
- Creates five database tables (`sft_vaults`, `sft_files`, `sft_shares`, `sft_otps`, `sft_audit`).
- Creates `wp-content/uploads/sft-vaults/` with an `.htaccess` file blocking direct HTTP access.
- Creates `wp-content/uploads/sft-chunks/` (chunked upload staging area, also `.htaccess` protected).
- Schedules the hourly lifecycle cron event (`sft_hourly_lifecycle`).

[[SCREENSHOT: Plugins screen with WP Secure File Transfer Pro activated and highlighted]]

---

## Initial Setup Checklist

After activation, complete these steps before accepting uploads:

- [ ] **Generate or configure the master encryption key** — see [Encryption Key Setup](configuration.md#encryption-key).
- [ ] **Confirm file storage is writable** — **Secure Transfer → Dashboard → Security Status** shows a green checkmark if the upload directory exists and is writable.
- [ ] **Verify cron is running** — Security Status also confirms the lifecycle cron is scheduled. If it shows missing, deactivate and reactivate the plugin.
- [ ] **Configure share limits** — **Secure Transfer → Settings** lets you set defaults and maximums for download counts and link expiration windows.
- [ ] **Grant user access** — by default only WordPress administrators can use vault features. See [Granting User Access](admin-guide.md#granting-user-access) to enable non-admin users.

[[SCREENSHOT: Secure Transfer Dashboard with Security Status card showing all green indicators]]

---

## Upgrading

Upgrades are non-destructive. The plugin does not run `DROP TABLE` statements on update — only `CREATE TABLE IF NOT EXISTS`, so existing data is always preserved.

If a database schema change is required between versions, the activation hook handles it via `dbDelta()`.

---

## Server-Side Email Configuration

The two-factor sharing flow depends on WordPress being able to send email reliably. Verify your site sends email by installing a diagnostic plugin (e.g. WP Mail SMTP Check) before testing share links in production.

If email delivery is unreliable, configure an SMTP plugin (WP Mail SMTP, FluentSMTP, etc.) before enabling sharing.

---

## Uninstalling

1. In **Secure Transfer → Settings → Data & Privacy**, enable **Delete all plugin data on uninstall** if you want data removed.
2. Deactivate the plugin in **Plugins → Installed Plugins**.
3. Click **Delete**.

With the option enabled, deletion permanently drops all five database tables, deletes all encrypted files from disk, and removes all plugin options and transients. **This cannot be undone.**

With the option disabled (default), all data remains in the database and on disk after the plugin is removed. Reactivating the plugin later restores full functionality.
