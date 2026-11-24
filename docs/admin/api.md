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
| `people:read` / `people:write` | read / create and change people and families |
| `events:read` / `events:write` | read events and participants / register, change participations |
| `finance:read` / `finance:write` | read invoices (incl. PDF) / record payments |
| `automations:read` | read automations and runs, dry runs |
| `automations:run` | start runs – **as approval requests** (the approvers decide) |
| `approvals:decide` | decide approvals (and start runs directly). Give it only to tokens a person controls |
| `import:write` | import people, roll imports back |
| `audit:read` | read the audit log |
| `health:read` | health and safety data (separate on purpose) |
| `settings:write` | change settings |

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
- Ids of people, families and events are their public ids (ULIDs), not database numbers.

```
curl -H "Authorization: Bearer kas_…" https://your-kasmin.example/api/v1/events
```

**Dangerous actions need a human** (X05): starting an automation via the API (invoice runs, mailings …) creates an
approval request unless the token has `approvals:decide`.

## MCP for AI assistants

Kasmin has a built-in MCP server (Model Context Protocol, streamable HTTP) at `https://your-kasmin.example/mcp`.
It offers the REST API as tools (search people, events, participants, invoices, automations with dry run, approval
requests, import with dry run and rollback, audit log) with the same token and scopes.

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
approvers decide by e-mail.

## Webhooks

*Settings → Webhooks* (administrators): Kasmin calls your HTTPS address when something happens. Events:
`participation.created`, `participation.status_changed`, `person.created`, `invoice.issued`, `invoice.paid`,
`payment.received`, `automation.approval_requested`, `automation.run_finished`, `import.finished`.

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
