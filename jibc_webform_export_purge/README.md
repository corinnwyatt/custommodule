# JIBC Webform Export & Purge

A custom Drupal module that emails webform submissions as a CSV attachment on a weekly schedule, then purges the exported submissions. No external services required.

**Compatible with:** Drupal 10.6+ and Drupal 11  
**Requires:** Webform module (`drupal/webform`)  
**Mail compatibility:** SMTP module, SendGrid, Symfony Mailer, or Drupal core mail

---

## How It Works

1. On your chosen day of the week, Drupal's cron fires this module.
2. The module loads ALL submissions for the selected webform(s).
3. Submissions are converted into a CSV file (UTF-8, Excel-compatible).
4. The CSV is emailed as an attachment to the configured address(es).
5. **Only after successful email delivery** are the submissions deleted.
6. If the email fails, submissions are preserved for the next cron run.

## Installation

1. Copy the `jibc_webform_export_purge` folder into `modules/custom/`.
2. Enable the module:
   ```
   drush en jibc_webform_export_purge
   ```
3. Configure at: **Admin > Configuration > System > JIBC Webform Export & Purge**  
   `/admin/config/jibc-webform-export-purge`

## Configuration (Admin UI)

The settings page at `/admin/config/jibc-webform-export-purge` provides:

- **Enable/Disable** — Master toggle.
- **Recipient email(s)** — One or more addresses, comma-separated.
- **Email subject & body** — Customizable. Use `[webform_id]` as a placeholder.
- **Webform selector** — Checkboxes showing all webforms with current submission counts.
- **Export day** — Pick which day of the week to run.
- **Run Now button** — Immediately exports and purges (use with caution).

## SMTP Module Compatibility

This module is fully compatible with the **SMTP Authentication Support** module
(`drupal/smtp`). It uses the standard `$message['params']['attachments']` format
that PHPMailer (used by the SMTP module) supports natively. No special
configuration is needed — if SMTP is your active mail system, this module's
emails will route through it automatically, including the CSV attachment.

If you later switch to **SendGrid**, **Symfony Mailer**, or another mail system,
the attachment format is compatible with those as well.

## CSV Format

The generated CSV includes these columns:

| Column | Description |
|--------|-------------|
| `sid` | Submission ID |
| `serial` | Webform serial number |
| `created` | Date/time submitted |
| `remote_addr` | IP address of submitter |
| *(field columns)* | One column per webform field |

Composite fields (like Name with first/last) are flattened with dot notation:
`name.first`, `name.last`.

Multi-value fields (like checkboxes) are comma-separated within the cell.

The CSV includes a UTF-8 BOM so Excel opens it correctly.

## Logging

All activity logs to Drupal's watchdog as `jibc_webform_export_purge`:

```
drush watchdog:show --type=jibc_webform_export_purge
```

Or check **Admin > Reports > Recent log messages**.

## Safety Features

- Submissions only purge after confirmed email send.
- Double-run prevention (20-hour cooldown).
- No REST API endpoints exposed.
- Email validation on the settings form.
- CSRF token protection on the manual run route.

## Uninstall

```
drush pmu jibc_webform_export_purge
```

Removes the module and its configuration.
Does NOT affect existing webform submissions.
