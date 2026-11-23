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
