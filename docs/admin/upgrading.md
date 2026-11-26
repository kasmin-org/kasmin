# Upgrading Kasmin

Kasmin only upgrades; there are no downgrades.

1. Install the new version (*System → System status → Update*, the release ZIP via the installer, or a new Docker image tag).
2. Until the database is upgraded, the login page shows a notice, and **only administrators can log in**. Everything else waits.
3. Log in as administrator with a new login link and press **Upgrade now**. Kasmin writes a compressed backup of the database to
   `storage/app/private/backups` first, then upgrades the database and records the new version. This can take a few minutes; only one
   upgrade runs at a time. If it does not finish, the page shows the details and can be tried again.

While the files of a new version are put in place, visitors see a short page "Kasmin is being updated" (HTTP 503) instead of errors from
half-replaced files; it disappears by itself.

No command line is needed. Where there is one, `php artisan kasmin:upgrade` does the same, `php artisan kasmin:status` shows the
versions, and deployment scripts can call `POST /system/hooks/upgrade` (see [Hosting](hosting.md)). Docker upgrades by itself at the start.

## Automatic updates

*Settings → Organisation and invoices → Automatic updates* (administrators, not in Docker; **on by default**, can be switched off): a new version is installed by
the cron job once it has been out for the configured number of days (default 10), at the configured time of day (default 04:00, in the time
zone of the settings). A backup of the database and the program files is made first.

- Two days before, all administrators get a notice with the date.
- If a newer version is released in between, the planned update is cancelled, the administrators are told, and the waiting time starts
  again for the newer version. So a version that is corrected shortly after is never installed.
- Afterwards the administrators get a mail with the changes. If the update did not work, the previous version keeps running, the
  administrators are told, and it is tried again the next night.

## Why Kasmin refuses to start after a downgrade

If the database was upgraded by a newer Kasmin version, older code must not work with it: it could misread or
damage data. In this case Kasmin stops all web requests and most commands and shows "Database is newer than this
version". Install the newer version again. Commands that would take the schema backwards (`migrate:rollback`,
`migrate:fresh`, …) are disabled outside development and test environments.

## Backups

Kasmin writes a database backup every night (02:30, needs the cron job) to `storage/app/private/backups` and keeps the last 7 daily ones
(*Settings → Keep daily backups*); backups before upgrades are kept. *System → System status* shows the last one. `php artisan kasmin:backup`
makes one at once. **Copy the backups to another place too**: they are on the same server, and they contain personal data. Uploaded files
and the `.env` file with the `APP_KEY` are not included; without this key, encrypted data such as safety sheets cannot be read.

### Backups in the browser

*System → System status → Backups* (administrators): **Back up now**, **Download** a backup, and – once a passphrase is set –
**Download encrypted**. Every download is recorded in the audit log.

### A copy outside the server

A backup on the same server does not help if the server is lost. *Settings → Organisation and invoices → Backup outside the server*:

| Setting | Meaning |
|---|---|
| Address of the folder | a folder of a storage service that speaks **WebDAV**, `https://` only – e.g. Nextcloud (`https://cloud.example.org/remote.php/dav/files/NAME/backups`) or a storage box |
| User name, password | of the storage; use an app password if the service offers one |
| Passphrase | at least 12 characters; **write it down outside of Kasmin** – without it the copies cannot be opened |

Every night (and with *Back up now*) the backup is encrypted with the passphrase and sent there; as many copies are kept as daily backups.
The system status shows when the last copy was sent, and an error if it failed. Password and passphrase are stored encrypted and are never
shown again.

**Open a copy again**: *System status → Backups → Open an encrypted backup*: upload the `.enc` file, get the `.sql.gz` back. Without Kasmin:
`openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE.sql.gz.enc -out FILE.sql.gz`.

### Restore a backup

**In the browser** (administrators, MySQL and MariaDB): *System → System status → Backups → Restore a backup*. Choose a backup of the
server or upload a file (`.sql.gz`, or an encrypted `.enc` with its passphrase), type the word shown and confirm. Kasmin checks that the
file is a complete backup of the same kind of database, saves the current state as a backup named `before-restore`, replaces everything in
the database and asks everybody to log in again. If the backup is older than the installed version, the upgrade notice appears and an
administrator starts the upgrade as usual. Uploaded files and the `.env` file with the `APP_KEY` are not part of a backup: restoring on
another server needs the same key, otherwise encrypted data such as safety sheets cannot be read.

**Without Kasmin** (e.g. when the installation does not start):

Restoring replaces **all** data of the target database. Stop Kasmin first (maintenance mode or stop the container), and keep a copy of the
current state. A complete backup ends with the line `-- End of backup`.

```
zcat kasmin-daily-….sql.gz | mysql -u USER -p DATABASE          # or mariadb; phpMyAdmin: Import
```

Backups made before 0.16 need the foreign key checks switched off by hand:

```
(echo "SET FOREIGN_KEY_CHECKS=0;"; zcat kasmin-….sql.gz) | mysql -u USER -p DATABASE
```

Afterwards run `php artisan kasmin:upgrade`. The `APP_KEY` in `.env` must be the one the backup was made with.
