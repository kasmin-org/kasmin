# Mail

## Accounts and sender profiles

*Communication → Mail accounts*: SMTP for sending (SSL/TLS on port 465 or STARTTLS on 587, certificate always
checked), optionally IMAP. Only accounts with IMAP ("send and receive") can store a copy of every sent mail in a
folder (e.g. `Sent`) and assign replies and bounces to people and families. Rate limits (per minute, hour, day)
follow the limits of your provider – mails above the limit go out in the next run. The *test mail* action checks
the connection.

*Sender profiles* (from name, address, reply-to, account) are chosen per template, so different teams can use
different addresses at the same time. Mails without a profile use the default account (or the server's mail
settings in `.env`).

## Templates

*Communication → Mail templates*: one text per language, edited in a formatted editor; every save is a new
version. Placeholders (sandboxed, no code can be executed; confidential fields such as health data are not
available):

| Placeholder | Content |
|---|---|
| `{{ salutation }}` | "Guten Tag First Last," for adults (formal), "Hallo First," for children – gender-neutral |
| `{{ recipient.first_name }}`, `.last_name`, `.full_name`, `.age` | the recipient |
| `{{ children }}` | list of the children the mail is about (`{% for child in children %}{{ child.first_name }}{% endfor %}`) |
| `{{ family.name }}` | family |
| `{{ event.title }}`, `.starts_at`, `.ends_at`, `.location`, `.meeting_point` | event |
| `{{ registration.status }}`, `.waitlist_position`, `.waitlist_total`, `.child` | registration |
| `{{ invoice.number }}`, `.total`, `.open`, `.paid`, `.due_on`, `.reference`, `.iban`, `.overdue`, `.children` | invoice (invoice, reminder and payment mails), see [Invoices](invoices.md) |
| `{{ organisation.name }}` | your organisation |
| `{{ links.login }}`, `{{ links.portal }}` | personal login link (limited, valid 30 days) and portal |

Allowed: `{% if %}`, `{% for %}`, `{% set %}` and the filters `upper`, `lower`, `capitalize`, `title`, `default`,
`join`, `length`, `first`, `last`, `trim`, `nl2br`. Unknown placeholders block saving.

- **Buttons**: a link alone in a paragraph becomes a button in the colour of the organisation. `{{ links.login }}` can be
  entered as the target of a link.
- **Images** (PNG, JPG, GIF, 2 MB) are added in the editor and embedded in the mail when it is sent.
- **HTML source**: switch per language, for everything the editor cannot do.
- **Preview**: all languages with the data of a real person and event, for computer and phone, and a *test mail to me*. Personal
  links of others are never shown or sent. Mail HTML is always shown in a sandboxed frame.
- **Versions** (in the template): view and restore earlier texts, including the first.
- **Compare with standard**: shows the difference to the shipped text. *New standard* appears if an update brought a new text;
  take it over or keep your own. Updates never change your texts.

## Look

*Settings → Organisation and invoices → Look of the e-mails*: logo, colour and footer of all mails.

## Mailings

*Communication → Mailings*: template, recipients (groups, event participants with status), send to legal
guardians / the person / both, one mail per family. *Prepare* renders all mails and holds them: page through them,
edit single mails if wanted, then *Send*. People who opted out, have no address or a bad address are excluded and listed.

*Review* shows the finished mails one by one, who is left out, and the progress while sending. A mailing can be paused,
continued and cancelled there.

## Bad addresses

*Communication → Bad addresses* lists addresses that do not exist (permanent error or bounce; notices about a delay do not count).
Mailings leave them out; invoices and other single mails are still tried. The mark goes away when the address is changed or with
*Address works again*. Bounces need an account with IMAP.

## Retention

*Settings → Retention → Mail log*: years after which finished mails are deleted (0 = as long as the person exists). Mails with
invoices stay at least as long as the invoices.

## Sending

Mails go through a queue processed by the scheduler at every run (every 5 minutes) (`php artisan schedule:run` via cron, or the web
cron URL, see [hosting](hosting.md)). Failed attempts are retried with increasing waiting times; the mail log under
*Communication → Mail log* shows every mail with its status and can resend it. Legal guardians see the mails sent to
them in the portal.

## Footer links and consent

Every mail contains a link to the recipient's e-mail settings: unsubscribe from the newsletter, no more information
mails, or request deletion of the data. Opening the link changes nothing; a button has to be pressed. Deletion
requests are not executed automatically – administrators are notified and decide what has to be kept by law.

## Notifications for administrators

Topics (new registrations, waitlist, changed information, safety sheets, bounces, deletion requests …) can be
received immediately or as a daily digest (07:00, last 24 hours) or weekly digest (Mondays, last 7 days). They never
contain confidential values.

## Test mode

*Settings → Organisation and invoices → Mail test mode* (administrators; `KASMIN_MAIL_TEST_ADDRESS` for Docker): **every** mail goes to
this address, also login links. The subject shows the real recipient, and the admin interface shows a notice. Make sure you can read
that mailbox, otherwise nobody can log in.

## Attachments

Templates and mailings can have up to five files (PDF, JPG, PNG, 5 MB each); mailings can also use documents of events. If a file is
missing, the mail is not sent and the error is shown in the mail log.

## Approval of large mailings

Mailings with more mails than set under *Settings* (default 50, 0 = never) wait for an approval, like automations: the approvers get a
mail, and nothing is sent before somebody decides.

## Private registrations

If somebody is a recipient only because of a registration made with the own login, the mail goes to that person and not to the legal
guardians (unless *Legal guardians see what their child registers itself* is on).

## Unsubscribing

The link in the footer offers: newsletter, all information mails, single event series and groups, deletion of the data. Mail programs can
unsubscribe with one click. The latest choice counts. Invoices and mails about own registrations are always sent.
