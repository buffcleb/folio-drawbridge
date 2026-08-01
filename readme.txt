=== Folio Drawbridge ===
Contributors: buffcleb
Tags: encryption, file-sharing, secure-files, two-factor, audit-log
Requires at least: 5.3
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and role-based vault oversight.

== Description ==

Folio Drawbridge lets authenticated WordPress users upload files into named **vaults**, where they are encrypted at rest using AES-256-CBC before being written to disk. Vault contents can be shared securely with external, unauthenticated recipients through a two-factor verification flow: invite email → email confirmation → one-time code. Every action across the plugin is recorded in an immutable audit log.

= Key features =

* **Encrypted vault storage** — AES-256-CBC with a unique per-vault key derived from a site-wide master key. Files stored with direct HTTP access blocked.
* **Two-factor external sharing** — recipients receive an invite link, confirm their email, then verify a time-limited one-time code before downloading.
* **Multi-file and chunked upload** — files split client-side and reassembled server-side, bypassing PHP `upload_max_filesize` limits.
* **ZIP bulk download** — recipients can download all vault files as a single archive (requires PHP `ZipArchive`).
* **File type restrictions and per-user storage quotas** — enforced server-side at upload time.
* **Role-based access** — two tiers of non-admin access: SFT Admin (full panel) and Vault User (My Vaults only).
* **Global share limits** — default and maximum download counts and link expiration windows, retroactively enforceable.
* **OTP rate limiting** — configurable cooldown between one-time-code requests.
* **Lifecycle management** — hourly WP-Cron expires vaults and shares, sends expiry warnings, prunes stale OTPs, and cleans orphaned upload chunks.
* **Download notifications and expiry warnings** — vault owners are emailed on recipient downloads and before share links expire.
* **Customisable email templates** — subject and body for all four system emails with `{placeholder}` tokens.
* **Immutable audit log** — every event logged with actor, IP, and timestamp. Filterable, sortable, exportable to CSV.
* **SIEM logging** — append every audit event to an OS log file in JSON (NDJSON) or CSV for Splunk, Datadog, ELK, and similar tools.
* **Vault inspector** — administrators can browse every vault, download files, edit metadata, transfer ownership, and revoke shares. All actions audited.

= Part of the Folio suite =

Folio Drawbridge shares a single "Folio" admin menu with the other Folio access and data-protection plugins when more than one is installed.

== Installation ==

1. Upload the `folio-drawbridge` directory to `/wp-content/plugins/`.
2. Activate **Folio Drawbridge** through the Plugins screen.
3. Complete the setup checklist under **Folio → Drawbridge → Dashboard → Security Status** — generate a master encryption key (recommended: store it in `wp-config.php`), confirm storage is writable, and verify the lifecycle cron is scheduled.
4. Grant users vault access from the **Users** tab (only WordPress administrators have access by default).

Requires the PHP `openssl` and `mbstring` extensions. The optional `zip` extension enables ZIP bulk download.

== Frequently Asked Questions ==

= Where are uploaded files stored? =

Encrypted files are written to `wp-content/uploads/sft-vaults/`, protected by an `.htaccess` deny-all rule. Files are never served directly — every download is decrypted and streamed through PHP after authorization.

= What happens if I lose the master encryption key? =

Encrypted files cannot be recovered without the master key. If you define `SFT_MASTER_KEY` in `wp-config.php`, back it up securely. Replacing the key permanently breaks decryption of existing files.

= Do share recipients need a WordPress account? =

No. Recipients verify their identity with their email address and a one-time code — no account or login required.

= Can I limit how many times a share link is used? =

Yes. Each share can have a download limit and an expiry date, and site-wide defaults and maximums can be configured under Settings. One download means one verified access: the recipient may retrieve every file in the vault during that session, so a limit of 1 lets them collect it once.

== Screenshots ==

1. Admin dashboard — vault, file, share, and download totals, seven-day download activity, recent events, and the security status panel showing key source, algorithm, storage protection, and cron health.
2. Vault inspector — encrypted file list with per-file admin download, shares showing live status including "Limit reached", ownership transfer, status control, and the vault's own audit trail.
3. Recipient download page after two-factor verification, with per-file downloads and the ZIP bulk download option.
4. Settings — two-factor verification (code validity, attempt limit, request rate limit) and download limits.
5. Settings — link expiration defaults and ceilings, chunked upload size limit, and audit log retention.
6. Settings — encryption key source and generator, SIEM log file export, and owner notification options.
7. Settings — file type allowlist and per-user storage quotas.
8. Settings — customisable templates for all four system emails, each with its available placeholder tokens.
9. Settings — data removal policy on uninstall, and encrypted storage location with its protection status.

== Changelog ==

= 1.2.0 =
* Multi-file upload with per-file progress.
* ZIP bulk download for recipients.
* Download notification emails to vault owners.
* Share expiry warning emails with configurable lead time.
* Customisable email templates with placeholder tokens.
* File type restriction allowlist.
* Per-user storage quotas.
* OTP request rate limiting (cooldown).
* Vault ownership transfer from the vault inspector.
* Database version tracking with automatic idempotent schema migration.

= 1.1.1 =
* Resend share invite button on pending and active shares.

= 1.1.0 =
* Sortable columns on all tables (server-side on paginated lists, client-side elsewhere).
* WordPress dashboard widgets for admins and vault users.
* Contextual "apply to existing shares" enforcement prompts in Settings.
* Expanded contextual help on every screen.
* Security: SIEM log path validation (absolute, no traversal).
* Security: Clipboard API for key copy with fallback.
* New documentation set under docs/.

= 1.0.2 =
* SFT Admin capability for non-administrator panel access.
* Users tab redesign with search and contextual actions.
* All timestamps display in the site's configured timezone.
* SIEM logging to OS file (NDJSON or CSV).
* Inline vault expiry and share editing for admins.

= 1.0.1 =
* Streaming encryption/decryption in 1 MB chunks for large files.
* Chunked uploads bypassing PHP size limits.
* Download limits and link expiration settings.
* Inline share and vault expiry editing.
* Configurable OTP attempt limit.
* Server-side encryption key generator.

= 1.0.0 =
* Initial release: encrypted vaults, two-factor sharing, immutable audit log, vault inspector, lifecycle cron.

== Upgrade Notice ==

= 1.2.0 =
Adds multi-file upload, ZIP download, notification emails, file type restrictions, storage quotas, and OTP rate limiting. Schema updates run automatically.
