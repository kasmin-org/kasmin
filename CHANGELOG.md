# Changelog

All notable changes to Kasmin are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Kasmin follows
[Semantic Versioning](docs/development/versioning.md).

## [Unreleased]

### Added

- Docker: almost complete configuration by environment variables (also as files). They are written to the database at the
  set-up and ignored afterwards; variables that differ from the database are reported in the log.
- Docker: HTTPS with your own certificate, Let's Encrypt (automatic) or a self-signed certificate; port 80 redirects to 443.
- Docker: scheduler, automatic database upgrade with backup, health check and the command `kasmin` in the container.
- Weekly automatic check (tests, security advisories, image scan) with an issue if it fails.

### Changed

- Docker: port 80 redirects to HTTPS by default; behind a reverse proxy set `KASMIN_TLS=off`.
- Login mails use the default mail account of the database, if there is one.

### Security

- STARTTLS (port 587) no longer falls back to an unencrypted connection.

## [0.10.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Changed

- Login only with the link or code sent by e-mail, for all roles; the second factor is off (`KASMIN_SECOND_FACTOR=true`
  switches it on).
- Every login of a person with an administrative role is reported to all administrators by e-mail.

### Security

- Twig updated to at least 3.31.1: the previous version had several ways around the sandbox that protects mail templates
  (e.g. CVE-2026-133764, CVE-2026-133770, CVE-2026-133772).
- Login links in mails (valid for 30 days) were meant to be limited, but gave full access, including safety sheets, and
  created a permanent login. They now only open the start page, invoices and offered places; everything else needs a
  fresh link. All remembered logins and sessions are ended once during the upgrade. Affects all versions up to 0.9.0.
- Approval requests and other notifications are no longer shown among the mails in the portal.

## [0.9.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Fixed

- **The admin interface did not work in any release package or Docker image so far (0.4.0–0.8.1)**: the JavaScript,
  styles and fonts of Filament were missing. They are now included, and the build fails without them.

### Changed

- The recommended cron interval is every 5 minutes; daily and hourly jobs run at the first call after their time instead
  of only in the exact minute.
- English dates are written day first (the portal showed the due date in the ambiguous American order).
- Admin interface: dates in the format of the language, labels without English capitalisation, instalment names instead of
  keys, no internal requirement numbers.
- Own admin theme, so tables, headings and links in Kasmin's admin pages are styled; menu in a fixed order.

### Security

- API tokens stop working when their owner loses the administrator role (before, they stayed valid until they expired
  or were revoked; affects 0.8.0 and 0.8.1).
- Webhooks and the Webling connector no longer follow redirects.

### Added

- Dashboard: one block per event series with the next event (registrations, last three, waitlist, open and reserve
  places, invoices paid/open/overdue), to-dos, finance, safety sheets (counts only), next and failed automation runs.
- Global search for people (also by e-mail), families, events and invoices, keyboard shortcut Ctrl/Cmd+K.
- "View as legal guardian": read-only preview of the portal for administrators, logged.
- Statistics: age distribution, age groups of equal size for activities, costs and income per event with cost items,
  sibling and role discounts, participants per event (returning/new).
- Data protection: deletion requests with a preview of what is deleted and what must be kept, anonymisation (keeps
  statistics and invoices within the retention period), nightly retention run for health and identity data (default
  7 years after the last event), audit log viewer, consents overview.

## [0.8.1] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Fixed

- Updates by uploading a new release (FTP, demo deployment) kept Laravel's cached package list, so packages added by
  the release were not loaded: invoice PDFs (since 0.7.0), API documentation and MCP (since 0.8.0). The cached files
  are now rebuilt automatically whenever the release changes. The update in the admin interface was not affected.

## [0.8.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Added

- REST API v1 with API tokens (scopes, expiry, revoke, last use, shown once), OpenAPI 3.1 documentation at
  `/docs/api` generated from the code (also attached to every release), audit log with the token as actor.
- MCP endpoint `/mcp` for AI assistants on top of the REST API (same tokens and scopes; tools only for granted
  scopes); setting "AI processing allowed" (none / non-confidential / all) limits MCP and health data.
- Automation runs started via API/MCP wait for a human decision unless the token has `approvals:decide`.
- Webhooks: subscribed events signed with HMAC-SHA256, retries with backoff, delivery log, HTTPS only.
- Import of people and families: wizard (CSV/XLSX or Webling), REST API, MCP and command line; dry run, family
  builder (e-mail, phone, legal guardians; address only as suggestion), idempotent re-imports by source id, import
  batches with rollback, historical participations.
- Webling connector (members and member groups via the Webling API, configurable field assignment).
- Export and import of mail templates and automations as JSON (`kasmin:config:export`, `kasmin:config:import`).
- Documentation: API, MCP and webhooks; import; "Migrate with an AI assistant" with a privacy checklist.

### Changed

- Cron documentation: web hosts that do not allow "every minute" (e.g. all-inkl.com) work with every 5 minutes.

## [0.7.0] - 2026-11-23

Pre-release for testing. Not ready for production use. Contains the milestones 0.6 (invoices and payments) and
0.7 (automations); there is no separate 0.6.0 release.

### Added

- Invoices and payments (0.6): prices per role, payment plans with fixed and remaining instalments, sibling discounts
  (staggered with cap or all siblings, oldest pays full) and role discounts; invoice runs per family, numbering and
  RF creditor references, PDF with EPC QR code, invoice/reminder/payment confirmation templates (German and
  English), payments by hand or CSV/XLSX bank import with automatic matching and suggestions, reminders before and
  after the due date, credit notes, invoices in the portal; organisation and invoice settings page; finance demo data.
- `kasmin:login-link` prints a one-time login link (support, or before mail works).
- Automations (0.7): triggers (by button, daily, before/after events, when a place becomes free), modes automatic /
  approval required / manual, approval by e-mail with one-time links (decision only on the page, untick single
  actions, second-factor code), reminder and expiry of requests, approvals inbox and run history, dry run, safety
  limit; actions: waitlist offers with deadline (accept/decline in the portal), expiry of offers, invoice runs,
  mails to participants, safety sheet reminders with interval and maximum, start season, close event, age notice;
  standard automations (switched off) and the template "Place offered from the waitlist".

### Changed

- All login links except the long-lived links in mails can now be used only once.
### Fixed

- Rate limits are counted per purpose (login link request, code, link, approvals, sign-up …); before, all shared one
  counter per IP, so using links could block requesting a login link.
- SQLite: roles that start today were not active yet (date compared as text); role periods are now compared as dates.

### Changed (deployment)

- Demo deployment: concurrency per instance on the job level, so a skipped run cannot cancel a pending release
  deployment.

## [0.5.0] - 2026-11-23

Pre-release for testing. Not ready for production use.

### Added

- Mail: SMTP/IMAP accounts with rate limits and connection test, sender profiles, mail queue with retries, copy of
  sent mails via IMAP, replies and bounces read from IMAP and assigned to people.
- Mail templates in German and English with a formatted editor, sandboxed placeholders (no confidential fields),
  versions and preview; default templates (registration confirmation with waitlist position, safety sheet request
  and reminder, event information, newsletter).
- Mailings: recipients from groups and events, to legal guardians/the person/both, one mail per family, opt-outs,
  prepare → review/edit single mails → send; mail log with resend.
- Footer links in every mail: e-mail settings, unsubscribe, no more information, deletion request (signed links,
  action only after a button press).
- Admin notifications immediately or as daily/weekly digest; registration confirmation mails; mails in the portal.
- System status page for administrators: PHP support, extensions and write permissions, cron job, mail queue,
  database, Kasmin version, and a mail DNS check (SPF, DKIM, DMARC with suggested records; warning if DMARC is not
  `p=reject`).
- System notifications for administrators (shown after login, mailed once): cron job missing, lowest supported PHP
  version with end-of-support date, new Kasmin version available.
- Self-update in the admin interface for web hosting installations (requirement check, checksum, backups, rollback,
  database upgrade); disabled in Docker with a notice to update the image.
- Direct mail delivery to the recipients' mail servers with DKIM signing (key generated by Kasmin).

### Changed

- Docker image now based on **Alpine Linux 3.24** with its PHP 8.5 packages and Apache (345 MB instead of about 1 GB);
  all packages upgraded during the build, no package cache in the image; bash and vim included for maintenance.

## [0.4.0] - 2026-11-23

First public pre-release for testing. Not ready for production use.

### Added

- Laravel 13 application skeleton, licence (AGPL-3.0), contribution and security guidelines, versioning rules.
- Docker-based development environment (PHP 8.5, MariaDB 11, Mailpit), static analysis with Larastan (level 8).
- Kasmin logo, favicons and web manifest.
- Production Docker image (Apache + PHP 8.5) and CI pipeline: code style, static analysis, composer audit, tests on
  PHP 8.3–8.5 with MariaDB and MySQL (8.6 experimental), Semgrep, Trivy container scan, GUI tests with Playwright
  on phone and desktop, Docker images per branch, commit and release, coverage and security badges.
- Database version lock: Kasmin stops if the database is newer than the code; `kasmin:upgrade` (with PHP-only
  database backup and lock) and `kasmin:status`; backward migration commands disabled in production.
- Release packages: installable ZIP with checksum for every release tag and a rolling development build per branch.
- Installer: one-file bootstrap script (releases fetched live from GitHub, last 10 with date and changelog link,
  development mode per branch, SHA-256 verification, access code, writes `.env`), web installer (organisation,
  language, default content German/English/none, first administrator) and `kasmin:install`; tested in CI.
- Kasmin colours in the Tailwind theme; simple start page.
- People and families: persons with contact points (purposes login/information/invoices/emergency), legal
  guardianship, households with legal guardians and children, membership types and memberships, roles with
  periods; default roles and membership types (German/English) from the installer; the first administrator is a
  person with the administrator role.
- Passwordless login with one-time link (confirmation click, mail-scanner safe) and six-digit code, brute-force
  protection, same answer for unknown addresses; second factor (TOTP with QR code) for administrative roles.
- Append-only audit log for all changes (old/new values) and all login events (method, token ID).
- Admin interface (Filament 5) with people and families; portal start page for legal guardians with their children.
- Demo data generator `kasmin:demo` (German and English, fictitious, reproducible).
- Groups, event series and events with status, participant roles with places, reserve places, age limits (at event
  start or a reference date) and who registers; registrations binding immediately or as requests.
- Registration with waitlist (position and total length), cancellation, reserve places assigned by the team with a
  reason; participants board in the admin interface.
- Public sign-up for legal guardians (name, e-mail, privacy consent, honeypot) with login link; portal: add
  children, see matching open events, register or join the waitlist, cancel.
- Demo data now includes a camp series and an alumni series with an open camp and a waitlist.
- Form builder for person forms (12 field types, labels and help texts in German and English, confidentiality,
  carry-over, list use, versions); default safety sheet (until 16, yearly confirmation) and general information
  (T-shirt size, need for new T-shirts).
- Safety sheet in the portal: pre-filled, one-click confirmation of unchanged data, history of changed fields;
  family-wide emergency contacts after the legal guardians.
- Encrypted answers; confidential values never in the audit log; safety data in the admin interface only for the
  safety role and administrators, with logged views.
- HTTP hooks for hosting without a command line: upgrade after deployment, web cron, demo set-up (disabled without
  `KASMIN_HOOK_TOKEN`); `kasmin:demo --email-domain`.
- Deployment of the latest release to demo instances (German and English) via FTPS.

### Security

- Docker image: system packages updated and build packages removed after compiling the PHP extensions – the
  container scan reports no high or critical findings any more (previously 75 in Debian packages).

[Unreleased]: https://github.com/kasmin-org/kasmin/compare/v0.10.0...develop
[0.10.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.10.0
[0.9.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.9.0
[0.8.1]: https://github.com/kasmin-org/kasmin/releases/tag/v0.8.1
[0.8.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.8.0
[0.7.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.7.0
[0.5.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.5.0
[0.4.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.4.0
