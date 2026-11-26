# System status, notifications and updates

## Language

An installation works in **one language** – German or English – for everybody: admin interface, portal, public
pages, mails and documents. It is chosen at the installation; administrators change it under *Settings → Organisation
and invoices → System → Language*. There is no switch per person or per visit.

The language decides the texts of the software. Everything that was **entered** – forms and their fields, mail
templates, names of roles and membership types, automations, texts of events – exists once and is not translated:
it stays exactly as it is when the language is changed.

An installation of a version up to 0.27 stored such texts once per language. The upgrade keeps the text in the
language of the installation (the other one if that was empty). For mail templates the text of the other language
is kept in the version history of the template.

## Time zone

*Settings → Organisation and invoices → Time zone*: all times and daily jobs. Installations before 0.13 run in UTC until a
time zone is chosen. Times already stored are not converted, so links and offers that are open at that moment may expire up
to two hours earlier or later.

## System status

*System → System status* (administrators only) shows:

- PHP version and the date until which it receives security updates
- PHP extensions, functions and write permissions: *Show every item* lists each one with what it is needed for and
  whether it is there (the full list: [Hosting](hosting.md#what-the-server-must-offer))
- the memory PHP may use (128 MB are needed)
- whether the cron job runs (last run of the scheduler)
- old mails waiting in the queue
- database: product and version of the server (for example "MariaDB 11.4") and the schema version
- installed and latest Kasmin version
- **mail DNS records** of every sender domain: SPF, DKIM and DMARC, each with a concrete suggestion for the DNS
  record. DMARC should use `p=reject`; anything weaker is shown as a warning. With *direct delivery* (to the
  recipients' mail servers, without an SMTP relay) Kasmin signs every mail with DKIM – generate the key at the mail
  account and publish the record shown.

At the end of the page, *PHP configuration (phpinfo)* shows what the server offers – versions, extensions, limits. Hosters ask for it.
The keys and passwords of the installation are left out; every view is recorded in the log.

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

## Finding a setting

*Settings → Organisation and invoices* has a search field at the top: type a word (e.g. *IBAN* or *retention*) and only the matching
settings stay visible.

## Help

*Help for this page* under the title of every page of the admin interface opens the matching page of this documentation, in the
version that is installed and in the language of the interface.
