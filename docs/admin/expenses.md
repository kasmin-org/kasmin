# Expense claims and advances

Somebody of the team pays for the organisation – craft material, train tickets, the shopping for the camp – and gets
the money back. Kasmin takes the receipt (a photo from the phone or a PDF), lets the treasurer approve it, pays it out
by bank transfer or in cash and books every step in the [accounting](accounting.md). Somebody who waives the refund
makes a donation. An **advance** is money handed out before; it is settled with the receipts.

Who can use it: in the portal every person with a role in the team (e.g. *Counsellor*) and everybody who already has a
claim or an advance – each person sees only their own. In the admin interface *Accounting → Expense claims* and
*Advances* for the roles with the right *Finance* (*Administrator*, *Board*, *Treasurer*; *Auditors* only read). The
accounting needs a first period (*Accounting → Settings → Periods*).

## 1. Settings

*Accounting → Settings → Invoice settings → Expense claims*:

| Setting | Meaning |
|---|---|
| Approval by a second person | four-eyes principle: nobody approves a claim they entered or that is their own |
| Flat rates | one per line as `name: amount`, e.g. `Travel allowance per event: 90` or `Mileage: 0.42`. A flat rate needs no receipt and is claimed once per person and event; with a quantity (e.g. kilometres) the amount is rate × quantity. Empty by default |

The finance roles get a mail about new claims under *My notifications → Expense claims* (immediately or as a digest).

## 2. Submitting in the portal

In the portal the link **Expenses and advances** opens the page of the person:

1. **Date of the receipt**, **amount** and **what for** – or a flat rate and the quantity.
2. Optional: the **event** (its cost centre is used), the **category** (expense account, default *Other expenses*),
   the cost centre (when cost centres are switched on) and the open **advance** the receipt settles.
3. **Receipt**: on a phone the field offers the camera and the files; photos and PDFs up to 10 MB.
4. *I waive the refund* makes the amount a donation once it is approved.

Below the form the person sees all own claims with their status (submitted, approved, rejected with the reason, paid,
donated) and the own advances with what is still open. The **IBAN for refunds** is entered once; it is stored encrypted,
shown only masked (`AT** **** **** **** 5864`) and never written into mails or the audit log. A claim that is
submitted or approved can still be turned into a donation (*Waive the refund*).

## 3. Entering centrally

For somebody who does not use the portal: *Accounting → Expense claims → Enter expense claim* with person, date, amount
and purpose (or flat rate), account, event, cost centre, advance and optionally the receipt and the IBAN of the person.
From there it is the same as a claim from the portal; the list marks it *entered centrally*.

## 4. Approving and rejecting

The list shows the open claims (submitted and approved) first; the filter shows the others. *Receipt* opens the photo or
PDF. **Approve** can still correct the expense account and the cost centre and books:

- the expense on its account and cost centre, against **Expenses to pay back** of the person;
- for a receipt against an advance: against **Advances** of the person instead (status *settled with the advance*).

The cost centre is the one of the event (or of its series), else the one chosen, else the standard cost centre of the
account, else the standard cost centre. The booking gets the date of the receipt – or today, if that period is closed.
**Reject** needs a reason; it goes to the person by mail. The person gets a mail at the approval and at the payout as
well.

## 5. Paying out

- **Pay out** a single claim or select several and **Pay out selected**: choose the bank or cash account and the day.
  The claims are booked (*Expenses to pay back* to the bank or cash account) and marked as paid.
- From a **bank account** Kasmin downloads a **SEPA file** (pain.001.001.09) to upload in online banking: one transfer
  per claim, or with *One transfer per person* the sum per person. The debtor is the IBAN of the bank account (or the
  IBAN of the invoice settings) and the name of the organisation. Every person needs an IBAN for refunds; Kasmin names
  who has none. The IBAN is copied into the claim at the payout.
- **Payment code**: for one claim a QR code for banking apps (IBAN, amount and text filled in); then *Pay out* to book.
- From a **cash account** no IBAN is needed.

## 6. Waiving as a donation

*Waived (donation)* on an approved claim – or *Waive the refund* by the person in the portal – books the amount from
*Expenses to pay back* to **Donations** in the cost centre of the expense. Expense and donation even out there; the
donation stays visible with the person (for a donation confirmation later).

## 7. Advances

*Accounting → Advances → Hand out advance*: person, amount, bank or cash account, day and purpose. The person submits
the receipts against the advance (portal or centrally). Once all receipts are decided, **Settle**: the person gives back
exactly what is still open, into a bank or cash account. If the receipts are more than the advance, the difference
becomes an approved claim of the person and is paid out like every other.

## 8. Bookings, step by step

An example with invented numbers: Kim buys craft material for the summer camp, Robin waives the postage, Sam gets
300.00 in cash for the shopping of the camp.

| Step | Debit | Credit | Amount |
|---|---|---|---|
| Kim's claim approved | Material (Summer camp) | Expenses to pay back (Kim) | 61.70 |
| Paid out with the SEPA file | Expenses to pay back (Kim) | Bank | 61.70 |
| Robin's claim approved | Postage (Summer camp) | Expenses to pay back (Robin) | 18.30 |
| Robin waives the refund | Expenses to pay back (Robin) | Donations (Summer camp) | 18.30 |
| Advance to Sam | Advances (Sam) | Cash | 300.00 |
| Sam's receipt for food approved | Food (Summer camp) | Advances (Sam) | 184.50 |
| Sam's bus tickets approved | Travel (Summer camp) | Advances (Sam) | 43.00 |
| Sam gives back the rest | Cash | Advances (Sam) | 72.50 |

Afterwards *Expenses to pay back* and *Advances* are 0 again; the summer camp has costs of 307.50 and a donation of
18.30, a result of −289.20. Had Sam's receipts been 325.00, the 25.00 above the advance would become a claim of Sam (*Advances* to *Expenses
to pay back*, 25.00) and be paid out like any other (*Expenses to pay back* to *Cash*, 25.00).

REST and MCP: see [REST API](api.md) (`/expense-claims`, `/advances`). An AI assistant never gets the SEPA file and sees
IBANs only masked.
