# Login and second factor

Kasmin has **no passwords**.

- Everyone logs in with their e-mail address: Kasmin sends a **one-time login link** and a **six-digit code**
  (valid for 30 minutes). The link opens a confirmation page; the login happens only after pressing *Continue*,
  so automatic link scanners of mail providers cannot log anybody in. The code is for the case that the link
  opens in another browser.
- The answer is the same whether or not an address is known (no account enumeration). Codes are blocked after
  five wrong attempts.
- **Administrative roles** (administration, board, finances, safety data, camp leaders, auditors) additionally
  need a **second factor**: an authenticator app (TOTP). It is set up at the first login with a QR code and asked
  after every login.
- Every login, failed login, logout and second-factor step is written to the **audit log**, including the login
  method and the ID of the token used (never the token itself).
