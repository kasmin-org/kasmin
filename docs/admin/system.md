# System status, notifications and updates

## Time zone

*Settings → Organisation and invoices → Time zone*: all times and daily jobs. Installations before 0.13 run in UTC until a
time zone is chosen. Times already stored are not converted, so links and offers that are open at that moment may expire up
to two hours earlier or later.

## System status

*System → System status* (administrators only) shows:

- PHP version and the date until which it receives security updates
- missing PHP extensions, functions or write permissions
- whether the cron job runs (last run of the scheduler)
- old mails waiting in the queue
- database connection and schema version
- installed and latest Kasmin version
- **mail DNS records** of every sender domain: SPF, DKIM and DMARC, each with a concrete suggestion for the DNS
  record. DMARC should use `p=reject`; anything weaker is shown as a warning. With *direct delivery* (to the
  recipients' mail servers, without an SMTP relay) Kasmin signs every mail with DKIM – generate the key at the mail
  account and publish the record shown.

## System notifications

System notifications go **only to users with the administrator role**. They appear at the top of the admin interface
after login until they are dismissed and are sent **once** by e-mail:

- the cron job does not run
- the installed PHP version is the lowest one still supported (with its end-of-support date) – update PHP before
  newer Kasmin versions require a newer PHP (see [versioning](../development/versioning.md))
- a newer Kasmin version is available

## Updating

On ordinary web hosting any administrator can update Kasmin on the system page: *Check for updates* checks the
requirements of the new version (PHP version, extensions, functions, write permissions, disk space). *Update now*
downloads the package and verifies its checksum, backs up the database and the program files, replaces the files
(restoring the old ones if anything fails) and then updates the database in a second step.

**Docker:** the in-place update is disabled. Pull the new image (e.g. `ghcr.io/kasmin-org/kasmin:latest`) and
recreate the container; the database upgrade runs with `php artisan kasmin:upgrade` (or the upgrade hook).
