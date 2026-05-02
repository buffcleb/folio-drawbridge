# Administrator Guide

This guide covers the Secure Transfer admin panel, accessible at **Secure Transfer** in the wp-admin sidebar. Access requires `manage_options` (WordPress administrator) or the `sft_admin` capability.

![Admin Dashboard - Full With Menu](/images/AdminDashboard_FullWithMenu.jpg)

---

## Dashboard

The Dashboard gives a real-time overview of the entire plugin.

![Admin Dashboard - Full](/images/AdminDashboard_Full.jpg)

### Stats cards

| Card | Description |
|---|---|
| Active Vaults | Vaults in the active state |
| Encrypted Files / Total Size | All files across every vault |
| Active Shares | Share links that are pending or active and not expired |
| Total Downloads | Cumulative download count |
| OTP Failures (30d) | Failed two-factor attempts in the last 30 days — elevated counts may indicate a brute-force attempt |
| Audit Events | Total rows in the audit log |

The **7-Day Download Activity** sparkline charts daily download volume. Use it to spot unusual spikes.

The **Recent Activity** table lists the 10 most recent audit events site-wide.

### Security Status card

Shows the current state of key security controls:

| Indicator | Meaning |
|---|---|
| Key Source | `wp-config.php constant` is the most secure. `database` means the key is in wp_options — move it to wp-config.php. |
| Algorithm | AES-256-CBC with unique IV per file. |
| OTP TTL | How long a verification code is valid. |
| Storage Path | Where encrypted files are written. Should be .htaccess-protected. |
| Cron | Confirms the lifecycle cron is scheduled. If missing, deactivate and reactivate the plugin. |

---

## Vaults Tab

Lists every vault on the site across all users.

![Admin Dashboard - Vaults](/images/AdminDashboard_Vaults.jpg)

### Filtering and sorting

Use the **Filter Vaults** panel to narrow by:
- **Status** — active, expired, revoked, archived.
- **Search Name** — partial match on vault name.

Click any sortable column header (Name, Status, Created, Expires) to sort. Click again to reverse. The sort and filter state persists in the URL, so you can bookmark filtered views.

### Vault Inspector

Click a vault name or **Inspect** to open the vault inspector.

![Admin Dashboard - Vaults Inspect](/images/AdminDashboard_VaultsInspector.jpg)

The inspector shows:
- **Encrypted Files** — download (decrypted) or delete any file. All admin downloads are logged.
- **Shares** — current status, recipient, download count, last access. Edit download limit/expiry or revoke any share.
- **Vault Audit Log** — last 25 events for this vault.
- **Vault Status** — change to active, expired, revoked, or archived.
- **Vault Expiry** — edit or clear the vault's expiry date.
- **Delete Vault** — permanently remove the vault, all files, and all shares. Cannot be undone.

All tables in the inspector are sortable client-side by clicking column headers.

---

## Audit Log Tab

The full, filterable, paginated event log for all plugin activity.

![Admin Dashboard - Audit Log](/images/AdminDashboard_AuditLog.jpg)


### Filtering

| Filter | Description |
|---|---|
| Event Type | Specific event — e.g. `file_downloaded`, `otp_failed`, `share_revoked` |
| Vault ID | Events for a specific vault (ID shown in the Vaults inspector URL) |
| From / To | Date range |
| Search Details | Case-insensitive keyword match against event detail data |

All columns (Event, Vault, Share, Actor, Date/Time) are sortable via the column headers.

### Exporting

**Export to CSV** downloads the current filtered result set. The export respects all active filters, so you can export a targeted subset.

### Pruning

**Manual Prune** in the filter sidebar permanently deletes all audit entries older than the number of days you specify. Runs immediately.

Auto-prune via WP-Cron can be configured in **Settings → Audit Log Retention**.

---

## Users Tab

Manage which non-administrator users have access to vault features.

![User Dashboard - Vault Detail](/images/UserDashboard_VaultDetail.jpg)

### Access roles

| Role | Capability | What they can do |
|---|---|---|
| **WordPress Admin** | `manage_options` | Everything — implicit, not listed |
| **SFT Admin** | `sft_admin` | Full admin panel — all tabs, vault inspector, audit export, settings, Users tab |
| **Vault User** | `use_sft_vaults` | My Vaults only — create, upload, share, revoke |

### Granting access

Search for any non-administrator by username or email. The search panel shows their current SFT status and presents contextual action buttons:

- **Grant Vault Access** — adds `use_sft_vaults`.
- **Grant SFT Admin Access** — adds `sft_admin`.
- **Promote to SFT Admin** — upgrades an existing Vault User.

### Modifying access

From the SFT Admins table:
- **Demote to User** — removes `sft_admin`, retains `use_sft_vaults`. The user keeps their vaults.
- **Remove All** — removes both capabilities. Vaults and files are preserved.

From the Vault Users table:
- **Make SFT Admin** — promotes to SFT Admin.
- **Revoke** — removes `use_sft_vaults`. Vaults and files are preserved.

Both tables are sortable by clicking column headers.

All changes are recorded in the audit log.

---

## Granting User Access

Quick summary for new installs:

1. Go to **Secure Transfer → Users**.
2. Type the user's login name or email in the search box and click **Search**.
3. Click **Grant Vault Access** (for a standard vault user) or **Grant SFT Admin Access** (for a delegated admin).
4. The user immediately sees the appropriate menu item in their wp-admin sidebar.

---

## WordPress Dashboard Widget

An **admin vault overview** widget appears on the WordPress dashboard for all SFT Admins. It shows:
- Total and active vault counts
- File count and total encrypted storage size
- Active share count
- Downloads in the last 7 days
- OTP failures in the last 30 days (highlighted in red if > 0)
- A link to the full admin panel

![WordPress Dashboard - Admin Widget](/images/WordpressDashboard_AdminWidget.jpg)

---

## Settings Tab

See the [Configuration Guide](configuration.md) for full documentation of every setting.

---

## SIEM Integration

When **SIEM Logging** is enabled, every audit event is appended to a log file in JSON (NDJSON) or CSV format.

**Example JSON line:**
```json
{"timestamp_utc":"2026-05-02T14:32:11Z","event":"file_downloaded","vault_id":12,"share_id":7,"actor_id":null,"ip":"203.0.113.42","details":{"file_id":34,"original_name":"report.pdf"},"site":"https://example.com"}
```

**Splunk:** Point a Universal Forwarder monitor at the log file path.  
**Datadog:** Configure a log agent to tail the file.  
**ELK:** Use Filebeat with the JSON codec for the NDJSON format.
