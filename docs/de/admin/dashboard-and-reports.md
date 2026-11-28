# Dashboard, Suche und Statistik

## Navigation

Die Verwaltung hat fünf **Bereiche**, oben neben dem Logo: **Personen**, **Veranstaltungen**, **Kommunikation**,
**Buchhaltung** und **Administration**. Die Navigation links zeigt nur die Seiten des geöffneten Bereichs:

| Bereich | Seiten |
|---|---|
| Personen | Personen, Familien, Gruppen, Mitgliedsarten; *Pflege:* Doppelte Einträge, Personen importieren, Importe |
| Veranstaltungen | Veranstaltungen, Veranstaltungsreihen, Neue Saison, Listen, Statistik; *Formulare:* Formulare, Öffentliche Formulare, Einsendungen; *Automatisierungen:* Automatisierungen, Läufe und Freigaben; *Vorlagen:* Veranstaltungsvorlagen |
| Kommunikation | Aussendungen, Mailvorlagen, Mailprotokoll, Unzustellbare Adressen, Einwilligungen und Abmeldungen |
| Buchhaltung | Rechnungen, Zahlungen, Rückzahlungen, Rechnungsarchiv; *Einstellungen:* Rechnungseinstellungen |
| Administration | Globale Einstellungen, Organisation, Rollen, E-Mail-Versand, Löschung und Aufbewahrung, Audit-Protokoll, API-Tokens, Webhooks; *System:* Systemstatus, Datensicherungen, Aktualisierung |

Das Logo oder **Dashboard** oben links öffnet die Startseite. Einen Bereich sehen nur Personen, die mindestens eine seiner Seiten öffnen
dürfen – *Administration* nur, wer für eine ihrer Seiten ein Recht hat; die globalen Einstellungen, die Systemseiten, Datensicherungen und
die Aktualisierung nur Administratorinnen und Administratoren. *Meine Benachrichtigungen* ist im Benutzermenü oben rechts. Auf dem Telefon
stehen die Bereiche oben im Menü.

## Dashboard

Die Startseite der Verwaltung zeigt:

- **Einen Block pro Veranstaltungsreihe** mit ihrer nächsten Veranstaltung (oder der letzten, wenn keine bevorsteht): Tage bis zum Beginn,
  Anmeldungen, Warteliste, freie Plätze vor der Warteliste, freie Reserveplätze, Plätze pro Rolle, die
  letzten drei Anmeldungen und – für Finanzrollen – Rechnungen bezahlt / offen / überfällig mit Summen.
- **Zu erledigen**: wartende Freigaben, Anmeldeanfragen zu bestätigen, Anmeldungen der letzten 7 Tage, Löschanfragen,
  unzustellbare E-Mails, Zahlungen zuzuordnen. Jede Zeile führt zur Liste.
- **Finanzen** (Finanzrollen): offene und überfällige Rechnungen, Zahlungen zuzuordnen, letzter Bankimport.
- **Sicherheitsblätter** der nächsten Veranstaltungen: bestätigt / fehlen – nur Zahlen, kein Inhalt.
- **Nächste Automatisierungen** in den kommenden 14 Tagen und fehlgeschlagene Läufe.

## Globale Suche

`Strg+K` (Mac: `⌘K`) oder das Suchfeld oben findet Personen (auch nach E-Mail-Adresse), Familien, Veranstaltungen und
Rechnungen (nach Nummer, Zahlungsreferenz oder zahlungspflichtiger Person).

## Als Erziehungsberechtigte ansehen

Auf der Seite einer Person können Administratorinnen und Administratoren (nur diese Rolle, weil Sicherheitsblätter sichtbar sind) **Als
Erziehungsberechtigte ansehen** verwenden. Das öffnet das Portal genau so, wie diese Person es sieht – nur zum Lesen; in der Vorschau
kann nichts geändert werden. Ein Balken zeigt die Vorschau an und beendet sie. Beginn und Ende stehen im Audit-Protokoll.

## Statistik

*Veranstaltungen → Statistik*, pro Veranstaltung und Rolle:

- **Altersverteilung** zu Beginn der Veranstaltung (oder zum Stichtag der Veranstaltung).
- **Altersgruppen für Aktivitäten**: teilt die Teilnehmenden in 2–5 etwa gleich große Gruppen, die Jüngsten zuerst,
  mit Altersspanne, Geburtsdaten und den Namen (Kinder, die am selben Tag geboren sind, bleiben zusammen). **Export** neben der
  Anzahl der Gruppen öffnet ein Menü: Felder je Person ankreuzen (Gruppe, Vorname, Nachname, Alter bei Beginn,
  Geburtsdatum, Geschlecht, Rolle) und Excel, CSV oder PDF wählen. Dafür braucht es das Recht, Personen zu sehen, und der Export wird protokolliert;
  vertrauliche Daten wie Gesundheitsangaben lassen sich hier nicht exportieren.
- **Kosten und Einnahmen** (Finanzrollen): verrechnete Beträge, davon noch offen, gewährte Ermäßigungen, Kostenpositionen nach Kategorie,
  Ergebnis und Kosten pro teilnehmender Person. Tragen Sie die Kostenpositionen auf der Seite der Veranstaltung ein (*Kosten*).
- **Ermäßigungen** (Finanzrollen): Geschwisterermäßigungen nach Stufe des Kindes und Rollen-Ermäßigungen, mit Summen.
- **Teilnehmende pro Veranstaltung** jeder Reihe: wiederkehrende und neue Teilnehmende. *Wiederkehrend* heißt: war schon bei einer
  früheren Veranstaltung derselben Reihe dabei, egal in welchem Jahr – nicht nur im Vorjahr. Das Fragezeichen neben dem Wort zeigt
  das, wenn die Maus darüber steht (am Telefon: antippen).
