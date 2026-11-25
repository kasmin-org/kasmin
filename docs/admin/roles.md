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

## Own events only

A role can count for **everything** or for **own events only** (*Settings → Roles → Counts for*). New installations use this for
camp leaders and counsellors; existing installations keep camp leaders as they were until an administrator changes it.

Own events are events

- the role was assigned for (*Person → Roles → Event*), and
- the person takes part in as team member or junior counsellor. Somebody who only registered itself does not count until the team
  confirms it.

They count until 180 days after the end. People with such a role see these events, their participants and the legal guardians of these,
the lists, and the schedule. Registrations a child made itself do not show its legal guardians. Everything else is closed: other events,
families, groups, imports, statistics, mailings, the to-do list, and searching for other people. Health data needs the additional right
*Safety* in the role and then also only counts for the own events.

**Counsellors** have no admin interface. They find *My events (team)* in the portal, with participants, contacts, bus list and
birthdays. Every view is logged, and a link in a mail is not enough to open it.
