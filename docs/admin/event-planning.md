# Templates, copies, dates, public pages and rounds

## Templates and copies

- *Events → Event templates*: everything a new event starts with (roles, ages, prices, payment plan, cancellation rules,
  questions, documents, rounds). Three types are installed with the defaults.
- *Events → From template* creates an event; *Copy* at an event makes the next one; *Save as template* keeps it as a type.
- All dates move by the same number of days. People, invoices and costs are never copied. A new event is a draft.

## Status by date

*Announce on*, *Registration opens* and *Registration closes* change the status, and so do the start (*running*) and the end
(*finished*). Each date acts once: a change by hand afterwards stays. Every change is in the audit log.

## Documents and image

Documents (PDF, JPG, PNG, up to 10 MB) are visible for participants and their legal guardians in the portal, or for everyone on
the public page. People on the waitlist do not see them. Files are stored privately.

## Public page and embedding

*Public page* shows `/events/<id>`: dates, place, prices, places, public documents and the registration buttons. Drafts and
archived events are never shown.

To show the page or a public form on your website, add the website under *Administration → Global settings → Websites that
may embed public pages* and use `?embed=1`:

```html
<iframe src="https://camp.example.org/f/alumni?embed=1" style="width:100%;height:900px;border:0"></iframe>
```

All other pages can never be shown in frames. The registration of children opens outside the frame, because it needs a login.

## Registration rounds

Without rounds, registration depends on the status only. With rounds for a role:

| Round | What happens |
|---|---|
| Returning participants | people who were in this role at the previous event of the series get an offer (age must fit) |
| Selection | people with at least *n* events as a child in the series get offers: previous year first, then number of events, then earliest registration; optionally only members of a group |
| Waitlist | from its start on, free places are offered to the waitlist |
| Open for all | places are taken directly; before that, new registrations go to the waitlist |

The automation *Registration rounds* runs daily and **asks for approval** before anything is sent; every family gets one mail.

- *Answer in the portal*: accept or decline until the round closes.
- *Paying the first instalment*: the invoice is sent with the offer, paying it takes the place. Unpaid places are released three
  days after the deadline, because transfers take time; the invoice is then proposed for correction.

People who declined get no second offer. *Participants → Offer a place* offers out of turn, with a reason.
