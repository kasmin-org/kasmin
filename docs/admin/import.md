# Importing people and families

Kasmin imports people, legal guardians, families, groups, memberships and past participations from a CSV or Excel
file, from **Webling**, via the REST API or through an AI assistant (MCP). Every import:

- can run as **dry run** first: what would be created, updated or skipped, with errors per row;
- builds **families** from flat lists (see below);
- is **idempotent**: rows keep their source and `source_id`, so the same export can be imported again (e.g. weekly
  until the switch) without duplicates; changes in the source update the person;
- is an **import batch** that can be **rolled back** (*People → Imports → Roll back*) as long as nothing was changed
  afterwards.

Health and safety data are not imported this way.

## Import wizard

*People → Import people*:

1. **Source**: a CSV or Excel file whose first row contains column names, plus a source name (e.g. `old-list`); or
   Webling with its address and an API key with read permission (not stored). Files are deleted right after reading.
2. **Assign the fields**: for each Kasmin field choose the column. Columns named like the fields (or Webling's
   standard fields) are assigned automatically.
3. **Dry run**, check errors and family suggestions, then **Import**.

## Fields

| Field | Meaning |
|---|---|
| `source_id` | id in the source system (needed for repeated imports) |
| `first_name`, `last_name` | required |
| `birth_date` | `YYYY-MM-DD` or `DD.MM.YYYY` |
| `gender`, `locale` (`de`/`en`) | |
| `email`, `phone` | of the person itself (adults; they get a login) |
| `street`, `postal_code`, `city`, `country` | address of the family |
| `guardian1_first_name`, `guardian1_last_name`, `guardian1_email`, `guardian1_phone` | first legal guardian (makes the row a child; the guardian gets an own record and a login) |
| `guardian1_name` | alternatively the full name in one field ("First Last") |
| `guardian2_…` | second legal guardian, same fields |
| `family` | optional family key: rows with the same key form one family |
| `groups` | group names, comma-separated (created if missing) |
| `membership_type`, `membership_start`, `membership_end` | membership (type key, e.g. `child`) |
| `participations` | past participations: `Event title:role:status;…`, e.g. `Summer camp 2026:child:attended` (the event must exist) |

## Family builder

Rows are joined into one family when they share an **e-mail address**, a **phone number** or the **same legal
guardians** (with the same last name) – confidence *high*. Rows that only share the **address** are shown as
suggestions with confidence *medium* and are not merged automatically; add a `family` column to merge them.

## Webling

The wizard and the command line read members and their member groups through the Webling API. The groups become
Kasmin groups. Because Webling fields differ between organisations, assign custom fields (e.g. the legal guardians'
names) in step 2.

```
KASMIN_WEBLING_KEY=… php artisan kasmin:import:people --webling=https://example.webling.eu [--mapping=fields.json] [--import]
php artisan kasmin:import:people members.csv --source=old-list [--import]
```

Without `--import` the command only shows the dry run. `fields.json` assigns Kasmin fields to Webling fields, e.g.
`{"first_name": "Vorname", "last_name": "Name", "guardian1_name": "Erziehungsberechtigte/r 1"}`.

See also [Migrate with an AI assistant](migrate-with-ai.md).
