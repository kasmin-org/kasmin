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
| `people:read` / `people:write` | read / create and change people, families, contact data, legal guardians, groups and membership types |
| `events:read` / `events:write` | read events and participants / create and change events, register, change participations |
| `finance:read` / `finance:write` | read invoices (incl. PDF) / record payments |
| `mail:read` / `mail:write` | read mail templates and mailings / change templates, create, prepare and release mailings |
| `automations:read` | read automations and runs, dry runs |
| `automations:write` | change automations: active, mode, trigger, settings, approvers. It can switch an approval off – give it only to tokens a person controls. Via MCP, changes to an approval also need `approvals:decide` |
| `automations:run` | start runs – **as approval requests** (the approvers decide) |
| `approvals:decide` | decide approvals (and start runs directly). Give it only to tokens a person controls |
| `import:write` | import people, roll imports back |
| `audit:read` | read the audit log |
| `health:read` | confidential values of forms, e.g. the safety sheet (separate on purpose; every read is logged) |
| `health:write` | change confidential values; the legal guardians have to confirm them in the portal |
| `settings:write` | read and change settings – not bank data, public address, test mode, embedding, time zone and AI settings |

Rate limit: 120 requests per minute per token.

## REST API v1

Base URL `https://your-kasmin.example/api/v1`, header `Authorization: Bearer kas_…`, JSON in and out.

- **Documentation** with every endpoint, parameter and response: `https://your-kasmin.example/docs/api`
  (OpenAPI 3.1 as JSON: `/docs/api.json`; every release also ships `openapi.json`).
- Main endpoints: `GET /me`, `GET|POST /people`, `GET|PATCH /people/{id}`, `GET /households`, `GET /events`,
  `GET /events/{id}`, `GET /events/{id}/participations`, `POST /events/{id}/registrations`,
  `PATCH /participations/{id}`, `GET /invoices`, `GET /invoices/{id}`, `GET /invoices/{id}/pdf`,
  `POST /invoices/{id}/payments`, `GET /automations`, `POST /automations/{key}/preview`, `POST /automations/{key}/run`,
  `GET /automation-runs`, `POST /automation-runs/{id}/decision`, `POST /imports/people/preview`,
  `POST /imports/people`, `POST /imports/{id}/rollback`, `GET /audit`.
- Contact data of a person: `GET|POST /people/{id}/contact-points` (`type` `email` or `phone`, `value`, `purposes`,
  `is_primary`), `DELETE /people/{id}/contact-points/{number}`. The address a person logs in with cannot be removed.
- Legal guardians of a person: `GET|POST /people/{id}/guardians` (`guardian` = id of the person),
  `DELETE /people/{id}/guardians/{guardian id}` ends the guardianship. The setting *Legal guardians per family (at most)*
  also applies here; families are not changed.
- Membership types: `GET|POST /membership-types`, `PATCH /membership-types/{key}` (`name.de`, `name.en`, `pays_fee`, `fee`).
- Events: `POST /events`, `PATCH /events/{id}` with the basic fields of the event form (title, dates, status,
  `series_id`, registration window, place, contact) and `roles` (`role`, `capacity`, `reserve_places`, `min_age`,
  `max_age`, `price` …). Prices by age or date, instalments, discounts, registration rounds, costs, documents and
  the image are set in the admin interface.
- Automations: `GET|PATCH /automations/{key}` for `active`, `mode`, `trigger`, `trigger_config`, `config`,
  `approver_roles`, `approvals_needed`, `max_items` and `name`, with the checks of the automation form.
  `trigger_config` and `config` replace the stored object. Key and action cannot be changed.
  `approver_roles_default` is `true` while no approvers are stored: `approver_roles` then shows the default roles.
  An empty list for `approver_roles` goes back to the default.
- Lists that can get long (`/people`, `/households`, `/events`, `/invoices`, `/automation-runs`) come in pages:
  `page` and `per_page` (default 50, at most 200) in the query, and `meta` with `total`, `page` and `last_page` in
  the answer. Events and runs are sorted newest first.
- Creating answers with `201`. Where the entry already existed (a person that already is a member of the group
  in `POST /groups/{id}/members`, or already is a legal guardian) the answer is `200` and nothing changes.
- Ids of people, families and events are their public ids (ULIDs), not database numbers.

```
curl -H "Authorization: Bearer kas_…" https://your-kasmin.example/api/v1/events
```

**Dangerous actions need a human** (X05): starting an automation via the API (invoice runs, mailings …) creates an
approval request unless the token has `approvals:decide`.

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
