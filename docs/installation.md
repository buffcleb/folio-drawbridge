# Installation Guide

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.3 |
| PHP | 7.4 |
| PHP extensions | `openssl`, `mbstring` |
| PHP extensions (optional) | `zip` — required for ZIP bulk download on recipient pages |
| MySQL / MariaDB | 5.6 / 10.0 |

Verify extensions are active before installing:

```php
// Add temporarily to functions.php — remove after checking.
var_dump( extension_loaded('openssl'), extension_loaded('mbstring') );
```

---

## Installing the Plugin

1. Upload the `folio-drawbridge` directory to `/wp-content/plugins/`.
2. In wp-admin navigate to **Plugins → Installed Plugins** and activate **Folio Drawbridge**.

On first activation the plugin automatically:
- Creates five database tables (`sft_vaults`, `sft_files`, `sft_shares`, `sft_otps`, `sft_audit`).
- Creates `wp-content/uploads/sft-vaults/` with an `.htaccess` file blocking direct HTTP access.
- Creates `wp-content/uploads/sft-chunks/` (chunked upload staging area, also `.htaccess` protected).
- Schedules the hourly lifecycle cron event (`sft_hourly_lifecycle`).

---

## Initial Setup Checklist

After activation, complete these steps before accepting uploads:

- [ ] **Generate or configure the master encryption key** — see [Encryption Key Setup](configuration.md#encryption-key).
- [ ] **Confirm file storage is writable** — **Folio Drawbridge → Dashboard → Security Status** shows a green checkmark if the upload directory exists and is writable.
- [ ] **Verify cron is running** — Security Status also confirms the lifecycle cron is scheduled. If it shows missing, deactivate and reactivate the plugin.
- [ ] **Configure share limits** — **Folio Drawbridge → Settings** lets you set defaults and maximums for download counts and link expiration windows.
- [ ] **Grant user access** — by default only WordPress administrators can use vault features. See [Granting User Access](admin-guide.md#granting-user-access) to enable non-admin users.

![Admin Dashboard - Full](/images/AdminDashboard_Full.jpg)

---

## Upgrading

Upgrades are non-destructive. The plugin does not run `DROP TABLE` statements on update — only `CREATE TABLE IF NOT EXISTS`, so existing data is always preserved.

From v1.2.0 onwards the plugin tracks its own database version via the `sft_db_version` option (matching the `SFT_DB_VERSION` constant). On `plugins_loaded`, `sft_maybe_upgrade_db()` compares the stored version to the current constant and runs `dbDelta()` if they differ, adding any new columns or indexes without touching existing data. This means upgrading from an older version is handled automatically on the next page load after the plugin files are replaced — no manual activation step required for schema changes.

---

## Server-Side Email Configuration

The two-factor sharing flow depends on WordPress being able to send email reliably. Verify your site sends email by installing a diagnostic plugin (e.g. WP Mail SMTP Check) before testing share links in production.

If email delivery is unreliable, configure an SMTP plugin (WP Mail SMTP, FluentSMTP, etc.) before enabling sharing.

---

## Uninstalling

1. In **Folio Drawbridge → Settings → Data & Privacy**, enable **Delete all plugin data on uninstall** if you want data removed.
2. Deactivate the plugin in **Plugins → Installed Plugins**.
3. Click **Delete**.

With the option enabled, deletion permanently drops all five database tables, deletes all encrypted files from disk, and removes all plugin options and transients. **This cannot be undone.**

With the option disabled (default), all data remains in the database and on disk after the plugin is removed. Reactivating the plugin later restores full functionality.
