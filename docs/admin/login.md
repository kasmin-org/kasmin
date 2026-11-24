# Login

Kasmin has **no passwords**.

- Everyone logs in with their e-mail address: Kasmin sends a **one-time login link** and a **six-digit code**
  (valid for 30 minutes). The link opens a confirmation page; the login happens only after pressing *Continue*,
  so automatic link scanners of mail providers cannot log anybody in. The code is for the case that the link
  opens in another browser.
- The answer is the same whether or not an address is known (no account enumeration). Codes are blocked after
  five wrong attempts.
- **Every login of a person with an administrative role is reported to all administrators by e-mail** (person, time, kind
  of login, IP address, browser). This cannot be switched off. Administrative roles are not remembered on a device, so
  they log in with a fresh link every time.
- **Links in other mails** (invoice, reminder, newsletter) are valid for 30 days and only give a **limited session**:
  start page of the portal, invoices and offered places. The admin interface, safety sheets, emergency contacts and
  registrations need a fresh link or code ("confirm your login").
- The mailbox is the key: whoever can read an administrator's e-mails can log in as this person. Protect these mailboxes
  well (strong password, second factor at the mail provider).
- There is **no second factor** in Kasmin by default. `KASMIN_SECOND_FACTOR=true` switches on an authenticator app for
  administrative roles.
- Every login, failed login, logout and confirmation is written to the **audit log**, including the login
  method and the ID of the token used (never the token itself).
