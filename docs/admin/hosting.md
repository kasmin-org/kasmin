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

# Hosting without a command line

Many web hosting packages offer no SSH access and run cron jobs only as HTTP calls. Kasmin therefore offers
protected HTTP hooks. They are **disabled** unless `KASMIN_HOOK_TOKEN` is set in `.env` (at least 32 random
characters). The token is sent as `Authorization: Bearer <token>`; services that cannot send headers may use
`?token=<token>` (cron only).

| Hook | Purpose |
|---|---|
| `POST /system/hooks/upgrade` | Runs the upgrade (`kasmin:upgrade`, with backup) – call it after uploading a new release |
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
