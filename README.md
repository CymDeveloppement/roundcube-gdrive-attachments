# Roundcube Google Drive Attachments

Sends the attachments that exceed the message size limit of
[Roundcube](https://roundcube.net) webmail to a Google Workspace **shared
drive**, with a **service account** of the company, and replaces them in the
message with a download link, like Gmail does.

Users do not need a Google account and do not sign in to anything. The files
belong to the company, in a shared drive managed by its administrators.

## Features

- **Large files intercepted in the compose screen**: when a file exceeds the
  attachment limit, the user is asked whether to share it via Google Drive
  (or it is uploaded right away, see `gdrive_attachments_behavior`).
- **Soft limit**: files that could be attached but are large may also be
  offered as a link (`gdrive_attachments_softlimit`).
- **"Anyone with the link" sharing**: recipients download the file without a
  Google account. The file is not listed in searches.
- **Link inserted in the message**, before the signature, in HTML and plain
  text messages. A small HTML file holding the link is also attached, with the
  `X-Mozilla-Cloud-Part` header used by Thunderbird (optional).
- **One folder per user** (configurable, e.g. `{username}/{year}-{month}`).
- **Resumable upload in 8 MB chunks**: large files never have to fit in
  memory, and an interrupted chunk is resumed.
- **Retention**: files are moved to the trash after a number of days by a
  cron script; the date is shown next to the link.
- **Removal**: removing the attachment in the compose screen offers to also
  remove the file from Google Drive.

## Requirements

- Roundcube 1.6 or later (tested with 1.6.19 and 1.7.4)
- PHP 7.3 or later with the openssl and json extensions
- Google Workspace with shared drives

## Google Workspace setup

1. **Create a service account** in the [Google Cloud console](https://console.cloud.google.com/):
   create (or pick) a project, enable the **Google Drive API** (*APIs &
   Services > Library*), then *IAM & Admin > Service accounts > Create*.
   No role is needed on the project.
2. **Create a JSON key** for it (*Keys > Add key > JSON*) and copy the file to
   the Roundcube server, out of the web root, readable by the web server only:
   ```bash
   chown root:www-data /etc/roundcube/gdrive-key.json
   chmod 640 /etc/roundcube/gdrive-key.json
   ```
   If your organization policy blocks key creation
   (`iam.disableServiceAccountKeyCreation`), an administrator must allow it
   for this project.
3. **Create a shared drive** (e.g. "Mail attachments") and add the service
   account's address (`name@project.iam.gserviceaccount.com`) as a member with
   the **Content manager** role.
4. **Allow link sharing outside the organization** in the Admin console
   (*Apps > Google Workspace > Drive and Docs > Sharing settings*), for the
   organizational unit and for the shared drive (*Shared drive settings*:
   "Allow people outside the organization to access files"). Otherwise
   creating the link fails and the upload is cancelled.
5. Copy the **shared drive id**: the last part of its URL,
   `https://drive.google.com/drive/folders/<id>`.

Service accounts cannot store files in their own drive (Google gives them no
storage quota): a shared drive is required. Without shared drives, the
service account can impersonate a user with domain-wide delegation
(`gdrive_attachments_subject`), and the files then use that user's storage.

## Installation

```bash
cd /path/to/roundcube/plugins
git clone https://github.com/CymDeveloppement/roundcube-gdrive-attachments roundcube_gdrive_attachments
cd roundcube_gdrive_attachments
cp config.inc.php.dist config.inc.php
```

Then enable the plugin in `config/config.inc.php`:

```php
$config['plugins'] = array(..., 'roundcube_gdrive_attachments');
```

### Size limits

Files going to Google Drive still go through the Roundcube server, so two
limits must be set apart:

- **PHP** (`upload_max_filesize` and `post_max_size`): the largest file a
  user may share, e.g. `2G`. With the official Docker image:
  `ROUNDCUBEMAIL_UPLOAD_MAX_FILESIZE=2G`.
- **Roundcube** (`max_message_size` in `config/config.inc.php`): the actual
  limit of your mail server, e.g. `'25M'`. Attachments above
  `max_message_size / 1.33` (base64 overhead) go to Google Drive.

Large uploads can also hit the limits of the web server (e.g.
`LimitRequestBody` for Apache, `client_max_body_size` for nginx) and its
timeouts. The plugin removes the PHP execution time limit during the upload.

## Configuration

In `config.inc.php` (see `config.inc.php.dist` for all options):

```php
$config['gdrive_attachments_key_file'] = '/etc/roundcube/gdrive-key.json';
$config['gdrive_attachments_drive_id'] = '0AbCdEfGhIjKlUk9PVA';
$config['gdrive_attachments_folder'] = '{username}/{year}-{month}';
$config['gdrive_attachments_behavior'] = 'prompt';
$config['gdrive_attachments_retention_days'] = 30;
```

| Option | Default | Description |
|---|---|---|
| `gdrive_attachments_key_file` | `''` | JSON key of the service account. The plugin is disabled while empty. |
| `gdrive_attachments_drive_id` | `''` | Id of the shared drive |
| `gdrive_attachments_folder_id` | `null` | Folder of the shared drive to use instead of its root |
| `gdrive_attachments_subject` | `null` | Account to impersonate (domain-wide delegation), without shared drive |
| `gdrive_attachments_folder` | `'{username}/{year}-{month}'` | Folder of the files: `{username}`, `{local}`, `{domain}`, `{year}`, `{month}`, `{day}` |
| `gdrive_attachments_behavior` | `'prompt'` | `'prompt'`: ask the user; `'upload'`: upload right away |
| `gdrive_attachments_softlimit` | `null` | Also offer Drive for files larger than this (e.g. `'10M'`) |
| `gdrive_attachments_attach_html` | `true` | Also attach an HTML file holding the link |
| `gdrive_attachments_retention_days` | `30` | Days before the cleanup script trashes the files (0 = kept) |
| `gdrive_attachments_timeout` | `300` | Timeout of each request to Google, in seconds |
| `gdrive_attachments_excluded_users` | `[]` | Logins for which the plugin is disabled |

## Cleanup of expired files

Each file records its expiration date. Run the cleanup script daily, as the
user running Roundcube:

```cron
0 3 * * * php /path/to/roundcube/plugins/roundcube_gdrive_attachments/bin/cleanup.php
```

`--dry-run` lists the expired files without trashing them. Files are moved to
the trash of the shared drive, which Google empties after 30 days. Changing
`gdrive_attachments_retention_days` only affects files uploaded afterwards.

## Notes

- Anyone who has the link can download the file: this is the purpose, but
  the link must be treated like the file itself.
- A draft reopened later keeps its link, but removing the attachment then no
  longer offers to remove the file from Drive (the cleanup script still
  trashes it when it expires). With `gdrive_attachments_attach_html = false`,
  the HTML file of such a draft is sent as a regular attachment.
- When a message is not sent, its files stay on Drive until they expire.
- Errors are written to the Roundcube error log, prefixed with
  `roundcube_gdrive_attachments:`.

## License

MIT. The interception of uploads in the compose screen is adapted from
[nextcloud_attachments](https://github.com/bennet0496/nextcloud_attachments)
by Bennet Becker (MIT). See [LICENSE](LICENSE).
