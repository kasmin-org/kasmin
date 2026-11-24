# Migrate with an AI assistant

Moving from another program (a spreadsheet, Webling, another association software) is mostly mapping work: which
column is which field, which people belong to one family, which rows are duplicates. An AI assistant connected to
Kasmin via **MCP** (or a script using the REST API) can do most of it: read the old export, map it, run dry runs,
explain the result to you – and import only after you agreed.

## Respect your privacy policy first

A migration passes personal data to the AI service. Before you start, check:

- [ ] **Legal basis and privacy policy**: does your privacy policy allow processing member data with this AI
      service (provider, country, purpose)? If not, update it first or do the migration without AI.
- [ ] **Data processing agreement** with the AI provider (in the EU: Art. 28 GDPR), and the provider does not use
      your data for training.
- [ ] **Data minimisation**: export only the fields you migrate; leave out what Kasmin does not need.
- [ ] **Health and safety data** (allergies, medication …) are **not** passed to the AI unless your organisation
      explicitly decided so. Import them separately (e.g. let the families enter them in the portal).
- [ ] **Setting** *AI processing allowed* in Kasmin: "Non-confidential data" for a normal migration.
- [ ] **Token** for the assistant: MCP switched on, only `people:read`, `events:read`, `import:write` (plus what you
      really need), a short expiry; revoke it after the migration.
- [ ] Delete the export files and the AI conversation when you are done, as far as your provider allows.

## Steps

1. Create the token (*Settings → API tokens*) and connect the assistant to `https://your-kasmin.example/mcp`
   (see [API and MCP](api.md)).
2. Give the assistant the export (or let it read Webling via the import wizard instead).
3. Let it **map** the columns to the [import fields](import.md#fields) and run **`import_preview`** with a stable
   source name (e.g. `webling`) and the source ids.
4. **Review** the dry run with the assistant: errors, duplicates, family suggestions ("medium" = only the same
   address). Correct the data or add `family` keys.
5. **`import_people`**. Check a few families in Kasmin.
6. If something went wrong: **`rollback_import`** (or *People → Imports → Roll back*), fix, import again.
7. While both systems run in parallel, repeat steps 3–5 with the same source; rows are updated, not duplicated.

## Example prompts

- "Here is our member list as CSV. Map the columns to Kasmin's import fields and show me the mapping as a table.
  Don't import anything yet."
- "Run import_preview with source `old-list`. List all rows with errors and all family suggestions with
  confidence medium, and tell me what you would change."
- "The preview looks good – import it with import_people and tell me the batch number."
- "Roll back batch 12, we used the wrong file."
