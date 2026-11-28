# Dashboard, search and statistics

## Navigation

The admin interface has five **areas**, shown at the top next to the logo: **People**, **Events**, **Communication**,
**Accounting** and **Administration**. The navigation on the left shows only the pages of the open area:

| Area | Pages |
|---|---|
| People | People, Families, Groups, Membership types; *Maintenance:* Duplicates, Import people, Imports |
| Events | Events, Event series, New season, Lists, Statistics; *Forms:* Forms, Public forms, Submissions; *Automations:* Automations, Runs and approvals; *Templates:* Event templates |
| Communication | Mailings, Mail templates, Mail log, Bad addresses, Consents and opt-outs |
| Accounting | Invoices, Payments, Refunds, Invoice archive; *Settings:* Invoice settings |
| Administration | Global settings, Organisation, Roles, E-mail sending, Deletion and retention, Audit log, API tokens, Webhooks; *System:* System status, Backups, Update |

The logo or **Dashboard** at the top left opens the start page. An area is shown only to people who may open at least
one of its pages – *Administration* only to those with a right for one of its pages; the global settings, the system
pages, backups and the update only to administrators. *My notifications* is in the user menu at the top right. On a
phone, the areas are at the top of the menu.

## Dashboard

The start page of the admin interface shows:

- **One block per event series** with its next event (or the latest, if none is coming): days until the start,
  registrations, waitlist, places still open before the waitlist starts, free reserve places, places per role, the
  last three registrations and – for finance roles – invoices paid / open / overdue with sums.
- **To do**: approvals waiting, registration requests to confirm, registrations of the last 7 days, deletion
  requests, undeliverable mails, payments to assign. Every line links to the list.
- **Finance** (finance roles): open and overdue invoices, payments to assign, last bank import.
- **Safety sheets** of the next events: confirmed / missing – counts only, no content.
- **Next automation runs** in the coming 14 days and failed runs.

## Global search

`Ctrl+K` (Mac: `⌘K`) or the search field at the top finds people (also by e-mail address), families, events and
invoices (by number, payment reference or payer).

## View as legal guardian

On a person's page, administrators (only this role, because safety sheets are visible) can use **View as legal
guardian**. It opens the portal exactly as this person sees it – read only; nothing
can be changed in the preview. A banner shows the preview and ends it. Start and end are recorded in the audit log.

## Statistics

*Events → Statistics*, per event and role:

- **Age distribution** at the start of the event (or the reference date of the event).
- **Age groups for activities**: splits the participants into 2–5 groups of about the same size, youngest first,
  with age range, birth dates and the names (children born on the same day stay together). **Export** next to the
  number of groups opens a menu: tick the fields per person (group, first name, last name, age at the start, date of
  birth, gender, role) and choose Excel, CSV or PDF. It needs the right to see people and is recorded in the log;
  confidential data such as health information cannot be exported here.
- **Costs and income** (finance roles): invoiced amounts, still open, discounts given, cost items by category,
  result and costs per participant. Enter the cost items on the event page (*Costs*).
- **Discounts** (finance roles): sibling discounts by child position and role discounts, with sums.
- **Participants per event** of every series: returning and new participants. *Returning* means: was already at an
  earlier event of the same series, in any year – not only the year before. The question mark next to the word shows
  this when the mouse is over it (on a phone: tap it).
