<p align="center"><img src="resources/brand/kasmin-logo-horizontal.svg" alt="Kasmin" height="72"></p>

# Kasmin

[![CI](https://github.com/kasmin-org/kasmin/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/kasmin-org/kasmin/actions/workflows/ci.yml?query=branch%3Adevelop)
[![Coverage](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/kasmin-org/kasmin/badges/coverage-develop.json)](https://github.com/kasmin-org/kasmin/actions/workflows/ci.yml?query=branch%3Adevelop)
[![Security scan](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/kasmin-org/kasmin/badges/security-develop.json)](https://github.com/kasmin-org/kasmin/actions/workflows/ci.yml?query=branch%3Adevelop)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](docs/development/versioning.md)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Licence: AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue)](LICENSE)
[![Docker image](https://img.shields.io/badge/docker-ghcr.io%2Fkasmin--org%2Fkasmin-2496ED?logo=docker&logoColor=white)](https://github.com/kasmin-org/kasmin/pkgs/container/kasmin)
[![Built with AI](https://img.shields.io/badge/built%20with-AI%20(Claude)-F5A524)](#built-with-ai--on-purpose)

**Kids' Summer camp Admin** – open-source management for children's summer camps: families, registrations,
waitlists, safety sheets, invoices and mailings, with a strong focus on automation.

> [!WARNING]
> **Kasmin is under development and should not be used yet.** A first version that is suitable for tests and for real
> use is planned for **January 2027** at the latest. This README will then say explicitly from which version on that is
> the case. Until then, every version may change or drop data structures without a way to upgrade. Version 1.0 will
> follow after a full year of real use and thorough testing.

## What Kasmin is meant to do

- **Family accounts:** legal guardians manage their children and their data themselves; siblings belong to one family.
- **Login without passwords:** sign-in with the e-mail address and one-time login links.
- **Registrations and waitlist:** automatic waitlist positions, moving up when a place becomes free, reserve places.
- **Safety sheet:** emergency contacts, allergies, medication and consents, confirmed once a year instead of filled in again.
- **Invoices per family:** sibling discounts, deposit and balance, payment matching from bank exports.
- **Mailings:** templates, rate-limited sending via SMTP, optional copy in an IMAP mailbox.
- **Automation:** the camp year runs largely by itself – with previews and approval for anything important.
- **API first:** REST API (OpenAPI), webhooks and an MCP endpoint for AI assistants.
- **Audit log** for every change and every login.

## Technology

PHP 8.3–8.5 (only versions supported by php.net and by the dependencies), Laravel 13, Filament 5, Livewire 4,
MariaDB/MySQL. Runs on ordinary shared hosting (release ZIP + installer) or as a Docker image
(`ghcr.io/kasmin-org/kasmin`, one tag per release, branch and commit).

- Development setup: [docs/development/setup.md](docs/development/setup.md)
- Versioning: [docs/development/versioning.md](docs/development/versioning.md) (Semantic Versioning, always `MAJOR.MINOR.PATCH`)
- Contributing (German or English, AI-assisted contributions welcome): [CONTRIBUTING.md](CONTRIBUTING.md)
- Security: [SECURITY.md](SECURITY.md)

## Where the name comes from

Kasmin stands for **K**ids' **S**ummer camp **Admin**. The name comes from the
[Kindersommerlager Wiental](https://ks-wiental.at) ("KS Wiental"), a volunteer-run summer camp for children from
Vienna, Austria, which maintains Kasmin. KS is short for *Kindersommerlager*, German for children's summer camp.

## Built with AI – on purpose

Kasmin is developed with heavy use of AI. Most of the code, the tests and the documentation are written together
with Claude, an AI assistant by Anthropic; the features are planned, the changes reviewed and the releases decided
by a human. Commits made with AI help are marked as co-authored by Claude.

## Licence

[GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0).
