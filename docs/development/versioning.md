# Versioning

Kasmin follows [Semantic Versioning 2.0.0](https://semver.org): `MAJOR.MINOR.PATCH`.

- **MAJOR** – incompatible changes: removed or changed REST/MCP API behaviour, changed template language syntax,
  configuration that has to be migrated by hand, dropped upgrade path, raised minimum PHP version outside the
  normal support policy.
- **MINOR** – new features that are backwards compatible, including new database migrations (upgrades are
  automatic), new settings with defaults, new API endpoints, adding support for a new PHP version.
- **PATCH** – bug fixes and security fixes without new features.

Before 1.0.0 (`0.y.z`), MINOR releases may contain breaking changes; they are listed in the changelog.

## Releases

- Releases are tagged on `main` as `vMAJOR.MINOR.PATCH`. Every release has release notes taken from
  [CHANGELOG.md](../../CHANGELOG.md) ([Keep a Changelog](https://keepachangelog.com) format).
- Each tag produces a Docker image (`MAJOR.MINOR.PATCH`, `MAJOR.MINOR`, `MAJOR`, and `latest` for the newest release)
  and an installable ZIP with checksum.
- The documentation in `docs/` belongs to the release it was tagged with.

## Database

Migrations only go forward. The application refuses to run if the database schema is newer than the code, to
prevent accidental downgrades.

## Minimum PHP version

Kasmin supports the PHP versions that php.net still supports **and** that all dependencies support. The minimum
PHP version **may be raised in any MINOR release** when the code or its dependencies need it – also before php.net
ends the support of the older version (for example because the locked dependencies require it). Such a change is
always stated clearly:

- in the changelog as **"Breaking: requires PHP x.y or newer"**,
- in the release notes and the README,
- by the installer and the self-updater, which check the requirements of the target version before anything is
  changed and refuse the update with an explanation if the server does not meet them.

Administrators are warned in advance: when the installed PHP version is the lowest one still supported, the
system shows a notification with its end-of-support date.
