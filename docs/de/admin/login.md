# Anmelden

Kasmin hat **keine Passwörter**.

- Alle melden sich mit ihrer E-Mail-Adresse an: Kasmin sendet einen **einmaligen Anmeldelink** und einen **sechsstelligen Code**
  (30 Minuten gültig). Der Link öffnet eine Seite zur Bestätigung; angemeldet wird erst nach einem Klick auf *Weiter*.
  So können automatische Linkprüfer von Mailanbietern niemanden anmelden. Der Code ist für den Fall gedacht, dass sich der Link
  in einem anderen Browser öffnet.
- Die Antwort ist dieselbe, ob eine Adresse bekannt ist oder nicht (so lässt sich nicht herausfinden, wer ein Konto hat). Codes
  werden nach fünf falschen Versuchen gesperrt.
- **Jede Anmeldung einer Person mit Verwaltungsrolle wird allen Administratorinnen und Administratoren per E-Mail gemeldet**
  (Person, Zeit, Art der Anmeldung, IP-Adresse, Browser). Das lässt sich nicht abschalten. Verwaltungsrollen werden auf einem Gerät
  nicht gemerkt. Diese Personen melden sich also jedes Mal mit einem neuen Link an.
- **Links in anderen Mails** (Rechnung, Erinnerung, Newsletter) gelten 30 Tage und geben nur eine **eingeschränkte Sitzung**:
  Startseite des Portals, Rechnungen und angebotene Plätze. Die Verwaltung, Sicherheitsblätter, Notfallkontakte und
  Anmeldungen brauchen einen neuen Link oder Code („Anmeldung bestätigen“).
- Das Postfach ist der Schlüssel: Wer die E-Mails einer Administratorin oder eines Administrators lesen kann, kann sich als diese
  Person anmelden. Schützen Sie diese Postfächer gut (starkes Passwort, zweiter Faktor beim Mailanbieter).
- Kasmin hat standardmäßig **keinen zweiten Faktor**. `KASMIN_SECOND_FACTOR=true` schaltet für Verwaltungsrollen eine
  Authenticator-App ein.
- Jede Anmeldung, fehlgeschlagene Anmeldung, Abmeldung und Bestätigung wird im **Audit-Protokoll** festgehalten, mit der Art der
  Anmeldung und der ID des verwendeten Tokens (nie mit dem Token selbst).

**Geräte.** *Meine Daten* im Portal zeigt, wo die Person angemeldet ist (Art des Browsers, IP-Adresse, letzte Verwendung). *Überall
sonst abmelden* beendet alle anderen Anmeldungen, auch gemerkte.
