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
