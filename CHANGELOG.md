# Changelog

All notable changes to Kasmin are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Kasmin follows
[Semantic Versioning](docs/development/versioning.md).

## [Unreleased]

## [0.32.0] - 2026-11-28

### Added

- **Accounting** (double-entry): chart of accounts in the language of the installation, bookings with any number of lines (typed in, with receipt) that are reversed instead of changed, numbers over all years; **periods** as date ranges that can be changed while open (bookings move along) and are closed in order and reopened together with all later ones; **cost centres** (one over all years, active per period; "carries its result" or "is allocated") and **funds** (reserves, pots of the equity, optionally assigned to a bank or cash account) – both switchable in the global settings; **allocation keys** per period with fixed amounts (cut in proportion when the costs are smaller), percentages, rest and share of income; **closing** with a preview that allocates, carries the results into the funds and locks the period; bank and cash accounts with a check against the statement; a **budget** per period (planned result per cost centre); reports: balance sheet, income statement, cost centres with budget and difference, statement of funds, ledger and open items, as Excel, CSV or PDF. See the new documentation page *Accounting*.
- **REST API and MCP for the accounting:** chart of accounts, cost centres (kind, active per period, standard, deleting with `confirm_count`), funds with transfers, periods (dates with a dry run, allocation keys, budgets, closing preview, closing, reopening with `confirm`), bookings (list, book, reverse) and reports as JSON or CSV, XLSX and PDF under `/api/v1/accounting`, scopes `finance:read` / `finance:write`; references by id or exact name; the switches *Use cost centres* and *Use funds* in `PATCH /settings`. 31 new MCP tools such as `list_accounts`, `create_booking`, `close_period_preview`, `reopen_period` and `get_report`.
- Accounting: **invoices, credit notes, payments, credits and refunds are booked by themselves** from the first period on – the receivable of the payer and the income per line with the amount before the discount, the discount on *Discounts given*, on the cost centre of the instalment, the membership type, the event or its series; payments to *Bank* or *Cash* (or the bank account of the import), overpayments to *Credits of families*, a credit note as the mirror of its invoice. Every document is booked once; a failed booking never stops the invoice or payment and is shown as a system notification. `php artisan kasmin:book-documents` books what is missing. Events, event series and membership types get an income account and a cost centre in the forms, REST and MCP (`cost_center`, `revenue_account`, id or exact name). Payments of the bank import are booked when they are assigned. Missing ones are booked when the first period is created or starts earlier, with *Book missing documents* (also REST and MCP) and every night; what was open before the start goes into an opening booking with a person on each line.
- **Expense claims and advances.** Team members submit their expenses in the portal (*Expenses and advances*: photo or PDF of the receipt from the phone, event, category, or a flat rate from the settings) and keep their IBAN for refunds there, stored encrypted and shown only masked; the treasurer can also enter a claim for a person. *Accounting → Expense claims*: approve (expense on the account and cost centre of the event, owed to the person), reject with a reason, pay out one or many from a bank account with a **SEPA file** (pain.001.001.09, one transfer per claim or per person) or a payment code, or in cash, and book a waived refund as a donation; optional approval by a second person. *Accounting → Advances*: hand out in cash or by transfer, settle with the receipts submitted against it, the rest comes back or becomes a claim. Mails at submission (notification *Expense claims*), approval, rejection and payout. REST `/expense-claims`, `/advances` and MCP tools (without the SEPA file). See the new documentation page *Expense claims and advances*.

## [0.31.1] - 2026-11-28

### Security

- **Login:** five wrong codes made the link in the same login mail unusable as well, so anyone who knew an address could
  lock the person out. Wrong codes are still counted per person (the code entry closes for 30 minutes); the link of the
  mail keeps working.
- **Sign-up switched off means switched off:** a pending sign-up is confirmed only while its form is switched on, and in
  the portal someone without a family of their own (for example after a “stay informed” form) can add a child – which
  creates a family – only while a sign-up form is switched on.

### Fixed

- The **sign-up form** of an existing German installation was created in English when the language was set only in the
  configuration (`APP_LOCALE`). The update now uses that language, and a form that is still unchanged is translated.
- **Invoices issued before 0.31** keep the discount column also when they have no discount (issued invoices never change
  their look).
- The **portal copy of a mail** showed the placeholder of the link date instead of leaving the sentence out.
- **Public forms:** the address preview says when the address still forwards to another form (it then opens the new one).
- **REST and MCP:** a number is looked for as a name when no record has that id (e.g. a membership type named `2027`); a
  text that is the name of one record and the old key of another is refused instead of silently taking the name.

## [0.31.0] - 2026-11-28

### Changed

- **Navigation in five areas.** The admin interface has the areas *People*, *Events*, *Communication*, *Accounting* and *Administration* at the top next to the logo; the navigation on the left shows only the pages of the open area, the logo or *Dashboard* opens the start page, and *My notifications* moved to the user menu. Areas without any page a person may open are not shown. The settings are split into *Organisation* and *Global settings* (Administration, the latter for administrators only) and *Invoice settings* (Accounting, also for finance roles); *System status*, *Backups* and *Update* are separate pages.
- **Signing up only through a public form.** The sign-up of family accounts is now a public form of the new kind *Sign-up (family account)* with opening and closing times, text, group and newsletter question. **Breaking:** it is **switched off** on every installation, also after this update – until an administrator switches it on, nobody can sign up; a system notification says so after the update. `/register` forwards to the form while it is on and open; the start page shows *Sign up for the first time* only then. Public forms can also be shown on the start page (*Show on the start page*). The public API accepts the sign-up form as well.
- **Links in mails about an event** stay valid until the event has ended, if the person or one of their children is registered, on the waitlist or has an open offer – never shorter than 30 days, never longer than a year; other mails keep 30 days. A cancellation, a cancelled event or the end of the guardianship shortens such a link at once. Every mail with a personal link says until when it is valid. New: *Make all links in older e-mails invalid* in the portal (*My data*) and *Make links in mails invalid* on the person page.
- Invoices show every **discount as its own position** below the line it belongs to (e.g. *Sibling discount (2nd child) −25.00*), with the full amount in the line above. REST and MCP give each line `gross`, `discount`, `discount_kind` and `discount_label`. Invoices issued before keep their look.
- Public forms: below the field *Address* the **full web address** appears while typing, with a button to copy it. The address is suggested from the title and saved normalised – lower case, umlauts spelled out (ä → ae, ß → ss), other accents removed, only a–z, 0–9 and hyphens. Existing addresses are normalised by the update; an earlier or differently written address forwards to the form.
- The **login code** has six characters in [Base32H](https://base32h.github.io) instead of six digits, shown as `K7M-2QX`: about a billion possibilities instead of 900,000. Typing is forgiving (lower case, with or without hyphen, O for 0, I for 1, S for 5, U for V). Codes in mails sent before the update keep working until they expire.
- **No more "Key" fields.** Membership types, roles, forms and their fields, mail templates, automations and the instalments of a payment plan are shown and edited with their **name** only; the field *Key* is gone from the admin interface. Names are **unique** per type (instalments: per event), with a plain message when a name is taken. Records refer to each other by their database id; the records the software itself knows (the role administrator, standard templates, forms and automations) keep an internal key that is never shown. A new record gets its internal key from its name, once; a form field keeps it when its label changes, so answers and placeholders keep working. Membership types are labelled *Name* instead of *Key* and *Label*.
- Invoices refer to the instalment of the payment plan by its id (`installment_id`); the update fills it in for existing invoices. What issued invoices say does not change.
- The automation **invoice run** chooses its instalment by the **position in the payment plan** of the event it runs for (*1st*, *2nd*, *3rd* or *last instalment*) instead of a typed key. A key stored by an older version keeps working and is offered as *Previous setting*. New installations: *Deposit invoices* use the 1st, *Balance invoices* the last instalment. The safety sheet reminders choose their form from a list.
- *Insert placeholder* in the template editor lists the **answers to the questions of the events** by their label (*Answer: …*); before, the placeholders `registration.answers.…` had to be typed and were refused when saving. The person import accepts the **name** of a membership type in `membership_type`.
- Form files (*Export*, `GET|PUT /forms/{form}/definition`) keep the internal keys, so a form still fits another installation; an import without a matching key updates the form with the same name.
- **Breaking (API):** membership types, roles, forms, mail templates and automations are addressed by their **id or exact name** (`/membership-types/{type}`, `/forms/{form}`, `/mail-templates/{template}`, `/automations/{automation}`, `type` of a membership, `role`, `template` of a mailing, `registration_form`, `membership_type`, templates, form and `approver_roles` of an automation); a name that several records have is refused with 422. Old keys are still accepted. Answers give `id` and `name` and no longer `key` (except form definitions and the fields of `GET /forms`, whose `values` refer to the field key). Instalments of `GET|PATCH /events/{id}` have `id` instead of `key` and are matched by `id` or by their exact `label`; `membership_type`, `registration_form` and the mail templates and form in automation settings are ids; `approver_roles` are role ids; invoices give `installment` as the id of the instalment plus `installment_name` (also in webhooks); automation runs and the event schedule give the automation id plus `automation_name` (runs); mailings give `template` as id plus `template_name`; `POST /membership-types` no longer takes `key`. MCP tools take `automation`, `template`, `type`, `form` and `role` as id or name instead of `key`.

### Security

- **Guessing a login code with many requested mails is no longer easier.** Wrong codes were counted only on the newest of all open codes of a person, while a typed code was compared with all of them, and login mails were limited only per IP address. Now wrong codes are counted per person (five within 30 minutes close the code entry for 30 minutes; the link in the mail keeps working), a new code ends the codes of older mails (their links keep working), and an address gets at most three login or sign-up mails within 15 minutes, whatever the IP address.

### Removed

- Event series no longer have a **type** (camp, alumni, meeting, other). It was stored and shown but never used; what kind of event it is comes from the event template. **Breaking (API):** `type` is gone from `GET|POST|PATCH /event-series` and the MCP tools `create_event_series`, `update_event_series`, `list_event_series`.

### Fixed

- The confirmation of participation subtracted a sibling or role discount a second time from the amount paid.
- Public forms accepted an address with umlauts (e.g. `anmeldung-für`), whose page then answered "404 Not Found".
- Creating a series from the event form labelled its name "Title"; the series form showed the untranslated field "type".

## [0.30.0] - 2026-11-27

### Added

- Statistics: **Export** of the age groups for activities – choose the fields per person (group, name, age at the start, date of birth, gender, role) and download as Excel, CSV or PDF. Needs the right to see people; every export is logged. Also via REST (`GET /statistics/age-groups`) and MCP (`get_age_group_members`).
- Mail templates belong to one of five **groups** – General, Event and forms, Registration and waitlist, Invoice and payment, Membership and groups. The group decides which placeholders a template has: the list of templates is arranged by group, *Insert placeholder* and *Insert condition* offer only the placeholders of the group, saving refuses a placeholder of another group and names it, and the preview fills only what such a mail has. The group of a standard template is fixed. REST and MCP show the group.
- Statistics: a question mark next to "returning" explains the word on hover (on a phone: on tap): at an earlier event of the same series, in any year.

### Fixed

- Demo deployment: the upload fallback left files behind that a newer version no longer has, so the removed page "Sender profiles" stayed visible on the demos. The program folders are now made equal to the release (storage, `.env` and uploads are never touched). Installations that update themselves were not affected.
- Help links of a new version led to "404 Not Found" until the documentation website had been rebuilt. The website now notices a new release itself and, until its documentation is built, shows the latest one; the release job checks the help links.

### Changed

- Mailings offer only templates they can fill: the group *General*, with an event as recipients also *Event and forms*; never mails of the system.
- System status: the database line says "Installed Kasmin version … · Kasmin database level …", so it cannot be mistaken for the version of the database server.

## [0.29.0] - 2026-11-27

### Added

- Update: if several newer versions exist, the version to update to can be chosen (any newer one; versions in between need not be installed first).
- Update: everything that changes between the installed and the chosen version is shown on the page, taken from the changelog.
- `POST /system/hooks/update` lets an installation update itself from a deployment pipeline (latest or a named version) and names the address that finishes the update.

### Fixed

- Update: with PHP's memory limit of 128 MB the update ran out of memory (the package was held in memory and the list of all program files was built at once). The package is now written to disk while it arrives and the files are saved one by one; tested at 128 MB.

### Changed

- The demo instances update themselves: the release pipeline asks them to, finishes the update and checks that the release runs. The upload via FTPS remains as fallback (empty or old instance, or on demand). Their automatic update is set to one day after a release.

## [0.28.0] - 2026-11-26

### Changed

- **One language per installation.** The language (German or English) is chosen at the installation and changed only by administrators (*Settings → Organisation and invoices → System → Language*). The language switch on the pages, the language of a person or family and the choice at the sign-up are gone; interface, portal, mails and documents use the language of the installation.
- **Texts are entered once.** Forms, fields and choices, public forms, membership types, roles, automations, labels of instalments and cancellation conditions have one text instead of one per language. The upgrade keeps the text in the language of the installation (the other one if that was empty). Changing the language later leaves everything that was entered as it is.
- **A mail template has one text.** The upgrade keeps the text in the language of the installation and stores the other one in the version history of the template.
- **One mail account with one sender.** *Communication → E-mail sending* replaces the lists of mail accounts and sender profiles: one page with the sender (name, address, reply-to) and the account. Templates and mailings no longer choose a sender. An installation that had several keeps using its standard account and the first sender of it.
- Installer: the standard content is installed in the chosen language (or not at all); there is no separate choice of its language.
- REST API and MCP: names, labels and texts are plain strings (objects with `de` and `en` are still accepted, the text in the language of the installation is taken); `locale` of people and families is gone; a mail template has `subject`, `html`, `text` instead of `contents` per language; `/sender-profiles` returns the one sender and refuses a second one.

### Added

- Mail templates: **Insert placeholder** – every placeholder with a name and an example, inserted at the cursor (also for the subject and the HTML source).
- Mail templates: **Insert condition** – an "if" built from a field, a comparison, a value and the texts for "then" and "otherwise", written in the right syntax.
- Mail templates: mistakes are named in plain words and all at once (placeholder not closed, bracket without partner, `if` without `endif`, unknown placeholder); saving stays blocked until they are corrected. The plain text part is checked, too.
- Mail templates: the preview offers the people in a list right away; typing narrows it down.

## [0.27.0] - 2026-11-26

### Added

- System status: *Show every item* lists each PHP extension, function and folder with what it is needed for and whether it is there; the documentation has the same list. The database line names product and version of the server.
- System status: check of the memory PHP may use. Kasmin needs 128 MB; only large PDFs ask for 256 MB for their one request.
- System status: *PHP configuration (phpinfo)* for administrators, without the keys and passwords of the installation; every view is logged.
- An optional note per gender below the choice of the gender of a child (*Settings → Organisation and invoices → Families*), with a standard text for "diverse" about the rooms.
- The documentation for administrators is available in German (`docs/de/admin`), page by page like the English one; a test keeps the two in step.

### Changed

- Legal guardians must choose the gender of a child they add (female, male, diverse); "not specified" is no longer offered there.
- The mail that confirms a new e-mail address and the page it opens say which address is replaced by which.
- Help links open the documentation in the language of the interface.
- Who works in the administration is led from the start page to the admin interface; the portal no longer tells them that no family is linked.
- The set-up also checks the extensions `dom`, `xmlreader`, `iconv`, `zlib`, `tokenizer`, `xml` and `ctype`.

### Fixed

- Docker image: the PHP extension `xmlreader` was missing, so importing an Excel file failed there. The new check of the requirements found it.
- Errors (HTTP 500) while the files of a new version were being put in place: visitors now see "Kasmin is being updated" until the files are complete, during the update in the browser and during a deployment.

## [0.26.1] - 2026-11-26

### Fixed

- The start page still showed the text of the very first version and no way to log in. It now shows the organisation, the buttons to log in and to sign up, and the public events.

## [0.26.0] - 2026-11-26

### Changed

- Approvers get one e-mail with all approvals that came in since the last summary, at a time that can be set (default 07:00, several times possible), instead of one e-mail per request. Leave the time empty for the old behaviour.

### Security

- Symfony components updated to 7.4.24 (six advisories of 25 November 2026, among them two about mail delivery where an attacker in the network could get around the required encryption).

## [0.25.0] - 2026-11-26

### Added

- A backup can be restored in the browser (administrators, MySQL and MariaDB): from the list or from an uploaded, also encrypted, file. The state before is saved first. No phpMyAdmin or shell is needed any more.
- REST API: everything the admin interface can do is now also available via REST (requirement X01) – families (create, change, members, invoice recipient), memberships of a person, duplicates (list, merge, dismiss), roles of a person, event series, bus of an event (`bus_booked`, `bus_seats`, `bus`), offers out of turn and reserve places, the schedule of an event, groups, rooms and duties of an event, the lists of an event as JSON, issuing, sending and cancelling invoices, payments and refunds, the invoice archive, versions of mail templates, the mail log, addresses that do not work, sender profiles, public forms and their submissions, consents, deletion requests, the data export and anonymising, statistics and the dashboard, own automations (create, delete) and webhooks. `docs/admin/api.md` describes every group and ends with a table of what is available in the interface, via REST and via MCP.
- REST API: the whole event form – sibling discount, cancellation fee and text, reminder days, prices by age or date and the payment plan in `POST`/`PATCH /events`, the registration form of a role (`registration_form`), registration rounds (`/events/{id}/rounds`), costs (`/events/{id}/costs`), documents with upload and download (`/events/{id}/documents`), event templates (`/event-templates`) and events from a template or as a copy (`POST /events` with `template` or `copy_of`).
- REST API: new scopes `roles:write` (give and take roles; a token can never give or take the role administrator) and `privacy:write` (anonymise people).
- REST API: the confidential lists of an event (kitchen, medical, emergency …) need `health:read`, and every read is recorded in the audit log, as in the admin interface.
- MCP: everything the REST API offers for administrative use is now also an MCP tool (requirement AP01) – 87 new tools (139 in total) for families, memberships, roles, duplicates, changing people and groups, event series and templates, the whole event form (bus, prices, payment plan), registration rounds, costs, documents (list, delete), participation status, offers and reserve places, groups and rooms of an event, lists, statistics and dashboard, issuing, sending and cancelling invoices, payments, refunds, the invoice archive, own automations, single runs, family suggestions for imports, form answers, public forms and their submissions, mail template changes and versions, the mail log, bad addresses, sender profiles, consents, deletion requests, data export, anonymising, settings and webhooks. A test walks the routes of the API and fails when a route has neither a tool nor a documented reason (files and webhook secrets are deliberately not available via MCP).
- MCP: tools that only read are marked read-only and tools that delete, cannot be undone or send mails are marked destructive; optional fields can be emptied by name with `clear`.
- MCP: more that an AI assistant cannot do without the scope `approvals:decide` – choosing the approval settings of a new automation, changing the setting from how many recipients a mailing needs an approval, and giving or taking roles (the role administrator is never available). Via MCP a webhook cannot be created and its address and secret cannot be changed, and a data export that holds emergency contacts needs the setting "All data" like other health and safety data.

### Changed

- REST API: `PATCH /participations/{id}` with the status `cancelled` takes a `reason`, recalculates draft invoices as the admin interface does, and refuses participations that are already cancelled, declined, attended or a no-show.
- REST API: families (`GET /households`) also show `locale` and `invoice_recipient`.

### Fixed

- The check for invoice corrections worked out the family of every registration again for every invoice (4.4 s with two years of data, now 0.7 s).
- Every invoice PDF was about 880 KB because the whole font was embedded, so every invoice mail had 1.2 MB; PDFs carry only the characters they use now (about 25 KB). Found by really sending the mails of two replayed camp years.
- The mail *Event information* showed "Place:" and "Meeting point:" without a value; several children were listed as "Ann, Ben" instead of "Ann and Ben"; the sign-up mail addressed adults by the first name only.
- People who take part for free (e.g. the team) got an invoice for the fixed deposit, and a family with only free places got an invoice over 0. Found by replaying two real camp years.

## [0.24.0] - 2026-11-25

### Added

- Invoice archive: invoices from the system used before are imported from a table and a ZIP with the PDFs and kept read-only; the own numbering continues after the highest imported number.
- Groups, rooms and duties within an event: divisions with any number of groups or rooms, a proposal that spreads the people (equal sizes, ages and genders mixed or genders separated, siblings apart or together, junior counsellors spread, beds of a room respected), changes by hand, notes on what to check, and a list to print.
- Demo data has genders, a team that takes part, a booked bus that is a little too small, and groups and rooms.
- MCP tools for what the REST API already offered: contact data of a person (`list_contact_points`, `add_contact_point`, `remove_contact_point`), legal guardians (`list_guardians`, `add_guardian`, `end_guardianship`), membership types (`list_membership_types`, `create_membership_type`, `update_membership_type`), automations (`get_automation`, `update_automation`), events (`create_event`, `update_event`) and travel by bus or car (`set_participation_travel`).
- REST API: automations have `approver_roles_default` – `true` while no approvers are stored and `approver_roles` shows the default roles.
- Development: the CI scans the running application with OWASP ZAP (public pages, and admin interface and portal as administrator); findings of risk medium or higher fail the run unless `tests/zap/rules.tsv` lists them with a reason.

### Changed

- Travel by bus: children can be set to travel by car too, for legal guardians who bring them themselves. The list *Travel by car* names who brings a child, with phone.
- An AI assistant cannot switch an approval off: via MCP, changing the mode, the approvers, the number of approvals or the limit of an automation needs the scope `approvals:decide` in addition to `automations:write`; so does changing or switching on an automation that runs without approval.
- REST API: `GET /events` and `GET /automation-runs` come in pages like people, families and invoices (`page`, `per_page`, answer with `meta`). Before, they returned up to 200 events and 100 runs at once; now 50 per page unless `per_page` (at most 200) is sent. The MCP tools `list_events` and `list_approvals` take `page` and `per_page`.
- REST API: `POST /groups/{id}/members` answers `201` when the person was added and `200` when it already was a member (before: always `200`).

### Security

- Pages only load scripts, styles, images and fonts from Kasmin itself and only send forms to it (content security policy); features of the device such as camera and location are switched off, pages are not kept in the cache of the browser, and the PHP version is no longer sent.
- The admin interface no longer asks another website (ui-avatars.com) for the picture with the user's initials, and the health page `/up` no longer loads a script and fonts from other servers.

## [0.23.0] - 2026-11-25

### Added

- Setting for how long camp leaders and counsellors keep access to their own events after the end (default 180 days).
- Travel by bus: an event can have a booked bus with a number of seats. Participants and dashboard show how full it is and warn when it is overbooked; adults can be set to travel by car (children always go by bus); lists *Bus list* and *Travel by car*.
- Backups in the browser: back up now, download (also encrypted), and open an encrypted backup again – no shell needed.
- Backup outside the server: every night an encrypted copy goes to a storage folder by WebDAV (https); the system status shows the result.

### Changed

- **Automatic updates are switched on by default** (not in Docker): a new version is installed 10 days after its release, with a notice two days before. Installations that never saved this setting follow the new default; switch it off under *Settings → Organisation and invoices*.

### Fixed

- After a version without changes to the database, the system status kept showing the old database version.

## [0.22.0] - 2026-11-25

### Added

- Families get a confirmation when a registration is cancelled (by themselves, the team, or because an invoice was not paid) and when an offer of a place ran out.
- *Start season* keeps the children on the waitlist of the last event on the waitlist of the new one, with their date.
- Confirmation of participation as a PDF after the event, for the team and in the portal, with the amount paid.
- List *Figures for the team meeting* (roles by gender, new and returning, waitlist); the participants list shows the gender.
- Legal guardians can state the gender when they add a child in the portal.
- Search in *Organisation and invoices*: shows only the settings that match.
- Actions on selected rows: add people to a group or remove them, write to the selection (mailing as a draft), change the status of several registrations.
- Person page with an overview (legal guardians, children, families, roles, groups, memberships, events, invoices, consents, login); family page with invoice recipient, emergency contacts, invoices and mails – each part only with the right for it.
- Dashboard: forms to review, possible duplicates and addresses that do not work in the to-dos; safety sheets changed since the last event.
- *My data* in the portal lists the devices that are logged in, with *Log out everywhere else*.
- Audit log: IP address and browser are shortened after 30 days and entries are deleted after 10 years; both can be set.
- *Help for this page* next to the title of every admin page opens the documentation of the installed version.
- A whole camp year is tested in one run, from the registration to the next season.
- REST API: contact data of a person (`/people/{id}/contact-points`) can be read, added and removed.
- REST API: legal guardians of a person (`/people/{id}/guardians`) can be read, added and ended.
- REST API: membership types (`/membership-types`) can be read, created and changed.
- REST API: automations can be read one by one and changed (`PATCH /automations/{key}`) with the new scope `automations:write`.
- REST API: events can be created and changed (`POST /events`, `PATCH /events/{id}`) with their basic fields and roles.

### Fixed

- The timeline of a person linked sent mails to a page that does not exist; the link opens the mail now.
- The address of a login could be removed in the person form of the admin interface, which locked the person out.
- The proposed SPF entry named `mx` twice when no mail server is set.
- The token form showed a raw key for the scope `health:write`; the API accepted names only up to 100 characters (120 as in the interface).
- Resetting a demo instance failed on the first call when the cache is stored in the database.
- The dashboard and the import list showed links to pages that the role may not open.
- A mail with a long link was wider than the screen in the portal on phones.
- After a mail was written, dates in the same run were shown in the American order (month first) in English.
- When a place was released because an invoice was not paid, the unpaid invoice stayed open for good; it is cancelled with a credit note now if nobody on it takes part any more.
- *Start season* proposed children that are too old again with every run, and did not tell families whose waitlist entry was carried over.

## [0.21.0] - 2026-11-25

### Added

- Roles that count only for the own events: camp leaders see their events and their people, counsellors have lists of their events in the portal. This also applies to existing installations.
- *My notifications*: choose topics immediately or as a digest, and the latest notifications. Until now there was no page for it, so nobody could get notifications. Administrators get deletion requests immediately and errors of automations daily by default.
- Automatic updates (off by default): a version is installed in the night once it has been out for the configured days (default 10, 04:00), with a notice two days before, a notice if a newer version resets the waiting time, and a mail with the changes afterwards.

### Fixed

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

[Unreleased]: https://github.com/kasmin-org/kasmin/compare/v0.32.0...develop
[0.32.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.32.0
[0.31.1]: https://github.com/kasmin-org/kasmin/releases/tag/v0.31.1
[0.31.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.31.0
[0.30.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.30.0
[0.29.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.29.0
[0.28.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.28.0
[0.27.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.27.0
[0.26.1]: https://github.com/kasmin-org/kasmin/releases/tag/v0.26.1
[0.26.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.26.0
[0.25.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.25.0
[0.24.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.24.0
[0.23.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.23.0
[0.22.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.22.0
[0.21.0]: https://github.com/kasmin-org/kasmin/releases/tag/v0.21.0
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
