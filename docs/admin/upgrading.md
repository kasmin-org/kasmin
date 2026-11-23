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
