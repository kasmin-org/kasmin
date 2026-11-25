# Changelog

All notable changes to Kasmin are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Kasmin follows
[Semantic Versioning](docs/development/versioning.md).

## [Unreleased]

### Added

- Roles that count only for the own events: camp leaders see their events and their people, counsellors have lists of their events in the portal. This also applies to existing installations.
- *My notifications*: choose topics immediately or as a digest, and the latest notifications. Until now there was no page for it, so nobody could get notifications. Administrators get deletion requests immediately and errors of automations daily by default.

### Added

- Automatic updates (off by default): a version is installed in the night once it has been out for the configured days (default 10, 04:00), with a notice two days before, a notice if a newer version resets the waiting time, and a mail with the changes afterwards.

### Added

- Families get a confirmation when a registration is cancelled (by themselves, the team, or because an invoice was not paid) and when an offer of a place ran out.
- *Start season* keeps the children on the waitlist of the last event on the waitlist of the new one, with their date.
- Confirmation of participation as a PDF after the event, for the team and in the portal, with the amount paid.
- List *Figures for the team meeting* (roles by gender, new and returning, waitlist); the participants list shows the gender.

### Fixed

- The timeline of a person linked sent mails to a page that does not exist; the link opens the mail now.
- A mail with a long link was wider than the screen in the portal on phones.
- Installations that started with a very old version never got the standard mail templates, automations and event types of later versions, so mails such as the registration confirmation were not sent. What is missing is installed with this update.

### Changed

- Upgrades are done in the browser: after the files of a new version are installed, the login page stays reachable with a notice, administrators log in and start the upgrade with a button. No command line is needed. Until now, Kasmin only showed a page with a command.
- The admin interface was not stopped while the database did not match the installed version (also after an accidental downgrade); now every request is checked.
- The API answers with 503 while the database does not match the installed version.

### Security

- Notifications are only sent to people who have the rights for the topic at that moment.

## [0.20.0] - 2026-11-25

Pre-release for testing. Not ready for production use.

### Added

- Waitlist: order by rules per role (priority, took part last time, number of events, registration date), page with reasons, offers and manual actions, skipping with a reason.
- Release of reserve places, reminder to all administrators at a date, automatic release (off by default).
- Waitlists of groups with offers in the portal.
- Own automations: trigger (also weekly, monthly, on a date, changes of status, group or field), people, conditions and steps.
- Approvals by several people; the others are told who decided.
- Schedule of an event, all automation runs of the next 14 days on the dashboard.
- New season: copy an event and start the season with a preview of everything that will happen.

### Security

- Stored approval mails no longer contain the link that decides without a login; it is made when the mail is sent. Until now, people with access to the mail log could decide about automations in the name of an approver, e.g. about their own large mailing.

### Changed

- Standard texts, automations and event types of an update are installed after all changes of the database. Every change is now tested with an upgrade from 0.10.0 and from the last release (SQLite and MariaDB).

### Fixed

- Free places were no longer offered to the waitlist by the daily automation once the registration was closed.

## [0.19.0] - 2026-11-25

Pre-release for testing. Not ready for production use.

### Added

- Mails: logo, colour and footer; images (embedded); HTML source; buttons.
- Mail templates: preview with a real person in all languages for computer and phone, test mail to oneself, restoring versions, comparison with the shipped text and notice of a new one.
- Mailings: review mail by mail, progress, pause and continue.
- List of bad addresses; mailings leave them out. A permanent error is not tried again.
- Retention of the mail log (off by default).

### Fixed

- Mail templates could not be saved in the interface, and the editor would have damaged conditions and buttons.
- Editing a single mail of a mailing removed the layout and the footer links.

## [0.18.0] - 2026-11-25

Pre-release for testing. Not ready for production use.

### Added

- Forms: number and person fields, choices with "Other", ready-made lists (health insurance carriers), smallest and largest values, check digits of insurance numbers (Austria, Germany, Switzerland).
- Export and import of forms as JSON, also by API and MCP.
- Order of the legal guardians as emergency contacts.
- Events: safety sheet not older than … days before the start.
- Safety sheet: mails for the yearly confirmation and thank you, summary to oneself (off by default, without numbers), one-click reminder to selected families, webhook `form.confirmed`.

### Security

- Stored mails no longer contain personal login links; a link is made when the mail is sent. Until now, people with access to the mail log or to the review of a mailing (and to backups) could use the links of others for a limited portal session; links that allow a full login or the admin interface were never stored. Links in mails already stored are removed, and in the test mode mails contain no personal link.
- The preview of mail templates is shown in a sandboxed frame. Until now, someone with the right to edit mail templates could place a script that ran in the session of whoever opened the preview.
- Once there are answers, only people with access to the safety sheets can remove or rename confidential fields, make them less confidential, or change or delete the safety sheet. Until now, form rights were enough to make health data readable.

### Fixed

- Vaccination fields accept "nein" and "unbekannt" in German; until now only the English words were accepted.
- The form builder replaced the English texts of choices with the German ones when saving.
- The API token of a request was not forgotten after the request (no effect with ordinary PHP hosting).

## [0.17.0] - 2026-11-25

Pre-release for testing. Not ready for production use.

### Added

- *My data* in the portal: phone numbers, e-mail addresses (confirmed, old addresses are told), family address, invoice recipient.
- Copy of the own data as a file (portal, and for administrators on the person page).
- Start page of the portal: open tasks, status of the safety sheet, payment data with QR code. Child page: invoices and consents.
- Newsletter question on public forms (can be switched off); the wording of a consent is stored.
- Unsubscribe from single event series and groups. Notice when a child moves up on the waitlist (automation, off by default).
- Spam protection of public forms without third parties (proof of work, `KASMIN_PROOF_OF_WORK`).

### Changed

- Sign-up of legal guardians: nothing is created before the address is confirmed; unconfirmed sign-ups expire after 3 days.
- Own registrations of children: hidden, told without details, or visible.
- Public forms by the API need the proof of work, too.

### Fixed

- Event form: wider sections, the public link no longer runs under the image, headings translated. Automations: the buttons are in a menu in front of each row and no longer cut off. Profile: visible labels for the address fields. Prices no longer break before the currency sign.
- People who had never made a choice could not unsubscribe from the newsletter with the link in the mail.
- One-click unsubscribe of mail programs did not work.

## [0.16.0] - 2026-11-25

Pre-release for testing. Not ready for production use.

### Added

- REST API and MCP: groups, forms (confidential values only with `health:read` / `health:write`), mail templates, mailings (sending always
  needs an approval unless the token has `approvals:decide`), settings, and public events and forms without a token.
- Idempotency keys for API requests.
- Timeline of a person; login link from the person page; single invoices.
- Daily database backups with status on the system page.
- Mails go to the recipients set in the template (legal guardians, the person, or both).
- Groups show who joined and left in the last 30 days.
- Groups: members can be added and removed; groups by rules (age, membership, role, other groups, number of past events) and of the
  participants of an event; subgroups; ended memberships stay as history.
- Mail test mode: all mails, also login links, go to one address; shown in the admin interface.
- Attachments for mail templates and mailings, also documents of events. A mail never goes out without a promised file.
- Large mailings (default: more than 50 mails) are sent after an approval.

### Changed

- Registrations by the API count as made by the team (registration rounds).

### Fixed

- Backups could not be restored with the usual commands, because they did not switch off the foreign key checks (the data in them was
  complete). Older backups: run `SET FOREIGN_KEY_CHECKS=0;` first, see *Upgrading*. Backups are now read at one point in time.
- Mailings about an event no longer go to the legal guardians of people who registered themselves.
- People whose group membership has ended no longer get its mailings.
- Newsletter: the latest choice counts; people who subscribed again stayed excluded.
- Resending a mail, running an automation and rolling back an import need the permission to edit.

## [0.15.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Added

- Roles can be assigned to people with a period, and the permissions of every role can be edited (administrators only).
- Event templates (with three default types), copying an event with all dates moved, saving an event as a template.
- More event data, image and documents for participants or for everyone.
- Status by date: announce, open and close the registration, running, finished.
- Public event page; public pages and forms can be embedded in websites named in the settings.
- Registration rounds: returning participants, selection by number of events, waitlist, open. Places are accepted in the portal or
  by paying the first instalment; administrators can offer a place with a reason.

### Changed

- Every page of the admin interface checks the permissions of the role. Treasurers change prices and invoice data only, auditors
  only view finance, consents and the audit log; camp leaders no longer see finance, mailings or settings. Mail accounts,
  roles, API tokens, public address and AI settings are for administrators.
- There is always at least one administrator.
- Events become *running* and *finished* by themselves.

### Fixed

- An empty field for reserve places in an event no longer causes an error.

### Security

- Until 0.14.0 every administrative role, including auditors, could change people, mailings and mail accounts, and through them
  take over the login of an administrator. Pages are restricted now, and login addresses and roles of people with an
  administrative role can only be changed by administrators, whatever page or import is used.
- Pages can no longer be shown in frames of other websites (clickjacking); no addresses are passed on as referrer.

## [0.14.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Added

- Questions and consents per event and role, per participant or once per family; changeable until a date; on lists, in mails
  and in the list of participants. Confidential answers are encrypted and never in mails.
- Conditions (price, instalments, cancellation) are shown before registering.
- Public forms: register for an event, apply for the team (with approval), stay informed – with confirmation by e-mail.
- Adults register themselves in the portal; guests with a personal invitation, own registration and own invoice.
- Legal guardians invite further legal guardians; own logins for children from a configurable age (safety sheet read-only).
- Duplicates: suggestions, merge with a choice per field, mark as different people.

### Changed

- What a child registers with its own login is private (can be changed in the settings); the person gets the mails and invoices.
- Guardianship ends at the legal age (setting, default 18); both sides are told.
- Legal guardians of a family become guardians of its children when the family is saved in the admin interface.

### Fixed

- Anonymising now also removes refund names and IBANs, and the reasons of reduced places and cancellations.

## [0.13.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Added

- Lists per event: participants, contacts, bus, birthdays, answers, missing safety sheets; for the safety role also
  emergency contacts, kitchen, medical needs, medication, insurance, swimmers, consents and changes since the last event –
  on screen, as PDF, XLSX and CSV.
- Camp folder: one confidential PDF page per child, for the whole event or one child.
- Export of tables as XLSX, CSV and PDF; saved views, also shared; filters with AND, OR and NOT and by age at a date.
- Time zone in the settings; new installations start with the time zone of their language.
- Language switch on public pages and in the portal.

### Changed

- The interface uses the language of the logged-in person; guests get the language of their browser.

### Fixed

- MySQL: the order of choices and sub-fields of form questions was lost (MariaDB was not affected). Forms that already exist on
  MySQL keep the changed order; save the choices again in the form builder to correct it.

## [0.12.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

### Added

- Prices by age and registration date, reduced and free places with reason (finance roles only).
- Cancellation rules per event: refundable until, cancellation fee, conditions shown in the portal before cancelling.
- Corrections: issued invoices that no longer fit are corrected with a credit note and a new invoice, after approval.
- Credits and refunds: overpayments and cancelled invoices become credits with a refund proposal (*Finance → Refunds*).
- One transfer for several invoices, automatically or by hand (*Split*).
- Yearly membership fees per membership type, also as an instalment of an event.
- After payment: confirm the place, change groups. Automation to release unpaid places.
- Optional reminder fees (off by default) and invoice numbers per year.

### Changed

- Issued invoices can no longer be changed.
- Payment reminders are an automation and need approval before they are sent; the daily command no longer sends them alone.
- Registrations are cancelled with a reason instead of changing the status.
- The paid invoice shows the date of the payment.

## [0.11.0] - 2026-11-24

Pre-release for testing. Not ready for production use.

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

[Unreleased]: https://github.com/kasmin-org/kasmin/compare/v0.20.0...develop
[0.20.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.20.0
[0.19.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.19.0
[0.18.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.18.0
[0.17.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.17.0
[0.16.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.16.0
[0.15.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.15.0
[0.14.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.14.0
[0.13.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.13.0
[0.12.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.12.0
[0.11.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.11.0
[0.10.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.10.0
[0.9.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.9.0
[0.8.1]: https://github.com/kasmin-org/kasmin/releases/tag/v0.8.1
[0.8.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.8.0
[0.7.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.7.0
[0.5.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.5.0
[0.4.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.4.0
