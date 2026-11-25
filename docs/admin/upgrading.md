# Upgrading Kasmin

Kasmin only upgrades; there are no downgrades.

1. Install the new version (release ZIP via the installer, or a new Docker image tag).
2. Until the database is upgraded, Kasmin shows "Upgrade required" and does not process requests.
3. Run the upgrade:

   ```sh
   php artisan kasmin:upgrade
   ```

   It writes a compressed backup of the database to `storage/app/backups/` first, then runs the migrations and
   records the new version. Only one upgrade can run at a time.

4. `php artisan kasmin:status` shows the installed version, the database version and the schema status.

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

### Restore a backup

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
