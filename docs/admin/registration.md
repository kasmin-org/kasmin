# Registration: questions, public forms, families

## Questions of an event

1. *Forms → New*: kind **Questions of an event**. Each question is asked *per participant* or *once per family*; consents that
   are marked required must be given.
2. *Events → edit → Roles*: choose the form under **Questions when registering**. Every role can have its own form.

Answers can be changed until **Answers can be changed until** (default: start of the event). They appear on the list
*Answers*, at the participant (*Answers*) and in mails as `{{ registration.answers.<key> }}`. Fields with the sensitivity
*health* or *identity* are encrypted, visible only to the safety role and never part of mails or of that list. The text of
a consent is stored as it was shown.

Before registering, legal guardians see places, price, instalments and cancellation conditions.

## Public forms

*Forms → Public forms*: address `/f/<name>`, title and text, opening and closing time.

**Address.** The field *Address* is the last part of the web address. Below it, the full address appears while you type
(with a button to copy it), exactly as it will be saved: Kasmin suggests it from the title, writes everything in lower case,
spells out umlauts (ä → ae, ß → ss), drops other accents and turns spaces and other characters into a hyphen – so
*Anmeldung für Kinder* becomes `/f/anmeldung-fuer-kinder`. Each address belongs to one form. If you change the address of a
published form, the old one keeps forwarding to it; still change the links on your website.

| Kind | After the confirmation |
|---|---|
| Register for an event | the person is registered in the chosen role (age rule, places and waitlist apply) |
| Application | waits under *Forms → Submissions*; after approval: membership, group and welcome mail. Without approval it is done at once |
| Stay informed | newsletter consent, optionally a group |

Nothing happens before the link in the mail is opened **and** confirmed with the button (valid 3 days). Known people are
recognised by address **and** name; their data is never overwritten. The page never shows whether an address is known.
Submitted data is encrypted and deleted once it is carried out, declined or expired.

## Adults and guests

Roles that are registered by *the person itself* appear in the portal under *My registrations*. With **Guests** in the event, a
participant can invite that many guests: each gets a personal link, registers personally and gets an own invoice.

## Families

*Settings → Organisation and invoices → Families*:

| Setting | Default |
|---|---|
| Legal guardians per family | 5 – guardians invite each other in the portal; administrators are notified |
| Own login from age | 14 – guardians create the login for their child; the child sees its safety sheet but cannot change it |
| Guardianship ends at age | 18 – both sides get a mail; future invoices go to the person |
| What a child registers itself | hidden; or the legal guardians are told that there is a registration; or visible |
| Ask for the newsletter on public forms | on |

## Person page and family page

- *People → (person)* shows the master data and an **overview**: legal guardians, children, families, roles, groups, memberships, events
  with role and status, invoices, consents and the login (address, last login, second factor). Events, invoices and consents appear
  only for roles with the right for them; answers of forms such as the safety sheet stay with the safety role.
- *Families → (family)* holds the address, the members and who receives the invoices, and shows the emergency contacts of the family
  (safety role), its invoices (finance roles) and the last mails to it.

## Duplicates

*People → Duplicates* (administrator, board) lists probable duplicates: same name, similar name and same date of birth, or same
address and similar first name. **Merge** lets you choose each field; everything else moves to the kept record. This cannot
be undone. A merge never gives more rights, and it is refused if both have an invoiced registration for the same event.

## Portal: my data

Legal guardians change phone numbers, e-mail addresses, the family address and the invoice recipient themselves; subscribers of the topic
*profile updates* are notified. A new e-mail address counts after it was confirmed with a link (24 hours); all other addresses of the person
are told. Links sent to the old login address end. The mail with the link and the page it opens both say which address is replaced by
which, or that an address is added.

Who works in the administration and belongs to no family is led to the admin interface: the start page goes there, and the portal says so
instead of asking for a family.

### Gender of a child

When legal guardians add a child, they must choose the gender: female, male or diverse – rooms are planned with it, so "not specified" is
not offered there. *Settings → Organisation and invoices → Families* holds an optional note per gender that appears below the choice as soon as this
gender is picked. Female and male have none by default; diverse has this one, which you can change or empty:

> We separate the children by gender per room. Please contact us directly and tell us in which room the child wants to sleep, so that we
> can plan properly. If there are enough diverse children, they share a room.

*Download data* gives a file with everything stored about the person and the children (Art. 15 and 20 GDPR), without data that is hidden from
the person. Administrators can create it on the person page.

## Cancellations and confirmations

- When a registration is cancelled – by the legal guardians, by the team or because an invoice was not paid – the family gets the mail
  *Confirmation of the cancellation*. An offer that was not answered in time ends with the mail *Offer expired*. Both texts are
  mail templates and can be changed.
- After the event, the **confirmation of participation** is available as a PDF: for the team in the participants of the event
  (*Confirmation of participation*), and for the person or the legal guardians in the portal next to the registration. It names the
  person, the event, the period and the amount paid, e.g. for employers or insurers who refund a part of the costs.

## Spam protection

Public forms have a hidden field for bots, a limit per address and a proof of work: the browser solves a small task (about a second) before it
sends. It needs JavaScript and HTTPS, and no third party is involved. `KASMIN_PROOF_OF_WORK=false` switches it off. For the API, the form
definition contains a `challenge`; send `pow` as `<challenge>:<number>` so that the SHA-256 of this text starts with 14 zero bits.
