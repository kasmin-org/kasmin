# Development setup

Requirements: Docker with Compose. PHP and Composer run inside containers.

```sh
export KASMIN_UID=$(id -u) KASMIN_GID=$(id -g)
cp .env.example .env
docker compose -f compose.dev.yaml up -d db mail
docker compose -f compose.dev.yaml run --rm app composer install
docker compose -f compose.dev.yaml run --rm app php artisan key:generate
docker compose -f compose.dev.yaml run --rm app php artisan migrate
docker compose -f compose.dev.yaml up -d app
```

- Application: http://127.0.0.1:8000
- Mailpit (catches all mails): http://127.0.0.1:8025
- Database: MariaDB 11 (service `db`, database/user/password `kasmin`)

## Checks before pushing

```sh
docker compose -f compose.dev.yaml run --rm app vendor/bin/pint --test        # code style
docker compose -f compose.dev.yaml run --rm app vendor/bin/phpstan analyse    # static analysis (level 8)
docker compose -f compose.dev.yaml run --rm app php artisan test              # tests
docker compose -f compose.dev.yaml run --rm app composer audit                # known vulnerabilities
```

The documentation for administrators exists in English (`docs/admin`) and in German (`docs/de/admin`), with the same file names.
Every change is made in both languages; `tests/Feature/System/DocsGermanTest.php` fails when a page or a `## ` section is missing in one
of them.

## Dynamic security scan (CI job `dast`)

Besides the static scans (Semgrep, CodeQL) the CI scans the running application with
[OWASP ZAP](https://www.zaproxy.org/docs/docker/baseline-scan/). The job `dast` runs with every push and pull request
and in the weekly run: it installs a demo instance on SQLite, serves it and runs `tests/zap/scan.sh`, which starts the
ZAP baseline scan (spider and passive rules, no attacks) twice:

1. the public pages without a login: login, registration, `/up`, the API reference, the public page of an event and
   public forms (`tests/zap/prepare.php` makes them, the demo data has none);
2. the admin interface and the portal with the session of the administrator, who logs in with a one-time link. Forms
   are not sent in this run.

The job fails when a report holds a finding of risk medium or higher that `tests/zap/rules.tsv` does not list with a
written reason, and when the session is not logged in at the start or at the end. The reports (HTML and JSON, and the
list of scanned addresses) are in the artifact `zap-report` of the run.

For a new finding: fix it (the headers are set in `app/Http/Middleware/SecurityHeaders.php` and checked by
`tests/Feature/System/SecurityHeadersTest.php`), or – for a false positive or something only the test server causes –
add a line with the reason to `tests/zap/rules.tsv`. To run the scan against a local instance with demo data
(needs Docker, curl and Python 3; it changes the data of the instance):

```sh
ARTISAN="docker compose -f compose.dev.yaml exec -T app php artisan" sh tests/zap/scan.sh http://127.0.0.1:8000 /tmp/zap-report
```

Limits: the scan only reads answers (no attacks, so no check for injections), the spider does not run scripts and so
does not open dialogs of the admin interface, and rule 10099 (source code disclosure) is switched off for the admin
interface because it does not finish on its large pages.

## Replay of two camp years

`tests/Feature/Replay` plays three seasons day by day (sign-up, registrations, invoices, payments, reminders, cancellations, team, bus,
groups, safety sheets, lists, closing) and fails with a list of everything that went wrong. It is not part of the normal suite:

```
vendor/bin/phpunit --group replay                       # fictitious families, a few minutes; own CI job
KASMIN_REPLAY_DATA=/path/data.json vendor/bin/phpunit --group replay      # an anonymised data set of a real organisation
KASMIN_REPLAY_SMTP_HOST=… KASMIN_REPLAY_SMTP_USER=… KASMIN_REPLAY_SMTP_PASSWORD=… …   # really send every mail (SMTPS, port 465)
KASMIN_REPLAY_PROGRESS=1                                 # one line per simulated month
KASMIN_REPLAY_UNTIL=2025-09-01                           # stop earlier
```

A data set has the shape of `ReplayData::synthetic()`. Real data must be anonymised before and must never be committed; use a mail
domain of your own whose mailbox catches every address, and read the delivered mails afterwards – the invoice PDFs of 880 KB were found
that way. With `DB_DATABASE` pointing to a file the database of the run is kept and can be opened in the browser.

