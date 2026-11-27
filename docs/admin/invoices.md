# Invoices and payments

Kasmin creates invoices per family, sends them as PDF with a QR code for banking apps, imports bank statements,
matches payments and sends reminders and payment confirmations. Accounting (bookkeeping, balance sheets) is not
part of Kasmin.

Who can use it: the roles *Administrator*, *Board* and *Treasurer*; *Auditors* can view everything but change
nothing. Legal guardians see their family's invoices in the portal.

## 1. Organisation data

*Settings → Organisation and invoices*:

| Setting | Meaning |
|---|---|
| Name, address, e-mail, website | printed on every invoice |
| Footer (legal notice) | e.g. register number, seat, contact – small print at the bottom of every invoice |
| IBAN, BIC, bank | bank account for transfers; the IBAN is checked. Without IBAN no QR code is printed |
| Number format | `{number}` = sequential number, `{year}` = year, e.g. `R{number}` → R516 or `{year}-{number}` → 2027-516 |
| Next number | the next invoice gets this number. After importing old invoices, set it to the next free number |
| Own numbers per year | numbers start with 1 every year; the number format then needs `{year}` |
| Payment term | days until an invoice is due, if the instalment has no own due date |
| Reminder before due date | a friendly reminder this many days before the due date (default 3, 0 = none) |
| Reminders after … days | reminders after the due date, comma-separated (default `7,21`) |
| Reminder fees | optional, off by default: one amount per reminder after the due date, separated by semicolons in the same order, e.g. `0;5` = no fee for the first, 5 € for the second reminder |
| Attach the paid invoice | the payment confirmation contains the invoice marked "paid" |

## 2. Prices, payment plan and discounts (per event)

*Events → edit event*:

- **Price per role** (e.g. child, junior counsellor) in the *Roles* section.
- **Role discount** (optional), e.g. for junior counsellors. It *replaces* the sibling discount, but the person still
  counts as a sibling, so younger siblings keep their position.
- **Counts as sibling**: whether registrations in this role count for the sibling discount (usually yes for
  children and junior counsellors, no for staff).
- **Payment plan** (*Prices, payment plan and sibling discount*): any number of instalments, each either a
  **fixed amount** (e.g. deposit 100 €) or the **remaining amount** (price minus all fixed instalments). Each
  instalment has a fixed due date or "due after … days", can be marked refundable, and has the switch *Apply
  discounts* – usually discounts are applied to the balance only, not to the deposit.
- **Sibling discount**:
  - *Staggered*: the 2nd child gets the amount once, the 3rd child twice and so on, up to the **cap** (e.g. cap 3:
    from the 3rd child on the discount stays at twice the amount).
  - *All siblings the same*: every child gets the amount once at least the minimum number of children take part.
  - *Who pays the full price*: the oldest child (default), the most expensive registration, or by registration order.
- **Reminder days** per event (optional, otherwise the global setting).

Example: price 420 €, deposit 100 € (no discounts), balance = 320 €, staggered 25 € with cap 3. Three siblings pay
deposits of 3 × 100 € and balances of 320 €, 295 € and 270 €.

On the invoice, every discount is **its own position** below the line it belongs to – e.g. *Balance Ben 320.00* and
below it *Sibling discount (2nd child) −25.00* –, so nobody has to work out the amount, and the statistics count the
discounts by kind. Invoices issued before version 0.31 keep their old look (discount in the same line).

- **Prices by age or registration date**: e.g. a lower price for younger children, early bird until a date or a late
  price from a date on. The first line that fits counts; without a fitting line the price of the role.
- **Reduced place**: in the list of participants, *Reduced place* sets the total price of one person (0 = free) with a
  reason. Price and reason can only be set and seen by finance roles; a reduced place gets no sibling discount.
- **Membership fee as instalment**: the kind *Membership fee* uses the yearly fee of a membership type; it is not
  invoiced twice in a year.
- **After payment** (per instalment): confirm the place, add the person to a group, remove the person from a group.

### Cancellation rules

Per event: cancellation conditions (a text) and a cancellation fee; per instalment: refundable or not,
and until how many days before the start. Legal guardians see what applies today **before** they cancel in the
portal. Administrators cancel in the list of participants and enter a reason. The cancellation fee is charged once, when
a refundable instalment is refunded.

### Membership fees per year

*People → Membership types* holds the yearly fee per type. The automation *Membership fees* creates one invoice
per family; fees already invoiced as an instalment of an event of the same year are skipped.

## 3. Invoice run

*Finance → Invoices → Invoice run*: choose the event and the instalment. Kasmin creates one **draft** per family
(all registered, confirmed or offered participants; waitlist entries are not invoiced). The payer is the family's
invoice recipient, otherwise the first legal guardian. Running it again only adds families that have no invoice
for this instalment yet.

Check the drafts (lines, discounts), then select them and use **Issue and send**: each invoice gets its number and
a **structured creditor reference** (RF reference, ISO 11649, e.g. `RF32R2`) and is sent with the template
*Invoice* and the PDF attached. Drafts can still be deleted; issued invoices cannot – they are cancelled.

The PDF contains an **EPC QR code** ("GiroCode"/"Scan to pay") for euro invoices: banking apps fill in recipient,
IBAN, amount and reference automatically.

## 4. Payments and bank import

- **Record payment** on an invoice for cash or single transfers (partial payments possible).
- **Bank import** (*Finance → Payments → Bank import*): upload the CSV or Excel (XLSX) export of your bank account
  and enter which column holds date, amount, reference and (optional) name and IBAN. The mapping is remembered.
  Only incoming payments are imported; the same row imported twice is detected. The file is deleted afterwards.
- Matching:
  - **Matched automatically** – the reference contains the RF reference or the invoice number and the amount is not
    more than the open amount;
  - **Suggestion** – same amount as an open invoice and the payer's last name appears in the name; confirm with one
    click (or select several and *Confirm suggestions*);
  - **Not matched** – assign by hand or *Ignore* (e.g. other income).
- **Several invoices in one transfer**: booked automatically if all references are in the text and the sum fits;
  otherwise use *Split*. What is left stays unassigned.
- **Too much paid**: the rest becomes a **credit** of the family with a refund proposal. Use it for another
  invoice or pay it back.
- **Refunds** (*Finance → Refunds*): amount, payer, IBAN and reason. Make the bank transfer, then mark it as
  *Paid back* – or *Keep as credit*. Kasmin never transfers money itself.
- When an invoice is paid in full, the payer gets the **payment confirmation** (template *Payment confirmation*,
  optionally with the paid invoice attached), and subscribers of the notification topic *payments* are informed.

## 5. Reminders, deadlines, credit notes

- The automation *Payment reminders* ([Automations](automations.md)) runs daily and **asks for approval first**: a friendly
  reminder before the due date (template *Payment reminder (before due date)*) and reminders after the due date
  (template *Payment reminder (overdue)*), each once per invoice. Paid invoices get none; invoices issued within
  the reminder window get no "due soon" reminder.
- A **reminder fee** (optional) is an own invoice attached to the reminder; there is no fee on a fee.
- The automation *Release places of unpaid invoices* proposes to cancel places that are still unpaid some days after the last
  reminder.
- **Extend deadline** changes the due date of a single invoice.
- **Cancel (credit note)** creates a credit note with its own number and the negative amount; the invoice stays for
  traceability with status *cancelled*. What was already paid becomes a credit with a refund proposal.
- **Corrections**: issued invoices never change. When a cancellation, a sibling or a price changes the amount, drafts are
  recalculated at once and the automation *Correct invoices* proposes a credit note and a new invoice. Payments move to
  the new invoice, only the difference is proposed as a refund. The family sees the old and the new document.

## 6. Portal

Legal guardians see under *Invoices* all issued invoices of their family with open amount, due date, reference
and status (open, partially paid, paid, overdue) and can open the PDF.

## 7. Command line

| Command | Purpose |
|---|---|
| `php artisan kasmin:invoices:remind` | queue all reminders that are due at once, without approval (normally the automation does this) |
| `php artisan kasmin:login-link <e-mail>` | print a one-time login link for a user (support, or when mail does not work yet) |
