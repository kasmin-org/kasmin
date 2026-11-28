# Accounting

Kasmin keeps a double-entry bookkeeping: every booking has lines with debit and credit, and both sides add up to the
same amount. Invoices, payments, refunds and expenses are booked automatically; everything else is typed in under
*Accounting → Bookings*. You find everything in the area **Accounting**.

## Basic terms

| Term | Answers | Example |
|---|---|---|
| **Account** | *what* was booked | Food, Bank, Membership fees |
| **Cost centre** | *what for* | Summer camp, Alumni weekend, General |
| **Fund** | *whose money it is* over the years | Summer camp fund, General fund |

Accounts of the type *assets* (bank, cash, receivables) say **where the money is**; funds are part of the **equity** and
say **what it is for**. Both sides are equal, but independent of each other – the money of the summer camp fund can be
partly in the bank and partly in the cash box:

| Where is it? | | What is it for? | |
|---|---|---|---|
| Bank | 6,000 | Summer camp fund | 5,200 |
| Cash | 200 | Weekend fund | 1,200 |
| Open invoices | 300 | | |
| Expenses to pay back | −100 | | |
| **Total** | **6,400** | **Total** | **6,400** |

That is also why the balance of a fund is not the balance of an account: open invoices and expenses that are not paid
back yet count as well.

## Periods

A period is a span of dates, e.g. 19 October to 18 October, so that a membership fee invoiced in autumn belongs to the
year of the next summer camp. *Accounting → Settings → Periods → New period*: the first period starts the accounting; every
further one follows the one before without a gap and takes over its active cost centres and allocation keys. Documents
after the last period make the next one by themselves (at most two years ahead).

The dates of an **open** period can be changed later (*Change name and dates*): bookings belong to a period by their date, so they
move along, and the neighbouring period moves with it. Kasmin shows beforehand how many bookings will belong to another
period. Opening values are never stored but computed, so they are always right.

A **closed** period is locked: nothing with a date in it can be booked, changed or deleted. *Reopen* lists the periods
that are reopened with it – every later closed period, because their opening values change – and asks for a reason.

## Bookings

*Accounting → Bookings → New booking*: date, text, receipt number and receipt (photo or PDF), and any number of lines –
e.g. one shop receipt for food and material paid in cash:

| Account | Debit | Credit |
|---|---|---|
| Food | 30.00 | |
| Material | 12.50 | |
| Cash | | 42.50 |

Income and expenses always get a cost centre; without one, the standard cost centre of the account or the standard cost
centre is taken. Numbers run over all years. A booking is never changed or deleted: **Reverse** books the same amounts on the
opposite sides, so everything stays traceable.

## Cost centres

*Global settings → Use cost centres* switches them on. Internally every booking always has a cost centre; there is always one
standard cost centre, *General*. Switched off, everything is booked on it and the field is hidden. It can be switched off again
only while there is just one cost centre and it carries its result.

A cost centre is **one over all years** and is **active or not per period**: a new period takes over the active ones, inactive
ones disappear from the selection but keep their bookings in earlier periods, and a project that comes back is simply activated
again – its fund carries on. Every cost centre is of one kind:

- **Carries its result**: at the closing its result goes into its fund.
- **Is allocated**: it has no fund; at the closing its costs are spread over cost centres that carry their result, by the
  allocation key of the period. Allocations never go to a cost centre that is allocated itself.

**Deleting** is not possible for the standard cost centre, while a key of an open period gives it an allocation, or while it has
bookings in closed periods (reopen them, or deactivate it instead). Its bookings are moved to another cost centre; typed-in bookings
in open periods can instead be deleted for good after typing the sentence Kasmin shows, e.g. *3 bookings shall be deleted for good.*
A backup is made before.

## Funds

Funds are also called **reserves** (in German *Rücklagen*, in an Austrian balance sheet often *zweckgebundene Rücklagen*). Think of
them as **pots**: each fund collects the results of the cost centres that carry their result into it, over the years, and money
moved to it by a decision of the board (*Move money to another fund*, e.g. to put money aside for new tents). A fund can become
negative after a year with a loss; the closing then shows a warning.

Funds are **optional**: *Global settings → Use funds* (only with cost centres). Switched off, all results go to the *General fund*,
the free equity. A fund can be assigned to a bank or cash account; Kasmin then compares the account with the sum of its funds and
shows the difference. A fund can be deleted when it holds nothing and no cost centre uses it.

## Allocation, step by step

1. **The administration has costs.** During the year there are costs that belong to no project: website, software, bank fees,
   postage – say **2,000.00**. They are booked on the cost centre *Administration*.
2. **The administration has no fund**, so it is *allocated* and must be at 0 after the closing: its costs are spread over the
   projects by the **key** of the period (*Periods → Allocation keys*).
3. **A key can hold fixed amounts.** For example: *the weekend pays a fixed 300.00, the youth group a fixed 100.00, the rest goes
   to the summer camp.* In a normal year: weekend 300.00, youth group 100.00, summer camp 2,000.00 − 400.00 = 1,600.00.
4. **Fixed amounts come first, then percentages of what is left; what is left then goes to the one "rest" cost centre or is
   shared in proportion to the income** of the cost centres marked so.
5. **The costs are smaller than the fixed amounts.** If the administration cost only **200.00**, the fixed amounts (400.00) would
   be more than the costs – the administration would end in the plus and the weekend would pay for the camp. Kasmin therefore
   **cuts every fixed amount in the same proportion** (here to one half: weekend 150.00, youth group 50.00, camp 0.00) and says so
   in its own message when closing. If you do not want that, cancel and change the key of this period. The next period takes over
   the key **as it was set**, not the cut amounts.
6. **The administration is in the plus** (e.g. after a large donation of 500.00 more than its costs): the fixed amounts pay nothing
   and get nothing; the surplus goes to the percentages and the rest – here the camp gets +500.00.

| Costs of the administration | Weekend (fixed 300) | Youth group (fixed 100) | Summer camp (rest) |
|---|---|---|---|
| 2,000.00 | 300.00 | 100.00 | 1,600.00 |
| 400.00 | 300.00 | 100.00 | 0.00 |
| 200.00 | 150.00 (cut) | 50.00 (cut) | 0.00 |
| surplus 500.00 | 0.00 | 0.00 | −500.00 (gets 500.00) |

Amounts are whole cents; what is left over by rounding goes to the largest share, so the sum is always exact. These examples are
tests of Kasmin.

## Budget

*Periods → Budget* takes the planned result of every cost centre that is active in the period: income minus costs, so planned costs
are negative. Example: the summer camp plans 1,500.00 surplus, the administration −300.00. The report *Cost centres* shows the budget
next to the result after the allocation and the difference (result − budget; positive = better than planned). A budget books nothing,
so it can be changed at any time, also in a closed period. An empty field means no budget.

## Closing

*Periods → Close* first shows a preview: per cost centre the result, the allocation and the result after it; per fund the value
before, the result and the value after; and every warning (cut fixed amounts, a cost centre with income that is in no key, a fund
that becomes negative). Errors (an allocated cost centre without a key, an earlier period still open) block the closing. Closing books
the allocations, carries every result into its fund, sets income and expenses back to 0 and locks the period. Periods are closed in
the order of their dates.

## Bank and cash accounts

*Accounting → Bank and cash accounts* shows the balance of every money account. Type in the balance of the bank statement to compare,
and see the funds assigned to the account. *Ledger* opens all lines of the account with the running balance.

## Reports

*Accounting → Reports*: balance sheet on a day, income statement of a period (optionally of one cost centre), cost centres (income,
expenses, allocation, result, budget and difference), statement of funds (opening, result, transfers, end – provisional while the
period is open), ledger of an account and open items per person. Every report can be downloaded as Excel, CSV or PDF.
