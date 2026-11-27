# Automations and approvals

Automations do recurring work: offering free places to the waitlist, sending invoices and reminders, information
before an event, closing an event. Each automation has a **trigger**, an **action** with settings and an
**execution mode**. Everything is recorded: what was planned, who decided, what happened.

*Events → Automations → Automations* (administrators and board can edit; the list is visible to all administrative roles).

## Execution modes

| Mode | Behaviour |
|---|---|
| **Approval required** (default for everything that sends mails or invoices) | The approvers get an e-mail "Automation X would now do Y" with the list of planned actions. Nothing happens until someone decides. |
| **Automatic** | Runs on its own. Safety limit: if a run has more actions than *Maximum actions without approval* (default 200), it waits for an approval anyway. |
| **Manual only** | Runs only with the button *Run now*. |

**Approvers** are everyone with one of the selected roles (default: board and administrator). One approval is enough by default; with
*Approvals needed*, more people have to approve (what one of them leaves out stays out), and one denial cancels. The other
approvers are told who decided.

## Approval by e-mail

- The e-mail contains the planned actions and the buttons **Yes, run it** and **No, cancel**. Each button opens a
  page – **nothing is decided by opening a link** (mail scanners open links automatically). On that page you
  confirm, can untick single actions ("approve with changes") and add a comment.
- Each link is personal, works **once** and expires with the request (default 7 days, `KASMIN_APPROVAL_DAYS`).
  If the second factor is switched on, approvers enter their authenticator code on the page.
- Halfway through, the approvers get one reminder. A request that expires without decision does nothing; the
  approvers are informed (notification topic *Approvals*).
- While a request is open, the scheduler does not create a second one for the same automation and event.

The same decisions are possible in *Events → Automations → Runs and approvals* (the menu shows the number of open requests).
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
| Weekly, monthly, once on a date | after the configured time; day 31 means the last day in shorter months |
| Status of a registration changes | to one of the chosen statuses, optionally only a series and roles |
| Person joins or leaves a group | the chosen group |
| New person, field of a person changes | not for imports and demo data |
| When a place becomes free | after a cancellation, a declined or expired offer |

The scheduler (cron job, see [Hosting](hosting.md)) checks the automations every hour (`kasmin:automations:run`).

## Own automations

*Events → Automations → Own automation*. They are always created switched off; check them with the dry run first.

1. **Trigger** (see above).
2. **For whom**: the person of the trigger (for changes), participants of the event, members of a group, or all people that fit
   the conditions. Without conditions, *all people* means nobody.
3. **Conditions**: the same rules as for groups (age, membership, role, group, number of events), and *only once per person*.
4. **What happens**, step by step: send a mail, add to or remove from a group, change the status of the registration (confirmed,
   attended, not shown up, cancelled – never past the waitlist), a task for the team (notification topic *Tasks from automations*),
   call webhooks (`automation.custom`), create an invoice draft (only people with the right to make invoices can add this step).

Automations can start other automations; chains are cut after three steps, so nothing runs in circles. A problem in an automation
never stops the change that started it. Shipped automations can be switched off, but not deleted.

## Schedule

*Events → Schedule* shows everything around an event: its dates, rounds, due dates, automations before and after it (also those that
are switched off) and the runs so far. The dashboard shows the runs of the next 14 days.

## New season

*Events → New season* copies an event and shows before anything is created what will happen: dates, places, prices, schedule, and who
*Start season* would register. The season can be started in the same step; people who may not approve this only send the request.

## Approvals by e-mail

Approvers get **one e-mail with everything that waits for them** instead of one e-mail per request: all requests that came in since the last
summary, each with what would happen and its own buttons *Yes, run it* / *No, cancel*. A request that is still open half-way to its end is
named once more in the next summary.

*Administration → Global settings → Approvals: summary by e-mail at*: the time of day (default 07:00). Several times are possible
(`07:00, 17:00`). Leave the field empty to get every request at once in an e-mail of its own, as before. Open approvals are always
visible on the dashboard and under *Events → Automations → Runs and approvals*, also before the summary is sent.

## Standard automations

Installed with the standard content, **switched off** except *Payment reminders*,
*Correct invoices* and *Registration rounds* (they only propose, nothing is sent without approval) – review the settings and activate what you need.

| Automation | Action | Default trigger and mode |
|---|---|---|
| Offer free places to the waitlist | the next person on the waitlist gets an offer with a deadline (default 7 days); mail *Place offered from the waitlist* | when a place becomes free · approval |
| Check the waitlist daily | the same, e.g. after the capacity was raised | daily 08:00 · approval |
| Expire unanswered offers | after the deadline the offer is declined and the place goes to the next person | daily 07:30 · automatic |
| Start season | children of the previous event of the same series are registered if their age still fits; those too old are proposed for the next role (e.g. junior counsellor) or for leaving – as a notice to the team. Children on the waitlist of the previous event stay on the waitlist of the new one, with the date of their first registration, so nobody loses the position | by button · manual |
| Deposit invoices / Balance invoices | invoices of the instalment `deposit` / `balance` per family, issued and sent ([Invoices](invoices.md)) | by button / 90 days before · approval |
| Registration rounds | offers in the rounds of an event ([Event planning](event-planning.md)); **active** | daily · approval |
| Payment reminders | reminders before and after the due date as set under *Invoice settings* ([Invoices](invoices.md)); **active** | daily · approval |
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
