# Data protection: deletion, retention, audit log

## Deletion requests

People can request the deletion of their data with the link in every mail. *Data protection → Deletion and
retention* lists the open requests. **Review** shows what will be deleted (contact data, login, form answers incl.
safety sheets, mails, family and group links) and what must be kept, with the reason:

- invoices within the legal retention period (setting, default 7 years) – the name stays on the invoice;
- participations, anonymised, for statistics (year of birth, role, year);
- the audit log, which cannot be changed and records the anonymisation. **Older entries can still contain the previous
  name** (e.g. a name change); database backups contain the data until they are deleted.

Names are also removed from import records, automation plans, notifications, webhook deliveries, replies and from
invoices whose retention period is over. People with an active role must lose it first.

**Anonymise now** replaces the name, reduces the date of birth to the year and deletes the rest. It cannot be
undone. Inform the person yourself.

## Retention of confidential data

Answers of forms with health or identity data (e.g. the safety sheet) are deleted automatically every night when the
person's last event ended more than the configured number of years ago (default 7, *Settings → Organisation and
invoices → Retention*), unless the person is registered for an upcoming event. The page shows what is due and can
run it at once; every deletion is logged. Command line: `php artisan kasmin:privacy:retention [--dry-run]`.

## Audit log

*Data protection → Audit log* (administrators and auditors): every change, login, mail, export, API/MCP call that
writes, automation run and anonymisation, with time, actor (person, API token, MCP, automation, system), object,
IP address and request id. Filter by action (e.g. `login.`), actor and date. The log cannot be changed or deleted.

## Consents and opt-outs

*Data protection → Consents and opt-outs* lists newsletter consents, opt-outs, deletion requests and completed
deletions with date and source.
