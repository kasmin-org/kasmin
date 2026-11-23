# Installing Kasmin

Requirements: PHP 8.3 or newer (see the README for the supported versions) with the extensions `pdo_mysql`, `intl`,
`mbstring`, `openssl`, `zip`, `gd`, `bcmath`, `fileinfo`; MariaDB or MySQL; a cron job for automations (later
versions).

## On ordinary web hosting (recommended way)

1. Create an empty MariaDB/MySQL database in your hosting control panel.
2. Download `installer/kasmin-setup.php` from the repository and upload it to your web space.
3. Open it in the browser. It shows the access code location: the file `kasmin-setup-code.txt` next to the script
   (read it via FTP). This makes sure only someone with access to the web space can install.
4. Choose a version (the latest 10 releases are listed with date and changelog) – or, for testing only, tick
   *development mode* and choose a branch.
5. Enter the target directory and the database data. The script downloads the package, **verifies its SHA-256
   checksum**, unpacks it and writes the configuration file `.env`.
6. Point the document root of your domain to the `public` folder of the target directory and open `/install`:
   organisation name, language, **default content (German, English, or none – not recommended)** and the first
   administrator.

## With Docker

The image is based on Alpine Linux (currently 3.24) with Apache and PHP 8.5, as small as possible; every build
installs all available security updates. `bash` and `vim` are included for maintenance (`docker exec -it <container> bash`).

Images: `ghcr.io/kasmin-org/kasmin:<version>` (and `latest` for the newest release; `<branch>` and `sha-<commit>`
for test builds). Pass the database settings as environment variables (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `APP_KEY`, `APP_URL`) and open `/install`.

## On the command line

```sh
php kasmin-setup.php --target=/srv/kasmin --db-host=localhost --db-name=kasmin --db-user=kasmin --db-password=…
cd /srv/kasmin && php artisan kasmin:install
```

`php kasmin-setup.php --list` shows the releases, `--list-branches` the development builds, `--release=v0.1.0` or
`--branch=develop` chooses one, `--source=file.zip` installs a local package (used by the CI tests).

Upgrades: see [upgrading.md](upgrading.md).
