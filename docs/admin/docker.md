# Docker: configuration, certificates, Let's Encrypt

The image (`ghcr.io/kasmin-org/kasmin`) is based on Alpine Linux with Apache and PHP 8.5. `latest` is the newest release,
`<version>` a certain one. `bash` and `vim` are included for maintenance.

## Quick start with MariaDB and Let's Encrypt

```yaml
services:
  kasmin:
    image: ghcr.io/kasmin-org/kasmin:latest
    container_name: kasmin
    restart: unless-stopped
    ports: ["80:80", "443:443"]
    environment:
      KASMIN_PUBLIC_URL: https://camp.example.org
      KASMIN_TLS: letsencrypt
      KASMIN_LETSENCRYPT_EMAIL: office@example.org
      KASMIN_ORGANISATION: Example Camp
      KASMIN_LOCALE: en
      KASMIN_ADMIN_NAME: Alex Example
      KASMIN_ADMIN_EMAIL: alex@example.org
      KASMIN_MAIL_HOST: mail.example.org
      KASMIN_MAIL_USERNAME: office@example.org
      KASMIN_MAIL_PASSWORD_FILE: /run/secrets/mail_password
      KASMIN_MAIL_FROM_ADDRESS: office@example.org
      DB_CONNECTION: mariadb
      DB_HOST: db
      DB_DATABASE: kasmin
      DB_USERNAME: kasmin
      DB_PASSWORD_FILE: /run/secrets/db_password
    secrets: [mail_password, db_password]
    volumes:
      - storage:/var/www/html/storage     # uploads, backups and the application key: back this up
      - certificates:/var/lib/kasmin
    depends_on: [db]
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: kasmin
      MARIADB_USER: kasmin
      MARIADB_PASSWORD_FILE: /run/secrets/db_password
      MARIADB_RANDOM_ROOT_PASSWORD: "yes"
    secrets: [db_password]
    volumes: ["database:/var/lib/mysql"]
secrets:
  mail_password: { file: ./secrets/mail_password }
  db_password: { file: ./secrets/db_password }
volumes: { storage: {}, certificates: {}, database: {} }
```

Mails, reminders and automations run in the container; no cron job is needed. A new image upgrades the database at its
first start (with a backup in the storage volume; `KASMIN_AUTO_UPGRADE=false` switches this off). Commands:
`docker exec kasmin kasmin kasmin:status` (`kasmin <artisan command>`).

## How the variables work

- **At the set-up** (empty database) the variables marked *database* are written to the database – with
  `KASMIN_ORGANISATION` and `KASMIN_ADMIN_EMAIL` automatically, otherwise when you finish `/install` in the browser.
- **Afterwards only the database counts.** A new container with an existing database never overwrites anything.
- If a variable **differs from the database**, every start writes a warning to the log (`docker logs kasmin`), e.g.
  `WARNING: KASMIN_INVOICE_DUE_DAYS is "30", but the database contains "21". The variable is ignored …`.
  Passwords are only named, never printed. Change the value in the admin interface, or correct the variable.
- Every variable marked *database* and `APP_KEY`, `DB_PASSWORD` can be given as file: `NAME_FILE=/run/secrets/…`.

### Set-up and organisation (database)

| Variable | Meaning |
|---|---|
| `KASMIN_PUBLIC_URL` | address of the installation, e.g. `https://camp.example.org`: links in mails, redirect, certificate |
| `KASMIN_ORGANISATION`, `KASMIN_ADMIN_EMAIL`, `KASMIN_ADMIN_NAME` | name of the organisation, first administrator |
| `KASMIN_LOCALE` (`de`/`en`), `KASMIN_DEFAULTS` (`de`/`en`/`none`) | language, default content |
| `KASMIN_ORGANISATION_ADDRESS`, `_EMAIL`, `_WEBSITE`, `_IBAN`, `_BIC`, `_BANK`, `_LEGAL_FOOTER` | data on invoices |
| `KASMIN_INVOICE_NUMBER_FORMAT`, `KASMIN_INVOICE_DUE_DAYS`, `KASMIN_INVOICE_ATTACH_PAID`, `KASMIN_INVOICE_REMINDER_DAYS_BEFORE`, `KASMIN_INVOICE_OVERDUE_DAYS` | [invoices](invoices.md) |
| `KASMIN_RETENTION_SAFETY_YEARS`, `KASMIN_RETENTION_INVOICE_YEARS` | [retention](privacy.md) |
| `KASMIN_AI_PROCESSING` (`none`/`non_confidential`/`all`) | [AI assistants](api.md) |
| `KASMIN_DIGEST_TIME` (`07:00`), `KASMIN_APPROVAL_DAYS` (`7`) | digest, approval requests |

### Mail (database)

| Variable | Meaning |
|---|---|
| `KASMIN_MAIL_HOST`, `KASMIN_MAIL_PORT`, `KASMIN_MAIL_ENCRYPTION` (`ssl`, `tls`) | mail server; port 465 for `ssl`, 587 for `tls` by default. Unencrypted delivery is not possible |
| `KASMIN_MAIL_USERNAME`, `KASMIN_MAIL_PASSWORD` | login |
| `KASMIN_MAIL_FROM_ADDRESS`, `KASMIN_MAIL_FROM_NAME`, `KASMIN_MAIL_REPLY_TO` | sender |
| `KASMIN_MAIL_RATE_PER_MINUTE`, `_PER_HOUR`, `_PER_DAY` | limits of the provider |
| `KASMIN_IMAP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`, `_SENT_FOLDER` | replies and copies of sent mails |

The default mail account in the database is also used for login mails.

### Container (always read from the environment)

| Variable | Meaning |
|---|---|
| `DB_CONNECTION` (`mariadb`, `mysql`, `sqlite`), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | database; without them SQLite in the storage volume |
| `APP_KEY` | key for encrypted data; created in the storage volume if missing. **Without it, safety sheets and passwords cannot be read** |
| `KASMIN_TLS` | `auto` (default), `custom`, `letsencrypt`, `selfsigned`, `off` |
| `KASMIN_TLS_CERT`, `KASMIN_TLS_KEY`, `KASMIN_TLS_WATCH_SECONDS` | own certificate, see below |
| `KASMIN_LETSENCRYPT_EMAIL`, `KASMIN_ACME_SERVER` | Let's Encrypt, see below |
| `KASMIN_TRUSTED_PROXIES` | addresses of your reverse proxy (comma-separated) |
| `KASMIN_SCHEDULER`, `KASMIN_AUTO_UPGRADE` | `true` by default |

These cannot come from the database, because they are needed to reach or protect it.

## HTTPS

Port 80 only answers Let's Encrypt and **redirects everything else to HTTPS** (port 443, TLS 1.2 and 1.3).

### Your own certificate

Mount the certificate (with the intermediate certificates) and the private key:

```yaml
    volumes:
      - ./certs/fullchain.pem:/certs/tls.crt:ro
      - ./certs/privkey.pem:/certs/tls.key:ro
```

or set other paths with `KASMIN_TLS_CERT` and `KASMIN_TLS_KEY`. Mount the **directory** instead of single files if another
program renews the certificate by replacing the files; Kasmin takes a new certificate over within 5 minutes without a
restart. A key that does not belong to the certificate stops the container with an error message.

### Let's Encrypt

`KASMIN_TLS=letsencrypt` with `KASMIN_PUBLIC_URL`: the container gets the certificate and renews it automatically (check twice
a day, renewal 30 days before the end). With this setting you accept the
[terms of Let's Encrypt](https://letsencrypt.org/repository/). The name must point to this server and **port 80 must be
reachable from the internet**. Until the first certificate is there, a self-signed one is used. Keep the volume
`/var/lib/kasmin`, because Let's Encrypt limits the number of certificates per week. For tests:
`KASMIN_ACME_SERVER=https://acme-staging-v02.api.letsencrypt.org/directory`.

The name comes from the public URL **in the database**; if you move to another address, change it in *Settings → Organisation
and invoices → System* and restart the container.

### Behind a reverse proxy

`KASMIN_TLS=off` and `KASMIN_TRUSTED_PROXIES=<address of the proxy>`: the proxy provides HTTPS and forwards to port 80. Never
expose port 80 of such a container to the internet.

### Further certificate authorities

Certificates of an internal authority (e.g. for your mail server): mount `*.crt` files to
`/usr/local/share/ca-certificates`.
