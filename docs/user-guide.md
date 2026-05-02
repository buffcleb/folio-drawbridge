# User Guide — My Vaults

This guide is for users who have been granted **Vault User** or **SFT Admin** access by a site administrator. If you can't see the **My Vaults** menu in your wp-admin sidebar, contact your administrator.

---

## Overview

A **vault** is an encrypted file container. You fill it with files, and then share it securely with people outside the site via a two-factor verification flow — recipients receive an invite email, confirm their address, and verify a one-time code before they can download anything.

The **My Vaults** dashboard is at **wp-admin → My Vaults**.

![User Dashboard - My Vaults](/images/UserDashboard_MyVaults.jpg)

---

## Your Vaults List

The list shows all your vaults with:
- **Status** — active, expired, or archived.
- **Files** — count of encrypted files stored in the vault.
- **Shares** — count of share links created for the vault.
- **Created** and **Expires** — dates in the site's configured timezone.

Click any column header to sort the list by that column. Click again to reverse the sort direction.

Click a vault name or **Open** to manage its files, shares, and activity log.

### Vault statuses

| Status | Meaning |
|---|---|
| Active | Files can be uploaded; share links can be created |
| Expired | Past the vault's expiry date. No new uploads or shares. Existing active shares stop working. |
| Archived | Closed by an administrator. Behaves like expired. |
| Revoked | Closed by an administrator. Behaves like expired. |

---

## Creating a Vault

Use the **Create New Vault** form at the bottom of the My Vaults page.

| Field | Required | Description |
|---|---|---|
| Vault Name | Yes | Short label — e.g. "Q1 Reports" or "Onboarding Pack – Jane Smith" |
| Description | No | Internal note visible only to you and administrators |
| Expiry Date | No | Date after which the vault and its shares stop working. Leave blank for no expiry (if your admin permits). |

After creating the vault you are taken directly to its detail page.

![User Dashboard - Create New Vault](/images/UserDashboard_CreateNewVault.jpg)

---

## Vault Detail Page

![User Dashboard - Create New Vault](/images/UserDashboard_VaultDetail.jpg)

### Editing vault expiry

Click **Edit Expiry** at the top of the vault detail page to change or clear the expiry date.

---

## Uploading Files

In the **Files** section, click **Encrypt & Upload** (or drag a file onto the upload area).

- Files are encrypted on the server before being written to disk. The original unencrypted version is never stored.
- Large files are uploaded in chunks, so you can upload files larger than the server's PHP `upload_max_filesize` limit.
- A progress bar shows chunk-by-chunk upload progress for large files.
- The maximum file size is configured by your administrator (shown as reference info on the upload form).

![User Dashboard - Vault File Upload](/images/UserDashboard_VaultFileUpload.jpg)

### Deleting files

Click **Delete** next to a file. Deletion is permanent — the encrypted file is removed from disk immediately and cannot be recovered.

---

## Sharing a Vault

In the **Shares** section, use the **Share this vault** form to invite a recipient.

| Field | Description |
|---|---|
| Recipient Email | The email address of the person you want to share with |
| Download Limit | Maximum number of times the vault can be downloaded (0 = unlimited, if your admin permits) |
| Link Expires | Date after which the share link stops working (leave blank for no expiry, if permitted) |

Click **Send Invite**. The recipient receives an email with a unique link.

![User Dashboard - Vault Share](/images/UserDashboard_VaultShare.jpg)


### How the recipient receives the files

The two-factor share flow:

1. **Invite email** — recipient receives an email with a unique link. The link encodes a secret token; it is single-use for the OTP step.
2. **Email confirmation** — recipient opens the link and enters their email address to verify they are the intended recipient.
3. **One-time code** — a 6-digit code is emailed to the recipient. They have a limited time to enter it (configured by your admin, default 15 minutes).
4. **Download access** — after successful verification, the recipient sees all files in the vault and can download them. A 30-minute download session is issued.

![Recipient - Email Confirmation](/images/Recipient_EmailConfirmation.jpg)
![Recipient - One Time Code](/images/Recipient_OneTimeCode.jpg)
![Recipient - Download access](/images/Recipient_DownloadPage.jpg)

### Editing a share

While a share is pending or active, click **Edit** next to it to change the download limit or expiry date without revoking and recreating the share.

### Revoking a share

Click **Revoke** to immediately block the recipient's access. Any active download session they hold is invalidated. The share record is retained in the activity log for audit purposes.

---

## Activity Log

The **Activity Log** section shows the 20 most recent events for this vault — uploads, downloads, share events, OTP verifications, and more. Each entry shows:

- **Event** — what happened.
- **Details** — contextual information (e.g. file name, share recipient).
- **IP** — the IP address of the actor.
- **Date/Time** — in the site's configured timezone.

The log is read-only. Click any column header to sort.

Administrators can see the full unfiltered audit log across all vaults at **Secure Transfer → Audit Log**.

---

## WordPress Dashboard Widget

If your administrator has enabled it, a **Secure File Transfer — My Vaults** widget appears on your WordPress dashboard (wp-admin home). It shows your vault and file counts, active share count, and your last 5 activity events at a glance.

![WordPress Dashboard widget](/images/Recipient_DownloadPage.jpg)

---

## Shortcode (Front-End)

The `[sft_my_vaults]` shortcode renders equivalent vault management functionality on any front-end WordPress page. All the same create/upload/share/revoke features are available. Useful for sites that want to keep editors or clients out of wp-admin entirely.
