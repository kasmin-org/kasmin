# Roles and permissions

## Assign roles

*People → person → Roles* (administrators only): role, from, until. A person with an e-mail address automatically gets a login.
Please name at least **two administrators**, so that the organisation does not depend on one person. The last administrator
cannot be removed.

## Permissions

*Settings → Roles* (administrators only): access per area – none, view or edit – and own roles. Defaults:

| Area | Administrator | Board | Treasurer | Safety officer | Camp leader | Auditor |
|---|---|---|---|---|---|---|
| People, families, groups | edit | edit | view | view | view | – |
| Events and participants | edit | edit | prices only | view | edit | list only |
| Forms | edit | edit | – | – | – | – |
| Finance | edit | edit | edit | – | – | view |
| Mailings and mail log | edit | edit | – | – | – | – |
| Mail accounts and senders | edit | – | – | – | – | – |
| Automations | edit | edit | – | – | – | – |
| Settings | edit | edit | invoice data | – | – | – |
| Privacy and consents | edit | edit | – | – | – | view |
| Safety sheets and health data | edit | – | – | edit | – | – |
| Audit log | edit | – | – | – | – | view |

Only administrators, whatever permissions a role has: roles, API tokens, webhooks, system status, public address, websites for
embedding, time zone and AI settings.

## Protected logins

The e-mail address of a person with an administrative role is the key to the admin interface. Only administrators and the person
itself can change it. This also holds for imports and for merging duplicates. Only administrators can delete such a person.
