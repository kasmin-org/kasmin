# Cron job and hosting without a command line

## The cron job (required)

Kasmin needs **one cron job that runs every 5 minutes** (every minute also works). It sends the mail queue, reminders, digests and
automations. If it is missing, administrators get the notification "Cron job not configured" and the system
status page shows an error.

**Linux server with crontab** (`crontab -e` as the user that owns the Kasmin files):

```
*/5 * * * * cd /path/to/kasmin && php artisan schedule:run >> /dev/null 2>&1
```

**Docker:** not needed, the container runs the scheduler itself.

**Web hosting whose cron jobs can only call URLs** (e.g. all-inkl.com): set `KASMIN_HOOK_TOKEN` in `.env` (at
least 32 random characters) and create a cron job that calls this URL every 5 minutes:

```
https://your-kasmin-domain.example/system/cron?token=YOUR_TOKEN
```

At all-inkl.com: KAS → *Tools* → *Cronjobs* → *Neuen Cronjob anlegen*: protocol `https`, the URL above (without
`https://`), a description such as "Kasmin scheduler", every 5 minutes (`*/5` for the minute, `*` for hour, day, month
and weekday; some packages refuse "every minute"). The answer of the URL is a short JSON text with `"ok":true`.

Daily and hourly jobs (digest, reminders, automations, retention) run once at the first call after their time, so a
call that comes a few minutes late does no harm. The "cron job not configured" warning appears
only after 15 minutes without a call.

## What the server must offer

*Administration → System → System status* lists every item below with a tick or a cross (*Show every item*), so you can compare your hosting with it.

| What | Needed for |
|---|---|
| PHP 8.3 or newer | see the README for the versions that are supported |
| MariaDB or MySQL | the data |
| **128 MB** memory for PHP (`memory_limit`) | enough for all pages. Only large PDFs (a camp folder for more than about 60 people) need more; Kasmin then asks for 256 MB for this one request, which most hosters allow. No `.user.ini` is needed. |
| a cron job, every minute or every few minutes | mail queue, reminders, automations, backups, automatic updates |

Required PHP extensions:

| Extension | Needed for |
|---|---|
| `pdo_mysql` | database (MySQL, MariaDB) |
| `intl` | dates, numbers and amounts in German and English |
| `mbstring` | texts with umlauts and other characters |
| `openssl` | encryption of confidential data, mail over TLS, backups |
| `zip` | updates, export as XLSX, upload of several files |
| `gd` | QR code on invoices, pictures |
| `bcmath` | exact amounts of money |
| `fileinfo` | checks what an uploaded file really is |
| `xml`, `dom` | PDFs (invoices, lists, camp folder), files of banks |
| `xmlreader` | import from Excel files |
| `iconv` | mails and files in other character sets |
| `zlib` | compressed backups |
| `tokenizer`, `ctype`, `json` | needed by the framework |

Recommended (Kasmin works without them, with the limits named):

| Item | Without it |
|---|---|
| extension `curl` | the search for new versions, the backup copy outside the server and webhooks may not work |
| function `proc_open()` | the cron job cannot start its tasks as a command – use the cron address by HTTP (below) |
| function `set_time_limit()` | a long upgrade or a big import can run into the time limit of the server |
| function `dns_get_record()` | the DNS records for mail (SPF, DKIM, DMARC) cannot be checked |

Folders Kasmin must be able to write to: `storage/` (uploads, backups, logs, sessions) and `bootstrap/cache/`.

If your hoster asks what is installed: *Administration → System → System status → PHP configuration (phpinfo)* shows the versions, extensions and limits of
the server. Only administrators see it, every view is recorded in the log, and the keys and passwords of the installation are left out.

# Hosting without a command line

Many web hosting packages offer no SSH access and run cron jobs only as HTTP calls. Kasmin therefore offers
protected HTTP hooks. They are **disabled** unless `KASMIN_HOOK_TOKEN` is set in `.env` (at least 32 random
characters). The token is sent as `Authorization: Bearer <token>`; services that cannot send headers may use
`?token=<token>` (cron only).

| Hook | Purpose |
|---|---|
| `POST /system/hooks/upgrade` | Runs the upgrade (`kasmin:upgrade`, with backup) – call it after uploading a new release |
| `POST /system/hooks/update` | The installation updates itself, like *Update* on the system page: downloads and verifies the release, backs up, replaces the files. `version=1.2.3` names the version, without it the latest one. The answer (JSON) holds `finish`: call this address next, it updates the database with the new files. `updated: false` means there was nothing newer. Not in Docker. |
| `GET or POST /system/cron` | Runs the scheduler once (mail queue, automations, reminders) – call it every few minutes from the hosting's cron job function |
| `POST /system/hooks/demo` | Demo instances only (`APP_ENV=demo`): installs Kasmin with fictitious demo data if the database is empty |

Use HTTPS only. A wrong or missing token answers "404 Not Found", so the hooks cannot be discovered.

## Demo instances

Demo instances use `APP_ENV=demo` and these settings:

| Setting | Meaning |
|---|---|
| `KASMIN_DEMO_LOCALE` | `de` or `en`: language of the demo data and the default content |
| `KASMIN_DEMO_ADMIN_EMAIL` | address of the first administrator |
| `KASMIN_DEMO_EMAIL_DOMAIN` | domain of the fictitious people's e-mail addresses – with a catch-all mailbox for this domain, all login links of the demo can be read there |

Demo instances update themselves: the release pipeline calls `POST /system/hooks/update` and checks the result, and
their automatic update is set to **one day** after a release, so they also follow without the pipeline.
