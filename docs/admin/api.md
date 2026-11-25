# REST API, MCP and webhooks

Everything an integration or an AI assistant needs goes through **API tokens**. A token acts on behalf of the
administrator who created it, limited to the **scopes** chosen for it. It stops working when that person loses the
administrator role.

## API tokens

*Settings → API tokens* (administrators): **Create token**, choose a name, the scopes and an expiry. The token
(`kas_…`) is shown **once** – copy it into the other system. Revoke tokens you no longer need; the list shows when
each token was last used. Every write via the API is recorded in the audit log with the token as actor.

| Scope | Allows |
|---|---|
| `people:read` / `people:write` | read / create and change people, families, contact data, legal guardians, memberships, groups and membership types; read roles; list / merge duplicates; read / decide public submissions |
| `events:read` / `events:write` | read events, participants, rounds, documents, templates, lists, groups and rooms, statistics and the dashboard / create and change events (incl. prices and payment plan), series, templates, rounds and documents, register, change participations, offer places, groups and rooms |
| `finance:read` / `finance:write` | read invoices (incl. PDF), payments, refunds and the invoice archive and the costs of an event / issue, send and cancel invoices, record and assign payments, settle refunds, change the costs of an event |
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
  `/invoice-archive`, `/automation-runs`, `/outgoing-mails`, `/public-submissions`, `/consents`) come in pages:
  `page` and `per_page` (default 50, at most 200) in the query, and `meta` with `total`, `page` and `last_page` in
  the answer. They are sorted newest first, people and families by name.
- Creating answers with `201`. Where the entry already existed (a person that already is a member of the group
  in `POST /groups/{id}/members` or of the family, or already is a legal guardian) the answer is `200` and nothing
  changes. A request the admin interface would refuse answers with `422` and a `message`; a missing scope with `403`.
- The API is for administrative use only: a token always acts for an administrator, and there is no API for
  families or other portal users.
- Ids of people, families and events are their public ids (ULIDs), not database numbers. Everything else
  (participations, invoices, payments, groups, divisions, units …) has a number.
- `GET /me` shows the token in use: name, scopes, expiry and the user it acts for.

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
- Memberships of a person: `GET|POST /people/{id}/memberships` (`type` = key of a membership type, `starts_on`,
  `ends_on`), `PATCH /people/{id}/memberships/{number}` with `ends_on` ends a membership (`null` lets it run
  again). A person has one running membership per type at most.
- Membership types: `GET|POST /membership-types`, `PATCH /membership-types/{key}` (`name.de`, `name.en`, `pays_fee`, `fee`).
- Duplicates: `GET /duplicates` lists the pairs that are probably the same person with the reason.
  `POST /duplicates/merge` (`keep`, `duplicate`, and `fields` such as `{"birth_date": "duplicate"}` for the values
  that should come from the duplicate) moves everything to the person to keep and deletes the duplicate – this
  cannot be undone. It is refused when the merge would give somebody more rights or both have an invoiced
  registration for the same event. `POST /duplicates/dismiss` (`first`, `second`) marks two people as different.
- Forms: `GET /forms`, `GET /people/{id}/forms`, `GET|PATCH /people/{id}/forms/{form}`. Confidential values need
  `health:read` / `health:write`. `GET|PUT /forms/{form}/definition` reads and replaces the definition of a form
  (`settings:write` to replace).

Example: `POST /people/{id}/memberships` with `{"type": "member", "starts_on": "2027-01-01"}`.

### Families

Scopes `people:read` / `people:write`.

- `GET /households` (`search`), `GET /households/{id}` – with members, language and `invoice_recipient`.
- `POST /households` (`name`, `street`, `postal_code`, `city`, `country`, `locale`) creates a family without
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
- `POST /people/{id}/roles` (`role` = key, `event` only for roles that count for the own events, `starts_on`,
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
  `capacity`, `reserve_places`, `min_age`, `max_age`, `price`, `registration_form` = key of a registration form …).
  A booked bus needs its number of seats. The answer has `bus` with `riders`, `cars`, `free`, `over`,
  `adults_on_bus` and `children_by_car`. The image is set in the admin interface.
- Prices and payment plan, in the same requests: `sibling_mode` (`none`, `staggered`, `all`), `sibling_order`
  (`oldest`, `highest_price`, `registration`), `sibling_amount`, `sibling_cap_position`, `sibling_min_children`,
  `cancellation_fee`, `cancellation_text.de` / `.en`, `reminder_days_before` (list of days), `prices` (prices by
  age or date: `role`, `min_age`, `max_age`, `from_date`, `until_date`, `price` – the list replaces the stored
  one) and `installments` (`key`, `label.de`, `label.en`, `kind` = `fixed`, `rest` or `member`, `amount`,
  `due_date`, `due_days`, `membership_type`, `refundable`, `refund_until_days`, `confirms_place`,
  `paid_add_group`, `paid_remove_group`, `discounts_apply`). Instalments go by key like roles: what is listed is
  created or changed, the rest is removed. As in the event form, prices can be changed while invoices exist: invoices
  that are issued stay as they are. An instalment that already has invoices cannot
  be removed (422). The currency is shown but cannot be changed, as in the form.
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
- Event series: `GET|POST /event-series` (`name`, `type`), `PATCH /event-series/{number}`.
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

- `GET /invoices` (`status`, `event`), `GET /invoices/{number}`, `GET /invoices/{number}/pdf`.
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

### Mail

Scopes `mail:read` / `mail:write`.

- `GET /mail-templates`, `GET|PATCH /mail-templates/{key}`, `POST /mail-templates/{key}/preview`.
- Versions: `GET /mail-templates/{key}/versions` and `POST /mail-templates/{key}/versions/{version}/restore`.
  Restoring makes a new version; nothing is lost.
- `GET|POST /mailings`, `GET /mailings/{number}`, `POST /mailings/{number}/prepare`,
  `POST /mailings/{number}/release` (asks for the approval unless the token has `approvals:decide`).
- `GET /outgoing-mails` (`status`, `person`) – the mail log with the stored text. **Personal login and approval
  links are never part of the answer**: stored mails hold a placeholder, the link is only made when a mail is sent.
- `GET /bad-addresses` – addresses that came back as not existing; `POST /bad-addresses/{number}/clear` when an
  address works again.
- `GET /sender-profiles` (name, from, reply-to, name of the mail account). `POST /sender-profiles` and
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
- `GET /dashboard` – the next event of every series (registrations, free places, waitlist, bus), to-dos and safety
  sheets confirmed and missing (counts only). Scope `events:read`; figures about money only with `finance:read`,
  automation runs only with `automations:read`.

### Automations

- `GET /automations`, `GET|PATCH /automations/{key}` for `active`, `mode`, `trigger`, `trigger_config`, `config`,
  `approver_roles`, `approvals_needed`, `max_items` and `name`, with the checks of the automation form.
  `trigger_config` and `config` replace the stored object. Key and action cannot be changed.
  `approver_roles_default` is `true` while no approvers are stored: `approver_roles` then shows the default roles.
  An empty list for `approver_roles` goes back to the default.
- Own automations (`automations:write`): `POST /automations` with `name.de`, `name.en`, `trigger` and `config`
  (`target`, `conditions`, `steps`) creates one with the action `custom`; it is created switched off, as in the
  admin interface. `DELETE /automations/{key}` deletes an own automation. **Standard automations cannot be
  deleted** (403); switch them off instead.
- `POST /automations/{key}/preview` (dry run), `POST /automations/{key}/run`, `GET /automation-runs`,
  `GET /automation-runs/{number}`, `POST /automation-runs/{number}/decision`.

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
It offers the REST API as tools (search people, events, participants, invoices, automations with dry run, approval
requests, import with dry run and rollback, audit log) with the same token and scopes. A tool calls the REST
endpoint, so the same checks apply and the audit log shows the change with "mcp".

| Tools | Scope |
|---|---|
| `list_contact_points` / `add_contact_point`, `remove_contact_point` | `people:read` / `people:write` |
| `list_guardians` / `add_guardian`, `end_guardianship` (legal guardians) | `people:read` / `people:write` |
| `list_membership_types` / `create_membership_type`, `update_membership_type` | `people:read` / `people:write` |
| `create_event`, `update_event`, `set_participation_travel` (bus or car) | `events:write` |
| `get_automation` / `update_automation` | `automations:read` / `automations:write` |

The other tools (people, groups, forms, events, invoices, mailings, imports, automation runs, audit log) follow
the same pattern; the assistant lists the ones its token allows.

**An assistant cannot switch an approval off.** Via MCP, `automations:write` alone allows `update_automation` to
change the name, trigger and settings of an automation and to switch it on or off. A token without
`approvals:decide` is refused (403) when it tries to

- change who approves and when: `mode`, `max_items`, `approvals_needed` or `approver_roles`;
- change the trigger or the settings of an automation in the mode *automatic* (it runs without approval), or
  switch such an automation on. Switching off and renaming stay possible.

This limit applies to MCP only; in the REST API `automations:write` is enough for all of these.

Requirements:

1. *Settings → Organisation and invoices → AI assistants*: **AI processing allowed** must not be "None". With
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
`approvals:decide` the approvals stay as they are.

## Webhooks

*Settings → Webhooks* (administrators): Kasmin calls your HTTPS address when something happens. Events:
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
new version). Templates are checked with the template sandbox first; imported automations are switched off.

## Parity: interface, REST and MCP

Legend: yes / part / no / n/a (deliberately interface only). MCP tools call the REST API, so MCP can never do more
than REST.

| Area | Browser | REST | MCP | Gap |
|---|---|---|---|---|
| People: search, read, create, change | yes | yes | part | MCP: no update_person |
| Contact data, legal guardians | yes | yes | yes | – |
| Families (households) | yes | yes | no | MCP: nothing |
| Duplicates: list, merge, dismiss | yes | yes | no | MCP |
| Memberships of a person (assign type) | yes | yes | no | MCP |
| Membership types | yes | yes | yes | – |
| Roles: list, assign to a person | yes | yes (not the role administrator) | no | MCP |
| Groups, members | yes | yes | part | MCP: no update_group |
| Group waitlist | yes | no | no | both |
| Event series, event templates | yes | yes | no | MCP |
| Events: basic data, roles | yes | yes | yes | – |
| Events: bus booked, seats | yes | yes | no | MCP |
| Events: prices, instalments, rounds, costs, documents | yes | yes (image in the interface) | no | MCP |
| Registration, participants, travel | yes | yes | part | MCP: status cannot be changed |
| Offers, reserve places, waitlist flags | yes | part (offers, reserve places) | no | REST: waitlist flags and skipping; MCP |
| Groups, rooms, duties of an event | yes | yes | no | MCP |
| Lists of an event (participants, bus, kitchen …) | yes | yes (JSON; health lists need health:read) | no | MCP; files (PDF, XLSX) only in the interface |
| Confirmation of participation (PDF) | yes | no | no | REST |
| Forms: definition | yes | yes | yes | – |
| Forms: answers of a person | yes | yes | part | MCP: read only |
| Public forms and their submissions | yes | part (read, accept, reject) | no | REST: creating and changing a public form; MCP |
| Invoices: list, read, PDF, record payment | yes | yes | yes (no PDF) | – |
| Invoices: issue, send, cancel/credit note, reduced place | yes | part (issue, send, cancel) | no | REST: reduced place, single invoice; MCP |
| Payments: list, bank import, assign | yes | part (list, assign) | no | REST: bank import, split, use a credit; MCP |
| Refunds, credits | yes | yes | no | MCP |
| Invoice archive | yes | yes (read; import in the interface) | no | MCP |
| Mail templates: read, preview, change | yes | yes | part | MCP: no update |
| Mail template versions, restore | yes | yes | no | MCP |
| Mailings: create, prepare, release | yes | yes | yes | – |
| Sent mails, bad addresses | yes | yes | no | MCP |
| Mail accounts, sender profiles | yes | part (sender profiles) | no | n/a for accounts and passwords; MCP |
| Automations: read, change, preview, run, approvals | yes | yes | yes | – |
| Own automations: create, delete | yes | yes | no | MCP |
| Season wizard / schedule of an event | yes | part (schedule) | no | REST: season wizard; MCP |
| Import of people | yes | yes | yes | – |
| Statistics, dashboard figures | yes | yes | no | MCP |
| Settings | yes | yes | no | MCP |
| Audit log | yes | yes | yes | – |
| Consents, deletion requests, data export, anonymise | yes | yes (retention run in the interface) | no | MCP |
| Notifications (own subscriptions) | yes | n/a (interface only) | no | personal to the person who is logged in |
| Webhooks | yes | yes | no | MCP |
| API tokens, system status, backups, update, upgrade | yes | no | no | n/a (deliberately interface only) |
| Portal of the family (own data, children, safety sheet) | yes | n/a | n/a | decided: the API is for administrative use only |
