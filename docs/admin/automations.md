# Automations and approvals

Automations do recurring work: offering free places to the waitlist, sending invoices and reminders, information
before an event, closing an event. Each automation has a **trigger**, an **action** with settings and an
**execution mode**. Everything is recorded: what was planned, who decided, what happened.

*Automations → Automations* (administrators and board can edit; the list is visible to all administrative roles).

## Execution modes

| Mode | Behaviour |
|---|---|
| **Approval required** (default for everything that sends mails or invoices) | The approvers get an e-mail "Automation X would now do Y" with the list of planned actions. Nothing happens until someone decides. |
| **Automatic** | Runs on its own. Safety limit: if a run has more actions than *Maximum actions without approval* (default 200), it waits for an approval anyway. |
| **Manual only** | Runs only with the button *Run now*. |

**Approvers** are everyone with one of the selected roles (default: board and administrator); one decision is enough.

## Approval by e-mail

- The e-mail contains the planned actions and the buttons **Yes, run it** and **No, cancel**. Each button opens a
  page – **nothing is decided by opening a link** (mail scanners open links automatically). On that page you
  confirm, can untick single actions ("approve with changes") and add a comment.
- Each link is personal, works **once** and expires with the request (default 7 days, `KASMIN_APPROVAL_DAYS`).
  If the second factor is switched on, approvers enter their authenticator code on the page.
- Halfway through, the approvers get one reminder. A request that expires without decision does nothing; the
  approvers are informed (notification topic *Approvals*).
- While a request is open, the scheduler does not create a second one for the same automation and event.

The same decisions are possible in *Automations → Runs and approvals* (the menu shows the number of open requests).
Every run shows its plan and, after execution, the result per action.

## Dry run and "Run now"

- **Dry run** shows what the automation would do now, without changing anything.
- **Run now** starts it at once. If you may approve this automation, it runs directly (you saw the plan);
  otherwise the approvers are asked. Starting it by hand replaces an open approval request of the same automation.

## Triggers

| Trigger | When |
|---|---|
| Only by button | never on its own |
| Daily | once a day after the configured time |
| Days before an event starts | once per event, from *n* days before the start until the start (optionally only one event series) |
| Days after an event ended | once per event, from *n* days after the end, for one week |
| When a place becomes free | after a cancellation, a declined or expired offer |

The scheduler (cron job, see [Hosting](hosting.md)) checks the automations every hour (`kasmin:automations:run`).

## Standard automations

Installed with the German or English defaults, **switched off** except *Payment reminders*,
*Correct invoices* and *Registration rounds* (they only propose, nothing is sent without approval) – review the settings and activate what you need.

| Automation | Action | Default trigger and mode |
|---|---|---|
| Offer free places to the waitlist | the next person on the waitlist gets an offer with a deadline (default 7 days); mail *Place offered from the waitlist* | when a place becomes free · approval |
| Check the waitlist daily | the same, e.g. after the capacity was raised | daily 08:00 · approval |
| Expire unanswered offers | after the deadline the offer is declined and the place goes to the next person | daily 07:30 · automatic |
| Start season | children of the previous event of the same series are registered if their age still fits; those too old are proposed for the next role (e.g. junior counsellor) or for leaving – as a notice to the team | by button · manual |
| Deposit invoices / Balance invoices | invoices of the instalment `deposit` / `balance` per family, issued and sent ([Invoices](invoices.md)) | by button / 90 days before · approval |
| Registration rounds | offers in the rounds of an event ([Event planning](event-planning.md)); **active** | daily · approval |
| Payment reminders | reminders before and after the due date as set under *Organisation and invoices* ([Invoices](invoices.md)); **active** | daily · approval |
| Correct invoices | credit note and new invoice where an issued invoice no longer fits; **active** | daily · approval |
| Release places of unpaid invoices | cancels places still unpaid some days (default 14) after the last reminder | daily · approval |
| Membership fees | yearly membership fees per family | by button · approval |
| Safety sheet reminders | families with a missing or unconfirmed safety sheet, every 7 days, at most 4 times, for events in the next 60 days; then a "still missing" notification to the team | daily 18:00 · approval |
| Information before the event | template *Event information* to all families | 14 days before · approval |
| Close the event | registered participants become "attended" | 1 day after · approval |
| Notice: child turns 14 | one notification per child to the team | daily 07:00 · automatic |

## Offers in the portal

When a place is offered, the legal guardians see it on the child's page with **Accept the place** and
**Decline**. Accepting books the place (or makes it a request if the event confirms registrations by hand);
declining or not answering passes it on to the next person on the waitlist.
