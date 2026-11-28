# REST API, MCP and webhooks

Everything an integration or an AI assistant needs goes through **API tokens**. A token acts on behalf of the
administrator who created it, limited to the **scopes** chosen for it. It stops working when that person loses the
administrator role.

## API tokens

*Administration → API tokens* (administrators): **Create token**, choose a name, the scopes and an expiry. The token
(`kas_…`) is shown **once** – copy it into the other system. Revoke tokens you no longer need; the list shows when
each token was last used. Every write via the API is recorded in the audit log with the token as actor.

| Scope | Allows |
|---|---|
| `people:read` / `people:write` | read / create and change people, families, contact data, legal guardians, memberships, groups and membership types; read roles; list / merge duplicates; read / decide public submissions |
| `events:read` / `events:write` | read events, participants, rounds, documents, templates, lists, groups and rooms, statistics and the dashboard / create and change events (incl. prices and payment plan), series, templates, rounds and documents, register, change participations, offer places, groups and rooms |
| `finance:read` / `finance:write` | read invoices (incl. PDF), payments, refunds, the invoice archive, expense claims, advances and the costs of an event; read the accounting (accounts, cost centres, funds, periods, bookings, reports) / issue, send and cancel invoices, record and assign payments, settle refunds, enter, approve, reject and pay out expense claims, hand out and settle advances, change the costs of an event; book and reverse, close and reopen periods, change the chart of accounts, cost centres, funds and allocation keys |
| `mail:read` / `mail:write` | read mail templates and their versions, mailings, the mail log, bad addresses and sender profiles / change and restore templates, create, prepare and release mailings |
| `automations:read` | read automations and runs, dry runs |
| `automations:write` | change automations: active, mode, trigger, settings, approvers. It can switch an approval off – give it only to tokens a person controls. Via MCP, changes to an approval also need `approvals:decide` |
| `automations:run` | start runs – **as approval requests** (the approvers decide) |
| `approvals:decide` | decide approvals (and start runs directly). Give it only to tokens a person controls |
| `import:write` | import people, roll imports back |
| `audit:read` | read the audit log, consents and deletion requests; with `people:read` export the data of a person |
| `health:read` | confidential values of forms, e.g. the safety sheet, and the confidential lists of an event (separate on purpose; every read is logged) |
| `health:write` | change confidential values; the legal guardians have to confirm them in the portal |
| `settings:write` | read and change settings – not bank data, public address, test mode, embedding, time zone and AI settings; change sender profiles; webhooks |
| `roles:write` | give and take roles – never the role administrator. Give it only to tokens a person controls |
| `privacy:write` | anonymise people (cannot be undone). Give it only to tokens a person controls |

Rate limit: 120 requests per minute per token.

## REST API v1

Base URL `https://your-kasmin.example/api/v1`, header `Authorization: Bearer kas_…`, JSON in and out.

- **Documentation** with every endpoint, parameter and response: `https://your-kasmin.example/docs/api`
  (OpenAPI 3.1 as JSON: `/docs/api.json`; every release also ships `openapi.json`).
- Everything the admin interface can do is also available here (see the [parity table](#parity-interface-rest-and-mcp)
  at the end). An endpoint calls the same function as the page, so mails, audit entries and rules are the same.
- Lists that can get long (`/people`, `/households`, `/events`, `/invoices`, `/payments`, `/refunds`,
  `/invoice-archive`, `/accounting/bookings`, `/automation-runs`, `/outgoing-mails`, `/public-submissions`, `/consents`) come in pages:
  `page` and `per_page` (default 50, at most 200) in the query, and `meta` with `total`, `page` and `last_page` in
  the answer. They are sorted newest first, people and families by name.
- Creating answers with `201`. Where the entry already existed (a person that already is a member of the group
  in `POST /groups/{id}/members` or of the family, or already is a legal guardian) the answer is `200` and nothing
  changes. A request the admin interface would refuse answers with `422` and a `message`; a missing scope with `403`.
- The API is for administrative use only: a token always acts for an administrator, and there is no API for
  families or other portal users.
- Ids of people, families and events are their public ids (ULIDs), not database numbers. Everything else
  (participations, invoices, payments, groups, divisions, units, membership types, roles, forms, mail templates,
  automations, instalments …) has a number – see [Ids and names](#ids-and-names).
- `GET /me` shows the token in use: name, scopes, expiry and the user it acts for.

### Ids and names

Membership types, roles, forms, mail templates, automations and the instalments of a payment plan have a **name**
that is unique per type (instalments: per event), and are referred to by their **database id**. Every list returns
`id` and `name` (instalments: `id` and `label`).

- Where such a record is addressed – in the path (`/membership-types/{type}`, `/forms/{form}`,
  `/mail-templates/{template}`, `/automations/{automation}`) or in a field (`type` of a membership, `role`,
  `template` of a mailing, `registration_form`, `membership_type`, the mail templates, the form and `approver_roles`
  of an automation) – send the **id** or the **exact name**. Upper and lower case and spaces at the start and end do
  not matter. A number is always taken as an id.
- A name that several records have is refused with `422` and the message that the name is not unique; use the id
  then. Nothing found: `404` in the path, `422` in a field.
- Up to 0.30 these records had a visible key (`deposit`, `safety_sheet` …). Such a key is still accepted where an id
  or name is, but it is no longer part of any answer, except in the files of form definitions (see below) and of
  `kasmin:config:export`, which go from one installation to another, where ids differ.
- Creating or renaming a record with a name that is taken is refused (`422`), as in the admin interface.
- Fields of a form keep their key: the answers of a person (`values`) and the placeholders of mail templates refer
  to it. It is made from the label when the field is added and does not change when the label changes. `GET /forms`
  lists the fields with `id`, `key` and `label`; `values` may name a field by its key or its id.

```
curl -H "Authorization: Bearer kas_…" https://your-kasmin.example/api/v1/events
```

**Dangerous actions need a human** (X05): starting an automation via the API (invoice runs, mailings …) creates an
approval request unless the token has `approvals:decide`.

### People

Scopes `people:read` / `people:write`.

- `GET|POST /people`, `GET|PATCH /people/{id}` – search (`search`, `group`, `updated_since`), read, create (the
  fields of one import row, recorded as an import that can be rolled back) and change name, date of birth, gender
  and language.
- Contact data: `GET|POST /people/{id}/contact-points` (`type` `email` or `phone`, `value`, `purposes`,
  `is_primary`), `DELETE /people/{id}/contact-points/{number}`. The address a person logs in with cannot be removed.
- Legal guardians: `GET|POST /people/{id}/guardians` (`guardian` = id of the person),
  `DELETE /people/{id}/guardians/{guardian id}` ends the guardianship. The setting *Legal guardians per family (at most)*
  also applies here; families are not changed.
- Memberships of a person: `GET|POST /people/{id}/memberships` (`type` = id or name of a membership type, `starts_on`,
  `ends_on`), `PATCH /people/{id}/memberships/{number}` with `ends_on` ends a membership (`null` lets it run
  again). A person has one running membership per type at most.
- Membership types: `GET|POST /membership-types`, `PATCH /membership-types/{type}` (`{type}` = id or name; `name`,
  unique, `pays_fee`, `fee`, `revenue_account` and `cost_center`: what the fee is booked on in the
  accounting, the id or the exact name; `null` empties).
- Duplicates: `GET /duplicates` lists the pairs that are probably the same person with the reason.
  `POST /duplicates/merge` (`keep`, `duplicate`, and `fields` such as `{"birth_date": "duplicate"}` for the values
  that should come from the duplicate) moves everything to the person to keep and deletes the duplicate – this
  cannot be undone. It is refused when the merge would give somebody more rights or both have an invoiced
  registration for the same event. `POST /duplicates/dismiss` (`first`, `second`) marks two people as different.
- Forms: `GET /forms`, `GET /people/{id}/forms`, `GET|PATCH /people/{id}/forms/{form}` (`{form}` = id or name).
  Confidential values need `health:read` / `health:write`. `GET|PUT /forms/{form}/definition` reads and replaces
  the definition of a form (`settings:write` to replace); `PUT` with a name that no form has creates a new form. The
  definition is the same file as *Export* in the admin interface: it holds the internal keys of the form and of its
  fields, so that it fits another installation. A field without `key` is a new field.

Example: `POST /people/{id}/memberships` with `{"type": "Regular member (team, board)", "starts_on": "2027-01-01"}`
(or the `id` from `GET /membership-types`).

### Families

Scopes `people:read` / `people:write`.

- `GET /households` (`search`), `GET /households/{id}` – with members and `invoice_recipient`.
- `POST /households` (`name`, `street`, `postal_code`, `city`, `country`) creates a family without
  members; `PATCH /households/{id}` changes it. `invoice_recipient` is the id of one of the legal guardians of the
  family (`null` = chosen automatically); somebody else is refused.
- `POST /households/{id}/members/{person}` with `role` `guardian` (legal guardian) or `child` adds a person or
  changes its role; `DELETE /households/{id}/members/{person}` takes it out. As in the admin interface, the legal
  guardians of a family become the legal guardians of its children; a guardianship that was ended stays ended, and
  taking somebody out of a family does not end a guardianship.

Example: `POST /households` with `{"name": "Family Ash", "street": "Oak Lane 1", "postal_code": "1010", "city": "Vienna", "country": "AT"}`.

### Roles

Reading with `people:read`, changing with the own scope `roles:write`.

- `GET /roles` – all roles; `admin` says whether the role may use the admin interface.
- `GET /people/{id}/roles` – the roles of a person with their period.
- `POST /people/{id}/roles` (`role` = id or name, `event` only for roles that count for the own events, `starts_on`,
  `ends_on`) and `DELETE /people/{id}/roles/{number}`.
- **A token can never give or take the role `administrator`** (403). In the admin interface only administrators
  may change roles; a token is not a person, so this role stays with a person there. The last administrator
  cannot be removed anywhere.
- A person who gets a role for the admin interface also gets a login for its primary e-mail address, as in the
  admin interface. Give `roles:write` only to tokens a person controls.

### Events and participants

Scopes `events:read` / `events:write`.

- `GET|POST /events`, `GET|PATCH /events/{id}` with the fields of the event form: basic data (title, dates,
  status, `series_id`, registration window, place, contact, `bus_booked`, `bus_seats`) and `roles` (`role`,
  `capacity`, `reserve_places`, `min_age`, `max_age`, `price`, `registration_form` = id or name of a form with questions …).
  A booked bus needs its number of seats. The answer has `bus` with `riders`, `cars`, `free`, `over`,
  `adults_on_bus` and `children_by_car`. The image is set in the admin interface.
- Prices and payment plan, in the same requests: `sibling_mode` (`none`, `staggered`, `all`), `sibling_order`
  (`oldest`, `highest_price`, `registration`), `sibling_amount`, `sibling_cap_position`, `sibling_min_children`,
  `cancellation_fee`, `cancellation_text.de` / `.en`, `reminder_days_before` (list of days), `prices` (prices by
  age or date: `role`, `min_age`, `max_age`, `from_date`, `until_date`, `price` – the list replaces the stored
  one) and `installments` (`id`, `label` = the name, unique within the event, `kind` = `fixed`, `rest` or `member`,
  `amount`, `due_date`, `due_days`, `membership_type` = id or name, `refundable`, `refund_until_days`,
  `confirms_place`, `paid_add_group`, `paid_remove_group`, `discounts_apply`, `revenue_account`, `cost_center`). Instalments go like roles: an entry
  with the `id` of an instalment changes it, an entry without `id` changes the instalment with the same name or
  creates a new one, and the instalments that are not listed are removed. As in the event form, prices can be changed while invoices exist: invoices
  that are issued stay as they are. An instalment that already has invoices cannot
  be removed (422). The currency is shown but cannot be changed, as in the form.
- Accounting: `cost_center` of the event and `revenue_account` / `cost_center` of an instalment say what its invoices are
  booked on (see [Invoices – Bookings](invoices.md)). Send the id or the exact name; `null` empties the field. The answer
  has the name and the id (`cost_center_id`, `revenue_account_id`). An unknown name is refused (422).
- Registration rounds: `GET|PUT /events/{id}/rounds`. `PUT` sends `rounds` (`id` for an existing round, `role`,
  `kind` = `returning`, `selection`, `waitlist` or `open`, `mode` = `accept` or `payment`, `opens_at`, `closes_at`,
  `min_events`, `group`) and replaces the list; rounds that are not listed are removed.
- Costs (`finance:read` / `finance:write`): `GET|POST /events/{id}/costs` (`label`, `category`, `amount`),
  `PATCH /costs/{number}`, `DELETE /costs/{number}`.
- Documents: `GET /events/{id}/documents`; `POST /events/{id}/documents` as `multipart/form-data` with `title`,
  `visibility` (`participants` or `public`) and `file` (PDF, JPEG or PNG, at most 10 MB, as in the form);
  `GET /event-documents/{number}/download` (`events:read`); `DELETE /event-documents/{number}`.
- Event templates: `GET /event-templates`; `POST /event-templates` with `event` and `title` saves an event as a
  template (or send the fields of an event to create one from scratch); `PATCH /event-templates/{id}`,
  `DELETE /event-templates/{id}`. `POST /events` with `template` (or `copy_of` = an event), `title` and
  `starts_at` creates an event as a copy with everything that is configured and all dates moved, as
  *From a template* and *Copy* do; people, invoices and costs are not copied.
- Event series: `GET|POST /event-series` (`name`, `cost_center` = id or exact name of the cost centre its events are
  booked on), `PATCH /event-series/{number}`.
- `GET /events/{id}/participations` (`status`, `role`), `POST /events/{id}/registrations` (`person`, `role`).
- `PATCH /participations/{number}` changes `status` or `travel` (`bus`, `car`). With `status` `cancelled` an
  optional `reason` is stored; the family is told, the place goes to the waitlist and draft invoices are
  recalculated. A participation that is cancelled, declined, attended or a no-show cannot be cancelled.
- Offer a place out of turn: `POST /events/{id}/offers` with `person`, `role`, `until` (date) and `reason`
  (required). The family gets the offer by mail and answers in the portal.
- Reserve places: `POST /participations/{number}/reserve-place` (`reason` required) gives a free reserve place to
  somebody on the waitlist; `POST /events/{id}/reserve-places/release` (`role`, `count`, `reason`) gives reserve
  places that are not needed to the waitlist.
- `GET /events/{id}/schedule` – the dates of the event, the automations planned before and after it and their runs.

Example: `POST /events/{id}/offers` with `{"person": "01J…", "role": "child", "until": "2027-05-31", "reason": "Sibling of a counsellor"}`.

### Groups, rooms and duties of an event

Scopes `events:read` / `events:write`.

- `GET /events/{id}/divisions` – the divisions with their units, the people in each unit, who is not placed yet
  (`not_placed`) and what to check by hand (`warnings`).
- `POST /events/{id}/divisions` with `kind` (`groups`, `rooms`, `duty`), `name`, `units` (how many), `capacity`
  (per unit) and `options` (`roles`, `balance_age`, `balance_gender`, `separate_gender`, `siblings` = `apart`,
  `together` or `any`; the defaults depend on the kind). `DELETE /divisions/{number}` removes a division.
- `POST /divisions/{number}/propose` places everybody who has no unit yet; with `restart` `true` everybody is placed
  again.
- `PUT /divisions/{number}/members/{participation}` with `unit` (number, or `null` to take the person out).
- `POST /divisions/{number}/units` adds a unit, `PATCH /units/{number}` changes `name`, `note` and `capacity`,
  `DELETE /units/{number}` removes a unit that has nobody in it.

### Lists of an event

- `GET /events/{id}/lists` – the lists the token may read, with titles.
- `GET /events/{id}/lists/{key}` (`role` optional) – `headers` and `rows` as on the page *Lists*. `events:read` is
  enough for `participants`, `figures`, `contacts`, `bus`, `cars`, `groups`, `birthdays`, `answers` and `missing`.
- The lists from the safety sheet – `emergency`, `kitchen`, `medical`, `medication`, `insurance`, `swimmers`,
  `consents`, `changes` – are confidential: they need `health:read` in addition, and every read is recorded in the
  audit log (`list.viewed`), as in the admin interface.

### Groups

Scopes `people:read` / `people:write`: `GET|POST /groups`, `GET|PATCH /groups/{number}`,
`POST /groups/{number}/members` (`person`), `DELETE /groups/{number}/members/{person}`.

### Invoices, payments and refunds

Scopes `finance:read` / `finance:write`.

- `GET /invoices` (`status`, `event`), `GET /invoices/{number}`, `GET /invoices/{number}/pdf`. `installment` is the
  id of the instalment of the payment plan (`null` for single invoices and membership fees), `installment_name`
  what it is for.
- `POST /invoices/{number}/issue` issues a draft (number, reference, due date) and sends it to the payer, as
  *Issue and send* does; with `send` `false` it is only issued. `POST /invoices/{number}/send` sends an issued
  invoice again. Only drafts can be issued, and drafts cannot be sent.
- `POST /invoices/{number}/cancel` with `reason` (**required**, printed on the credit note) cancels an issued
  invoice with a credit note. Payments of the invoice become a credit of the family with a refund proposal. The
  answer is the credit note.
- `POST /invoices/{number}/payments` (`amount`, `paid_on`, `method`, `note`) records a payment.
- `GET /payments` (`match` = `auto`, `manual`, `suggested`, `unmatched`, `credit`, `refunded`).
  `POST /payments/{number}/assign` assigns a payment that is not assigned yet to an open invoice (`invoice` = its
  number; without it the suggested invoice is confirmed). What is paid too much becomes a credit with a refund
  proposal.
- `GET /refunds` (`status` = `proposed`, `done`, `declined`), `POST /refunds/{number}/done` with `date` (the day of
  the bank transfer, not in the future), `POST /refunds/{number}/keep` when the family keeps the credit.
- `GET /invoice-archive` (`search`, `status`) – the invoices of the system used before, read-only.
- Interface only: the bank import from a file, splitting one payment over several invoices, using a credit for
  another invoice, the reduced price of a place and single invoices.

Example: `POST /invoices/{number}/cancel` with `{"reason": "Camp cancelled"}`.

### Accounting

Scopes `finance:read` / `finance:write`; see [Accounting](accounting.md) for the terms and rules.

- Accounts, cost centres, funds and periods are named by their `id` or their exact `name` – accounts also by their
  account `number` – in the path and in the body; every list has both. A name that fits two records (a period name used
  twice, a name that is also the id of another record) answers `422`: use the id then. Amounts are decimals in the
  currency of the installation. What the admin interface refuses (a closed period, debit and credit not equal, an
  inactive cost centre …) answers `422` with the same `message`.
- **Chart of accounts:** `GET|POST /accounting/accounts` (filter `type`, `money` = `bank`, `cash` or `any`, `active`),
  `PATCH|DELETE /accounting/accounts/{id}` with `name`, `number`, `type`, `money`, `iban`, `default_cost_center`, `active`
  and `sort`. The list has the balance today; bank and cash accounts also the funds on them and the difference, as on the page
  *Bank and cash accounts*. System accounts keep their type, and so does an account with bookings; only own accounts without
  bookings can be deleted (switch the others off with `active` `false`).
- **Cost centres:** `GET|POST /accounting/cost-centers` (`name`, `kind` = `carries` or `allocated`, `fund`, `sort`; a new one
  is active in all open periods), `PATCH /accounting/cost-centers/{id}` (`name`, `fund`, `sort`),
  `POST /accounting/cost-centers/{id}/kind` (`kind`, `fund`), `PUT /accounting/cost-centers/{id}/periods/{period}` with
  `active` `true` or `false` (the answer has `warnings`, e.g. money left in its fund), `POST /accounting/cost-centers/{id}/default`
  makes it the standard one. `DELETE /accounting/cost-centers/{id}` with `move_to` (the cost centre that gets the bookings) or
  with `delete_bookings` `true` and `confirm_count` – the number of its bookings (`bookings` in the list) instead of the sentence
  typed in the admin interface; only typed-in bookings in open periods can be deleted, and a backup is made before.
  `cannot_delete` in the list gives the reasons why a cost centre cannot be deleted.
- **Funds:** `GET|POST /accounting/funds` (`name`, `account` = a bank or cash account, `note`, `sort`),
  `PATCH|DELETE /accounting/funds/{id}`; `POST /accounting/funds/{id}/transfers` (`to`, `amount`, `text` = the decision,
  `date`) moves money to another fund with a booking of the kind `fund_transfer`. Only a fund that holds nothing and that no
  cost centre uses can be deleted, never the general fund. Creating cost centres and funds needs the switches below.
- **Periods:** `GET|POST /accounting/periods` (`name`, `starts_on` – only for the first period, later the day after the last –,
  `ends_on`). `PATCH /accounting/periods/{id}` changes `name`, `starts_on` and `ends_on` of an open period; `moved` says how
  many bookings now belong to another period, with `dry_run` `true` nothing is saved.
- **Budget:** `GET /accounting/periods/{id}/budgets` (the planned result per cost centre) and `PUT /accounting/periods/{id}/budgets`
  with `budgets` (`cost_center` = id or exact name, `amount` = planned result, negative for costs; `0` removes it). Cost centres that
  are not listed keep their budget; closed periods can be planned too. MCP: `get_budgets`, `save_budgets`.
- **Allocation keys:** `GET /accounting/periods/{id}/allocation-keys` (one per allocated cost centre),
  `PUT /accounting/periods/{id}/allocation-keys/{cost_center}` with `rules` (`target`, `kind` = `fixed`, `percent`, `rest` or
  `share`, `amount` for `fixed`, `percent` for `percent`) replaces the key of one allocated cost centre in an open period, with the
  checks of the page; an empty list removes it.
- **Closing:** `GET /accounting/periods/{id}/closing` is the preview: `rows` per cost centre (`before`, `allocation`, `after`),
  `allocations`, `funds` (`before`, `result`, `after`), `warnings`, `errors` and `can_close`. `POST /accounting/periods/{id}/close`
  closes as the preview shows. `POST /accounting/periods/{id}/reopen` with `reason` and `confirm` `true` reopens the period and
  every later closed one; without `confirm` the answer is `422` with the `periods` that would be reopened.
- **Bookings:** `GET /accounting/bookings` (`from`, `to`, `period`, `account`, `cost_center`, `kind`, `search`; in pages, with
  the lines), `GET /accounting/bookings/{id}` (the `id`, not the booking `number`), `POST /accounting/bookings` (`date`,
  `text`, `receipt`, `lines` with `account`, `debit` or `credit`, `cost_center`, `fund`, `text`),
  `POST /accounting/bookings/{id}/reverse` (`text`, `date`; allocations and closings are not reversed but removed by reopening).
- **Reports:** `GET /accounting/reports/{report}` – `balance` (`date`), `income` (`period`, `cost_center`), `cost_centers`
  (`period`), `funds` (`period`), `ledger` (`account`, `from`, `to`) or `open_items` (`date`); the date is today and the period
  the current one unless given. The answer has `title`, `headers` and `rows` as on the page; with `format` `csv`, `xlsx` or `pdf`
  it is the file of *Export* (logged).
- **Switches:** `use_cost_centers` and `use_funds` in `GET|PATCH /settings` (`settings:write`), with the rules of the global
  settings.
- Interface only: the receipt file (photo or PDF) of a booking; the answer says `has_receipt_file`.

Example: `POST /accounting/bookings` with `{"date": "2027-03-01", "text": "Shop", "lines": [{"account": "Food", "debit": 30},
{"account": "Material", "debit": 12.5}, {"account": "Cash", "credit": 42.5}]}`.

### Expense claims and advances

Scopes `finance:read` / `finance:write`. See [Expense claims and advances](expenses.md). IBANs are only shown masked.

- `GET /expense-claims` (`status` = `submitted`, `approved`, `rejected`, `paid`, `donated`, `settled`; `person`).
- `POST /expense-claims` enters a claim for a person, as *Enter expense claim* does: `person`, `date`, `amount` and
  `purpose`, or `flat_rate` (name from the settings) with `quantity`; optional `account`, `cost_center`, `event`,
  `advance`, `waived` and `iban` (stored for the refunds of the person). Receipt files only in the interface.
- `POST /expense-claims/{id}/approve` (optional `account`, `cost_center`), `POST /expense-claims/{id}/reject` with
  `reason` (mailed to the person), `POST /expense-claims/{id}/donate` (the person waives the refund).
- `POST /expense-claims/pay` with `claims` (ids), `account` (id, or `bank` / `cash`), `date` and `per_person`: books
  the payout and mails the people. From a bank account the answer has the SEPA file in `sepa_xml` (not via MCP).
- `GET /advances` (`settled`, `person`), `POST /advances` (`person`, `amount`, `account`, `date`, `purpose`),
  `POST /advances/{id}/settle` (`returned` – exactly what is still open –, `account`, `date`).

Example: `POST /expense-claims/pay` with `{"claims": [12, 13], "account": "bank", "date": "2027-07-20"}`.

### Mail

Scopes `mail:read` / `mail:write`.

- `GET /mail-templates`, `GET|PATCH /mail-templates/{template}`, `POST /mail-templates/{template}/preview`
  (`{template}` = id or name). A template has one text: `subject`, `html` and optionally `text`.
- **Texts are single values.** Names, labels and texts (forms and their fields and choices, membership types,
  automations, labels of instalments, cancellation conditions, mail templates) are plain strings. Up to 0.27 they
  were objects with one text per language (`{"de": …, "en": …}`); such input is still accepted and the text in
  the language of the installation is taken. People and families have no `locale` any more.
- Versions: `GET /mail-templates/{template}/versions` and `POST /mail-templates/{template}/versions/{version}/restore`.
  Restoring makes a new version; nothing is lost.
- `GET|POST /mailings` (`template` = id or name of the template), `GET /mailings/{number}`, `POST /mailings/{number}/prepare`,
  `POST /mailings/{number}/release` (asks for the approval unless the token has `approvals:decide`).
- `GET /outgoing-mails` (`status`, `person`) – the mail log with the stored text. **Personal login and approval
  links are never part of the answer**: stored mails hold a placeholder, the link is only made when a mail is sent.
- `GET /bad-addresses` – addresses that came back as not existing; `POST /bad-addresses/{number}/clear` when an
  address works again.
- `GET /sender-profiles` – the one sender of the installation (name, from, reply-to, name of the mail account).
  `POST /sender-profiles` creates it if there is none yet (a second one is refused);
  `PATCH /sender-profiles/{number}` (`name`, `from_name`, `from_address`, `reply_to`, `mail_account`) need
  `settings:write`, as the mail settings in the admin interface are a separate permission.
- Interface only: mail accounts. Their passwords and DKIM keys are never part of an answer.

### Public forms

Scopes `people:read` / `people:write`.

- `GET /public-forms` – the forms with their address and where they lead.
- `GET /public-submissions` (`status` = `pending`, `done`, `declined`, `failed`) – what was sent and confirmed.
  Answers to confidential questions are never part of the answer (`hidden` names them).
- `POST /public-submissions/{number}/accept` carries a pending application out,
  `POST /public-submissions/{number}/reject` (`note`) declines it and deletes its answers.

### Privacy

Reading with `audit:read`, anonymising with the own scope `privacy:write`.

- `GET /consents` (`kind`, `person`) – consents and opt-outs with their proof.
- `GET /deletion-requests` – people who asked for deletion, with what would be deleted and kept.
- `GET /people/{id}/export` – the copy of the data of a person and the children it is a legal guardian of, the
  same as in the admin interface. Needs `audit:read` and `people:read`, and `health:read` when the export holds
  answers to confidential questions (e.g. a safety sheet). Every export is recorded in the audit log.
- `POST /people/{id}/anonymise` with `reason`. It cannot be undone. With `preview` `true` nothing is changed and
  the answer shows what would be deleted and kept. Where the admin interface refuses – e.g. the person still has a
  role – the answer is `422` with `reasons`.
- Interface only: the retention run for old confidential answers.

### Reports

- `GET /statistics` (`event`, `role`, `groups`) – what the page *Statistics* shows: age distribution, age groups,
  participants per year, and with `finance:read` costs and discounts. Scope `events:read`.
- `GET /statistics/age-groups` (`event`, `role`, `groups`, `fields[]`) – the members of the age groups, one row per person
  with the chosen fields (`group`, `first_name`, `last_name`, `age`, `birth_date`, `gender`, `role`; default all), as
  `headers` and `rows` – what *Export* on the page *Statistics* holds. Scopes `events:read` and `people:read`; logged.
- `GET /dashboard` – the next event of every series (registrations, free places, waitlist, bus), to-dos and safety
  sheets confirmed and missing (counts only). Scope `events:read`; figures about money only with `finance:read`,
  automation runs only with `automations:read`.

### Automations

- `GET /automations`, `GET|PATCH /automations/{automation}` (id or name) for `active`, `mode`, `trigger`,
  `trigger_config`, `config`, `approver_roles`, `approvals_needed`, `max_items` and `name`, with the checks of the
  automation form. `trigger_config` and `config` replace the stored object. The action cannot be changed.
  `approver_roles` are ids of roles (ids or names in a request); `approver_roles_default` is `true` while no
  approvers are stored: `approver_roles` then shows the default roles. An empty list for `approver_roles` goes back
  to the default.
- In `config`, mail templates (`template`, `steps[].template`) and the form of the safety sheet reminder (`form`) are
  ids in the answer; a request may send ids or names. The instalment of an invoice run (`installment`) is its
  position in the payment plan of the event the automation runs for: `pos:1`, `pos:2`, `pos:3` … or `pos:last`. An
  instalment key of older versions (e.g. `deposit`) still works.
- Own automations (`automations:write`): `POST /automations` with `name` (unique), `trigger` and `config`
  (`target`, `conditions`, `steps`) creates one with the action `custom`; it is created switched off, as in the
  admin interface. `DELETE /automations/{automation}` deletes an own automation. **Standard automations cannot be
  deleted** (403); switch them off instead.
- `POST /automations/{automation}/preview` (dry run), `POST /automations/{automation}/run`, `GET /automation-runs`,
  `GET /automation-runs/{number}`, `POST /automation-runs/{number}/decision`. A run names its automation by
  `automation` (id) and `automation_name`.

### Import, settings and audit log

- `GET /imports`, `POST /imports/people/preview`, `POST /imports/people/families`, `POST /imports/people`,
  `POST /imports/{number}/rollback` (`import:write`).
- `GET|PATCH /settings` (`settings:write`).
- `GET /audit` (`audit:read`).

### Webhooks via the API

Scope `settings:write` (in the admin interface: administrators).

- `GET|POST /webhooks` (`name`, `url` with https, `events`, `active`), `PATCH /webhooks/{number}`,
  `DELETE /webhooks/{number}`.
- **The signing secret is shown once**: in the answer to `POST /webhooks`, and to `POST /webhooks/{number}/secret`,
  which replaces it. No other answer contains it, and it cannot be set or read.

### Interface only

Own notifications (which events a person is told about) belong to the person who is logged in, so there is no
endpoint for them. API tokens, system status, backups and updates are deliberately only in the admin interface.

## Repeating requests safely

Send the header `Idempotency-Key: <your unique key>` with a write request. If the same request arrives again within 24 hours, it is not
carried out again; you get the first answer with `Idempotent-Replayed: true`. The same key with another request is refused (422), while
the first one is still running you get 409.

## Public events and forms

Without a token, for your own website: `GET /api/v1/public/events`, `GET /api/v1/public/forms/{name}` and
`POST /api/v1/public/forms/{name}`. Only events with a public page and active forms are available. Sending a form only sends the
confirmation mail, and the answer is the same for known and unknown addresses.

## MCP for AI assistants

Kasmin has a built-in MCP server (Model Context Protocol, streamable HTTP) at `https://your-kasmin.example/mcp`.
It offers the REST API as tools with the same token and scopes. A tool calls the REST endpoint, so the same checks
apply and the audit log shows the change with "mcp". Every endpoint for administrative use has exactly one tool
(the participation has two, for status and travel); a test walks the routes of the API and fails when one is
forgotten (`tests/Feature/Api/McpParityTest.php`). Tools name membership types, roles, forms, mail templates and
automations by their id or exact name, as the REST API does (`automation`, `template`, `type`, `form`, `role`).

| Area | Tools | Scopes |
|---|---|---|
| Token | `who_am_i` | – |
| People | `search_people`, `get_person` / `create_person`, `update_person` | `people:read` / `people:write` |
| Contact data | `list_contact_points` / `add_contact_point`, `remove_contact_point` | `people:read` / `people:write` |
| Legal guardians | `list_guardians` / `add_guardian`, `end_guardianship` | `people:read` / `people:write` |
| Families | `list_households`, `get_household` / `create_household`, `update_household`, `add_household_member`, `remove_household_member` | `people:read` / `people:write` |
| Memberships | `list_membership_types`, `list_memberships` / `create_membership_type`, `update_membership_type`, `add_membership`, `update_membership` | `people:read` / `people:write` |
| Roles | `list_roles`, `list_person_roles` / `assign_role`, `remove_role` | `people:read` / `roles:write` **and** `approvals:decide` |
| Duplicates | `list_duplicates` / `merge_duplicates`, `dismiss_duplicate` | `people:read` / `people:write` |
| Groups | `list_groups`, `get_group` / `create_group`, `update_group`, `add_group_member`, `remove_group_member` | `people:read` / `people:write` |
| Events | `list_events`, `get_event`, `get_event_schedule` / `create_event`, `update_event` (whole event form: bus, prices, payment plan) | `events:read` / `events:write` |
| Event series and templates | `list_event_series`, `list_event_templates` / `create_event_series`, `update_event_series`, `create_event_template`, `update_event_template`, `delete_event_template` | `events:read` / `events:write` |
| Registration rounds, documents | `list_registration_rounds`, `list_event_documents` / `save_registration_rounds`, `delete_event_document` | `events:read` / `events:write` |
| Costs of an event | `list_event_costs` / `add_event_cost`, `update_event_cost`, `delete_event_cost` | `finance:read` / `finance:write` |
| Participants and places | `list_participants` / `register_participant`, `update_participation_status`, `set_participation_travel`, `offer_place`, `assign_reserve_place`, `release_reserve_places` | `events:read` / `events:write` |
| Groups, rooms, duties of an event | `list_divisions` / `create_division`, `delete_division`, `propose_division`, `move_division_member`, `add_division_unit`, `update_division_unit`, `delete_division_unit` | `events:read` / `events:write` |
| Lists and reports | `list_event_lists`, `get_event_list`, `get_statistics`, `get_age_group_members`, `get_dashboard` | `events:read` (confidential lists: `health:read`; money: `finance:read`; members of age groups: `people:read`) |
| Invoices | `list_invoices`, `get_invoice` / `record_payment`, `issue_invoice`, `send_invoice`, `cancel_invoice` | `finance:read` / `finance:write` |
| Payments, refunds, archive | `list_payments`, `list_refunds`, `list_archived_invoices` / `assign_payment`, `mark_refund_done`, `keep_refund_as_credit` | `finance:read` / `finance:write` |
| Accounting: chart of accounts, cost centres, funds | `list_accounts`, `list_cost_centers`, `list_funds` / `create_account`, `update_account`, `delete_account`, `create_cost_center`, `update_cost_center`, `change_cost_center_kind`, `set_cost_center_active`, `make_default_cost_center`, `delete_cost_center`, `create_fund`, `update_fund`, `transfer_between_funds`, `delete_fund` | `finance:read` / `finance:write` |
| Accounting: periods, keys, closing | `list_periods`, `get_allocation_keys`, `close_period_preview` / `create_period`, `update_period`, `save_allocation_key`, `close_period`, `reopen_period` | `finance:read` / `finance:write` |
| Accounting: bookings, reports | `list_bookings`, `get_booking`, `get_report` / `create_booking`, `reverse_booking` | `finance:read` / `finance:write` |
| Expense claims, advances | `list_expense_claims`, `list_advances` / `create_expense_claim`, `approve_expense_claim`, `reject_expense_claim`, `donate_expense_claim`, `pay_expense_claims`, `create_advance`, `settle_advance` | `finance:read` / `finance:write` |
| Automations | `list_automations`, `get_automation`, `preview_automation`, `list_approvals`, `get_automation_run` / `create_automation`, `update_automation`, `delete_automation` / `request_automation_run` / `decide_approval` | `automations:read` / `automations:write` / `automations:run` / `approvals:decide` |
| Imports | `import_preview`, `suggest_import_families`, `import_people`, `list_imports`, `rollback_import` | `import:write` |
| Forms | `list_forms`, `get_form_definition`, `get_person_forms`, `get_person_form` / `update_person_form` / `save_form_definition` | `people:read` / `people:write` / `settings:write` (confidential answers: `health:read`, `health:write`) |
| Public forms | `list_public_forms`, `list_public_submissions` / `accept_public_submission`, `reject_public_submission` | `people:read` / `people:write` |
| Mail templates | `list_mail_templates`, `get_mail_template`, `preview_mail_template`, `list_mail_template_versions` / `update_mail_template`, `restore_mail_template_version` | `mail:read` / `mail:write` |
| Mailings | `list_mailings`, `get_mailing` / `create_mailing`, `prepare_mailing`, `request_mailing_release` | `mail:read` / `mail:write` |
| Mail log, sender profiles | `list_outgoing_mails`, `list_bad_addresses`, `list_sender_profiles` / `clear_bad_address` / `create_sender_profile`, `update_sender_profile` | `mail:read` / `mail:write` / `settings:write` |
| Privacy | `list_consents`, `list_deletion_requests` / `export_person_data` / `anonymise_person` | `audit:read` / `audit:read` and `people:read` / `privacy:write` |
| Settings, webhooks | `get_settings`, `update_settings`, `list_webhooks`, `update_webhook`, `delete_webhook` | `settings:write` |
| Audit log | `read_audit_log` | `audit:read` |

Tools that only read are marked read-only, tools that delete, cannot be undone or send mails to families are marked
destructive, so that an MCP client can ask before it calls them. Optional fields are emptied by name with `clear`.

Not available via MCP, on purpose:

- **Files**: uploading a document of an event, downloading a document and the PDF of an invoice, the files of an
  accounting report (`get_report` answers with the table). A tool call carries JSON only; use the admin interface or the
  REST API.
- **Creating a webhook, a new signing secret, a new address of a webhook**: the secret is shown once and belongs
  to the person who sets up the receiving system, and an assistant must not send data of the organisation to an
  address of its choice. `update_webhook` renames, changes the events and switches a webhook on or off.
- Settings that the REST API does not offer either (bank data, public address, mail test mode, embedding, the AI
  setting itself), API tokens, and the own notification settings of a person (interface only).

### What an assistant cannot do

**An assistant cannot switch an approval off.** Via MCP, `automations:write` alone allows `update_automation` to
change the name, trigger and settings of an automation and to switch it on or off. A token without
`approvals:decide` is refused (403) when it tries to

- change who approves and when: `mode`, `max_items`, `approvals_needed` or `approver_roles` – also for a new
  automation: `create_automation` without these fields creates an automation that is switched off and asks the
  approvers before every run (mode *confirm*). Deleting an own automation and creating it again is therefore no
  way around an approval;
- change the trigger or the settings of an automation in the mode *automatic* (it runs without approval), or
  switch such an automation on. Switching off and renaming stay possible;
- change the setting `mailing_approval_above` (from how many recipients a mailing needs an approval) with
  `update_settings`;
- give or take a role (`assign_role`, `remove_role`): a role can make somebody an approver or open the admin
  interface. The two tools are only offered with `roles:write` **and** `approvals:decide`. The role
  *administrator* can never be given or taken with a token, as in the REST API.

These limits apply to MCP only; in the REST API `automations:write`, `settings:write` and `roles:write` are enough.

**Health and safety data follow the setting "AI processing allowed".** With "Non-confidential data" the scopes
`health:read` and `health:write` do not count via MCP, whatever the token has:

- `get_event_list` refuses the confidential lists (emergency, kitchen, medical, medication, insurance, swimmers,
  consents, changes), and `list_event_lists` does not name them;
- `get_person_form` leaves out the answers to confidential questions (their fields are named in `hidden`), and
  `update_person_form` refuses to write them;
- `export_person_data` is refused when the export holds answers to confidential questions or emergency contacts
  of the family;
- `list_public_submissions` never shows answers to confidential questions, with any setting.

With "All data" the scopes count as in the REST API, and every read of confidential data is recorded in the audit log.

**The accounting asks twice.** `reopen_period` is refused without `confirm` `true` and names the periods that would be
reopened with it; `delete_cost_center` deletes bookings only with `confirm_count`, the number of these bookings. The
assistant is told to show the human `close_period_preview` before `close_period` and the lines before `create_booking`;
`close_period`, `reopen_period`, `reverse_booking` and the deleting tools are marked destructive.

**Things that cannot be undone need their own scope and a human.** `anonymise_person` needs `privacy:write`
(nothing else includes it), and the assistant is told to call it with `preview` first and to act only after the
approval of a human; the same holds for `merge_duplicates`, `cancel_invoice`, `issue_invoice` and the tools that
delete.

Requirements:

1. *Administration → Global settings → AI assistants*: **AI processing allowed** must not be "None". With
   "Non-confidential data", health and safety data stay unavailable via MCP even with the scope `health:read`.
   Only allow what your privacy policy and a data processing agreement with the AI provider cover.
2. A token with **May be used by AI assistants (MCP)** switched on, and only the scopes the assistant needs.
   The assistant only sees the tools its scopes allow.

Example configuration of an MCP client (the exact format depends on the client):

```json
{
  "mcpServers": {
    "kasmin": {
      "type": "http",
      "url": "https://your-kasmin.example/mcp",
      "headers": { "Authorization": "Bearer kas_…" }
    }
  }
}
```

Recommendation: give an AI assistant `automations:run` but not `approvals:decide` – it can prepare runs, the
approvers decide by e-mail. Give it `automations:write` only if it should set up automations; without
`approvals:decide` the approvals stay as they are. Give `finance:write`, `privacy:write`, `settings:write` and
`roles:write` only for the task at hand and with an expiry date: with them an assistant can issue and cancel
invoices, anonymise people and change settings without a second person.

## Webhooks

*Administration → Webhooks* (administrators): Kasmin calls your HTTPS address when something happens. Events:
`participation.created`, `participation.status_changed`, `person.created`, `invoice.issued`, `invoice.paid`,
`payment.received`, `form.confirmed`, `automation.approval_requested`, `automation.run_finished`, `import.finished`.

Each call is a `POST` with JSON (`event`, `occurred_at`, `data`, `delivery`) and the headers `X-Kasmin-Event`,
`X-Kasmin-Delivery`, `X-Kasmin-Timestamp` and `X-Kasmin-Signature: sha256=<HMAC-SHA256 of "timestamp.body" with
the secret>`. Check the signature and reject old timestamps (e.g. older than 5 minutes):

```php
$expected = 'sha256='.hash_hmac('sha256', $_SERVER['HTTP_X_KASMIN_TIMESTAMP'].'.'.$body, $secret);
$valid = hash_equals($expected, $_SERVER['HTTP_X_KASMIN_SIGNATURE'])
    && abs(time() - (int) $_SERVER['HTTP_X_KASMIN_TIMESTAMP']) < 300;
```

Answer with a 2xx status. Failed deliveries are retried after 1, 5, 30, 120 and 720 minutes; the delivery log
shows every attempt and allows sending again. Payloads contain no health or safety data.

## Templates and automations as files

```
php artisan kasmin:config:export kasmin-config.json         # templates and automations
php artisan kasmin:config:import kasmin-config.json --dry-run
php artisan kasmin:config:import kasmin-config.json [--update]
```

Import creates what is missing and leaves existing entries alone unless `--update` is given (templates then get a
new version). Templates are checked with the template sandbox first; imported automations are switched off. The file
matches templates and automations by their internal key, which is the same in every installation for the standard
ones and is never shown in the admin interface.

## Parity: interface, REST and MCP

Legend: yes / part / no / n/a (deliberately interface only). MCP tools call the REST API, so MCP can never do more
than REST; what REST offers and MCP deliberately does not (files, webhook secrets) is listed in the MCP section.

| Area | Browser | REST | MCP | Gap |
|---|---|---|---|---|
| People: search, read, create, change | yes | yes | yes | – |
| Contact data, legal guardians | yes | yes | yes | – |
| Families (households) | yes | yes | yes | – |
| Duplicates: list, merge, dismiss | yes | yes | yes | – |
| Memberships of a person (assign type) | yes | yes | yes | – |
| Membership types | yes | yes | yes | – |
| Roles: list, assign to a person | yes | yes (not the role administrator) | yes (also needs approvals:decide) | – |
| Groups, members | yes | yes | yes | – |
| Group waitlist | yes | no | no | both |
| Event series, event templates | yes | yes | yes | – |
| Events: basic data, roles | yes | yes | yes | – |
| Events: bus booked, seats | yes | yes | yes | – |
| Events: prices, instalments, rounds, costs, documents | yes | yes (image in the interface) | part (documents: list and delete) | MCP: no files (upload, download) |
| Registration, participants, travel | yes | yes | yes | – |
| Offers, reserve places, waitlist flags | yes | part (offers, reserve places) | part (as REST) | REST and MCP: waitlist flags and skipping |
| Groups, rooms, duties of an event | yes | yes | yes | – |
| Lists of an event (participants, bus, kitchen …) | yes | yes (JSON; health lists need health:read) | yes (health lists only with "All data") | files (PDF, XLSX) only in the interface |
| Confirmation of participation (PDF) | yes | no | no | REST |
| Forms: definition | yes | yes | yes | – |
| Forms: answers of a person | yes | yes | yes (confidential answers only with "All data") | – |
| Public forms and their submissions | yes | part (read, accept, reject) | part (as REST) | REST and MCP: creating and changing a public form |
| Invoices: list, read, PDF, record payment | yes | yes | yes (no PDF) | n/a for MCP: files |
| Invoices: issue, send, cancel/credit note, reduced place | yes | part (issue, send, cancel) | part (as REST) | REST and MCP: reduced place, single invoice |
| Payments: list, bank import, assign | yes | part (list, assign) | part (as REST) | REST and MCP: bank import, split, use a credit |
| Refunds, credits | yes | yes | yes | – |
| Invoice archive | yes | yes (read; import in the interface) | yes (read) | – |
| Accounting: chart of accounts, cost centres, funds, fund transfers | yes | yes | yes | – |
| Accounting: periods, allocation keys, closing, reopening | yes | yes | yes | – |
| Accounting: bookings (type in, reverse), journal | yes | yes (receipt file in the interface) | yes (no receipt file) | REST and MCP: receipt file |
| Accounting: bank and cash accounts, reports | yes | yes (JSON, CSV, XLSX, PDF) | yes (table) | n/a for MCP: files |
| Accounting: switches (cost centres, funds) | yes | yes | yes (`update_settings`) | – |
| Expense claims, advances | yes | yes (no receipt files; SEPA file as text) | yes (no SEPA file, IBAN masked) | MCP: no files |
| Mail templates: read, preview, change | yes | yes | yes | – |
| Mail template versions, restore | yes | yes | yes | – |
| Mailings: create, prepare, release | yes | yes | yes | – |
| Sent mails, bad addresses | yes | yes | yes | – |
| Mail accounts, sender profiles | yes | part (sender profiles) | part (sender profiles) | n/a for accounts and passwords |
| Automations: read, change, preview, run, approvals | yes | yes | yes | – |
| Own automations: create, delete | yes | yes | yes (approval settings need approvals:decide) | – |
| Season wizard / schedule of an event | yes | part (schedule) | part (schedule) | REST and MCP: season wizard |
| Import of people | yes | yes | yes | – |
| Statistics, dashboard figures | yes | yes | yes | – |
| Settings | yes | yes | yes (mailing_approval_above needs approvals:decide) | – |
| Audit log | yes | yes | yes | – |
| Consents, deletion requests, data export, anonymise | yes | yes (retention run in the interface) | yes (export with health or safety data only with "All data") | – |
| Notifications (own subscriptions) | yes | n/a (interface only) | no | personal to the person who is logged in |
| Webhooks | yes | yes | part (list, rename, events, switch, delete) | n/a for MCP: creating, address and signing secret (deliberately not for an assistant) |
| API tokens, system status, backups, update, upgrade | yes | no | no | n/a (deliberately interface only) |
| Portal of the family (own data, children, safety sheet) | yes | n/a | n/a | decided: the API is for administrative use only |
