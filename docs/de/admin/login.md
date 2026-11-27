# Anmelden

Kasmin hat **keine Passwörter**.

- Alle melden sich mit ihrer E-Mail-Adresse an: Kasmin sendet einen **einmaligen Anmeldelink** und einen **Code aus sechs
  Zeichen** wie `K7M-2QX` (30 Minuten gültig). Der Link öffnet eine Seite zur Bestätigung; angemeldet wird erst nach einem Klick auf *Weiter*.
  So können automatische Linkprüfer von Mailanbietern niemanden anmelden. Der Code ist für den Fall gedacht, dass sich der Link
  in einem anderen Browser öffnet.
- Der Code verwendet [Base32H](https://base32h.github.io): Ziffern und Buchstaben ohne I, O, S und U, damit man ihn ohne
  Verwechslung vorlesen kann. Beim Eintippen ist Kasmin nachsichtig: Kleinbuchstaben, mit oder ohne Bindestrich, O statt 0, I statt
  1, S statt 5 und U statt V – alles wird verstanden. Sechs Zeichen ergeben rund eine Milliarde Möglichkeiten.
- Die Antwort ist dieselbe, ob eine Adresse bekannt ist oder nicht (so lässt sich nicht herausfinden, wer ein Konto hat).
- **Schutz gegen Erraten:** Falsche Codes werden je Person gezählt, über alle offenen Codes dieser Person. Nach fünf falschen Codes
  in 30 Minuten ist die Eingabe eines Codes für diese Person 30 Minuten gesperrt – der Link in der E-Mail funktioniert weiter. Gültig
  ist nur der Code der neuesten Anmeldemail (die Links älterer Mails funktionieren bis zu ihrem Ablauf), und an eine Adresse gehen
  höchstens drei Anmelde- oder Registrierungsmails in 15 Minuten, egal von wo die Anfragen kommen.
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
