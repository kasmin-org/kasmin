# Changelog

All notable changes to Kasmin are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Kasmin follows
[Semantic Versioning](docs/development/versioning.md).

## [Unreleased]

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
