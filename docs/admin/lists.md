# Lists, exports and saved views

## Lists of an event

*Events → Lists*: choose the event, the list and, if needed, one role. Every list can be opened as PDF (for printing), XLSX
or CSV. Only people who take part are listed (registered, confirmed, attended).

| List | Content | Visible for |
|---|---|---|
| Participants | name, date of birth, age, role, status, legal guardians | administrator, board, camp leader, safety officer |
| Contacts | legal guardians with phone and e-mail | same |
| Bus list | legal guardian with phone, boxes to tick for outward and return journey | same |
| Birthdays | birthdays during the event | same |
| Answers | non-confidential answers of other forms, e.g. T-shirt sizes | same |
| Safety sheets still missing | status and phone number, no health data | same |
| Emergency contacts, kitchen, medical needs, medication, insurance, swimmers, consents | from the safety sheet | **administrator and safety officer only** |
| Changes since the last event | field and new value | **administrator and safety officer only** |

- Which field appears on which list is set per field in the form builder (*list use*).
- Someone **without a safety sheet stays on the kitchen and medical lists as "Still missing"** – so a missing sheet never
  looks like "no allergies".
- Values that changed since the person's last event are highlighted.
- From the age limit of the safety sheet on, people no longer appear on these lists.
- Every view and export of a confidential list is logged (without the values).

## Camp folder

*Events → Lists → Camp folder*: one page per child with emergency contacts and safety sheet, marked confidential and
logged. The address `/admin/events/<event>/camp-folder/<person>` gives the page of one child.

## Exports

The button *Export* on people, families, groups, events, invoices, payments, refunds, consents, mailings, mail log and audit
log exports what the table shows – with the current search, filters and columns. XLSX and CSV up to 5000 entries, PDF up to
1000. Exports are logged. Entered text is never executed as a spreadsheet formula.

## Saved views and filters

- *Views → Save this view* keeps search, filters, sorting and columns under a name; *Visible to everyone* shares it with all
  who can open the page.
- People can be filtered by age at a date and with any combination of conditions (AND, OR, NOT): name, date of birth, groups,
  families, membership type, role, event and status at an event. Form answers are encrypted and cannot be filtered.
