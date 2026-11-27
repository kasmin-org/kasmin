# Login

Kasmin has **no passwords**.

- Everyone logs in with their e-mail address: Kasmin sends a **one-time login link** and a **code of six characters**
  such as `K7M-2QX` (valid for 30 minutes). The link opens a confirmation page; the login happens only after pressing *Continue*,
  so automatic link scanners of mail providers cannot log anybody in. The code is for the case that the link
  opens in another browser.
- The code uses [Base32H](https://base32h.github.io): digits and letters without I, O, S and U, so it can be read aloud
  without mix-ups. Typing is forgiving: lower case, with or without the hyphen, and O for 0, I for 1, S for 5 and U
  for V all work. Six characters give about a billion possibilities.
- The answer is the same whether or not an address is known (no account enumeration).
- **Protection against guessing:** wrong codes are counted per person, over all codes that person has open. After
  five wrong codes within 30 minutes, entering a code is blocked for that person for 30 minutes – the link in the
  mail still works. Only the code of the newest login mail is valid (links of older mails keep working until they
  expire), and an address gets at most three login or sign-up mails within 15 minutes, from wherever the requests
  come.
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

**Devices.** *My data* in the portal lists where the person is logged in (kind of browser, IP address, last use). *Log out everywhere
else* ends all other logins, also remembered ones.
