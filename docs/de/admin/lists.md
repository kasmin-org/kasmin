# Listen, Exporte und gespeicherte Ansichten

## Listen einer Veranstaltung

*Veranstaltungen → Listen*: Wählen Sie die Veranstaltung, die Liste und bei Bedarf eine Rolle. Jede Liste lässt sich als PDF (zum Drucken), XLSX
oder CSV öffnen. Angeführt werden nur Personen, die teilnehmen (angemeldet, bestätigt, teilgenommen).

| Liste | Inhalt | Sichtbar für |
|---|---|---|
| Teilnehmende | Name, Geschlecht, Geburtsdatum, Alter, Rolle, Status, Erziehungsberechtigte | Administration, Vorstand, Lagerleitung, Sicherheitsdaten |
| Kennzahlen für die Teambesprechung | pro Rolle: Anzahl nach Geschlecht, gesamt, schon dabei gewesen, neu, Warteliste – nur Zahlen, keine Namen | dieselben |
| Kontakte | Erziehungsberechtigte mit Telefon und E-Mail | dieselben |
| Busliste | alle, die mit dem Bus fahren: erziehungsberechtigte Person mit Telefon, Kästchen zum Abhaken für Hin- und Rückfahrt | dieselben |
| Anreise mit dem Auto | alle, die nicht mit dem Bus fahren: Erwachsene mit Telefon; Kinder mit der erziehungsberechtigten Person, die sie bringt, und deren Telefon | dieselben |
| Gruppen, Zimmer, Dienste | jede Einteilung der Veranstaltung mit ihren Gruppen oder Zimmern und den Personen darin | dieselben |
| Geburtstage während der Veranstaltung | Geburtstage während der Veranstaltung | dieselben |
| Antworten | nicht vertrauliche Antworten anderer Formulare, z. B. T-Shirt-Größen | dieselben |
| Fehlende Sicherheitsblätter | Status und Telefonnummer, keine Gesundheitsdaten | dieselben |
| Notfallkontakte, Küche, Medizinisches, Medikamente, Versicherung, Schwimmen, Einwilligungen | aus dem Sicherheitsblatt | **nur Administration und Sicherheitsdaten** |
| Änderungen seit der letzten Veranstaltung | Feld und neuer Wert | **nur Administration und Sicherheitsdaten** |

- Welches Feld auf welcher Liste erscheint, wird pro Feld im Formular eingestellt (*Verwendung in Listen*).
- Wer **kein Sicherheitsblatt hat, bleibt auf den Listen Küche und Medizinisches mit „Fehlt noch“** stehen. So sieht ein fehlendes Blatt nie
  wie „keine Allergien“ aus.
- Werte, die sich seit der letzten Veranstaltung der Person geändert haben, sind hervorgehoben.
- Ab der Altersgrenze des Sicherheitsblatts erscheinen Personen nicht mehr auf diesen Listen.
- Jede Ansicht und jeder Export einer vertraulichen Liste wird protokolliert (ohne die Werte).

## Lagermappe

*Veranstaltungen → Listen → Lagermappe*: eine Seite pro Kind mit Notfallkontakten und Sicherheitsblatt, als vertraulich gekennzeichnet und
protokolliert. Die Adresse `/admin/events/<event>/camp-folder/<person>` liefert die Seite eines Kindes.

## Exporte

Die Schaltfläche *Export* bei Personen, Familien, Gruppen, Veranstaltungen, Rechnungen, Zahlungen, Rückzahlungen, Einwilligungen, Aussendungen, Mailprotokoll und
Audit-Protokoll exportiert, was die Tabelle zeigt – mit der aktuellen Suche, den Filtern und Spalten. XLSX und CSV bis 5000 Einträge, PDF bis
1000. Exporte werden protokolliert. Eingegebener Text wird nie als Formel einer Tabellenkalkulation ausgeführt.

## Aktionen für ausgewählte Zeilen

Haken Sie Zeilen in *Personen* oder bei den Teilnehmenden einer Veranstaltung an und wählen Sie unter *Mehrfachaktionen*:

- **Zu einer Gruppe hinzufügen / Aus einer Gruppe entfernen** – für Gruppen, die von Hand gefüllt werden.
- **An die Auswahl schreiben** – legt eine Aussendung als Entwurf für genau diese Personen an; Vorschau, Freigabe und Versand folgen dort
  ([Mail](mail.md)).
- **Status ändern** (nur Teilnehmende) – z. B. mehrere als *Teilgenommen* markieren. Stornierungen erfolgen einzeln, weil sie einen
  Grund brauchen und die Familie informieren.

## Gespeicherte Ansichten und Filter

- *Ansichten → Diese Ansicht speichern* behält Suche, Filter, Sortierung und Spalten unter einem Namen; *Für alle mit Zugriff auf diese Seite sichtbar* teilt sie mit allen,
  die die Seite öffnen können.
- Personen lassen sich nach dem Alter an einem Datum filtern und mit jeder Kombination von Bedingungen (UND, ODER, NICHT): Name, Geburtsdatum, Gruppen,
  Familien, Mitgliedsart, Rolle, Veranstaltung und Status bei einer Veranstaltung. Antworten aus Formularen sind verschlüsselt und lassen sich nicht filtern.
