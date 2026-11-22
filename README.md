# Kasmin

**Kids' Summer camp Admin** – open-source management for children's summer camps: families, registrations,
waitlists, safety sheets, invoices and mailings, with a strong focus on automation.

> **Status: specification phase.** There is no code yet. Planning and requirements are being worked out first.

## What Kasmin is meant to do

- **Family accounts:** parents manage their children and their data themselves, siblings belong to one family.
- **Login without passwords:** parents sign in with their e-mail address and one-time login links.
- **Registrations and waitlist:** automatic waitlist positions, moving up when a place becomes free.
- **Safety sheet:** emergency contacts, allergies, medication and consents, confirmed once a year instead of filled in again.
- **Invoices per family:** sibling discounts, deposit and balance, payment matching.
- **Mailings:** templates, rate-limited sending via SMTP, optional copy in an IMAP mailbox.
- **Automation:** the camp year runs largely by itself – with previews and approval for anything important.
- **API first:** everything available via REST API (OpenAPI), webhooks and an MCP endpoint for AI assistants.
- **Audit log** for every change and every login.

## Planned technology

- PHP (the three newest stable versions plus the oldest one with security support) and MariaDB/MySQL
- Laravel, runs on ordinary shared hosting (release ZIP + one-file installer) or as a Docker image
- Upgrade-only database migrations in PHP; the app refuses to run against a newer database
- Extensive automated tests, code coverage and security scans in GitHub Actions
- Documentation in `docs/`, translations in `lang/` (managed with Weblate)

## Where the name comes from

Kasmin stands for **K**ids' **S**ummer camp **Admin**. The name comes from the
[Kindersommerlager Wiental](https://ks-wiental.at) ("KS Wiental"), a volunteer-run summer camp for children from
Vienna, Austria. KS is short for *Kindersommerlager*, German for children's summer camp. Kasmin is built for this
camp first and is free and open source, so every other camp can use it as well.

## Built with AI – on purpose

Kasmin is developed with heavy use of AI. Most of the code, the tests and the documentation are written together
with Claude, an AI assistant by Anthropic; the features are planned, the changes reviewed and the releases decided
by a human. Commits made with AI help are marked as co-authored by Claude. More on [kasmin.org](https://kasmin.org).

## License

Kasmin will be licensed under the [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html) (AGPL-3.0).
