# Dashboard, search and statistics

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

*Reports → Statistics*, per event and role:

- **Age distribution** at the start of the event (or the reference date of the event).
- **Age groups for activities**: splits the participants into 2–5 groups of about the same size, youngest first,
  with age range, birth dates and the names (children born on the same day stay together).
- **Costs and income** (finance roles): invoiced amounts, still open, discounts given, cost items by category,
  result and costs per participant. Enter the cost items on the event page (*Costs*).
- **Discounts** (finance roles): sibling discounts by child position and role discounts, with sums.
- **Participants per event** of every series: returning and new participants.
