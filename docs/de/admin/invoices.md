# Rechnungen und Zahlungen

Kasmin erstellt Rechnungen pro Familie, sendet sie als PDF mit einem QR-Code für Banking-Apps, importiert Kontoauszüge,
ordnet Zahlungen zu und sendet Erinnerungen und Zahlungsbestätigungen. Die Buchhaltung (Buchführung, Bilanzen) ist nicht
Teil von Kasmin.

Wer damit arbeiten kann: die Rollen *Administration*, *Vorstand* und *Finanzen*; die *Rechnungsprüfung* kann alles ansehen, aber
nichts ändern. Erziehungsberechtigte sehen die Rechnungen ihrer Familie im Portal.

## 1. Daten der Organisation

*Einstellungen → Organisation und Rechnungen*:

| Einstellung | Bedeutung |
|---|---|
| Name, Anschrift, E-Mail, Website | stehen auf jeder Rechnung |
| Fußzeile (Impressum) | z. B. Registernummer, Sitz, Kontakt – klein gedruckt am Ende jeder Rechnung |
| IBAN, BIC, Bank | Bankverbindung für Überweisungen; die IBAN wird geprüft. Ohne IBAN wird kein QR-Code gedruckt |
| Nummernformat | `{number}` = laufende Nummer, `{year}` = Jahr, z. B. `R{number}` → R516 oder `{year}-{number}` → 2027-516 |
| Nächste Nummer | die nächste Rechnung bekommt diese Nummer. Nach dem Import alter Rechnungen stellen Sie hier die nächste freie Nummer ein |
| Eigene Nummern pro Jahr | die Nummern beginnen jedes Jahr mit 1; das Nummernformat braucht dann `{year}` |
| Zahlungsziel | Tage, bis eine Rechnung fällig ist, wenn die Rate kein eigenes Fälligkeitsdatum hat |
| Erinnerung vor Fälligkeit | eine freundliche Erinnerung so viele Tage vor der Fälligkeit (Voreinstellung 3, 0 = keine) |
| Mahnungen nach … Tagen | Mahnungen nach der Fälligkeit, durch Beistriche getrennt (Voreinstellung `7,21`) |
| Mahnspesen | optional, standardmäßig aus: ein Betrag pro Mahnung nach der Fälligkeit, durch Strichpunkte getrennt in derselben Reihenfolge, z. B. `0;5` = keine Spesen für die erste, 5 € für die zweite Mahnung |
| Bezahlte Rechnung anhängen | die Zahlungsbestätigung enthält die Rechnung mit dem Vermerk „bezahlt“ |

## 2. Preise, Ratenplan und Ermäßigungen (pro Veranstaltung)

*Veranstaltungen → Veranstaltung bearbeiten*:

- **Preis pro Rolle** (z. B. Kind, Nachwuchs-Betreuung) im Abschnitt *Rollen, Plätze und Altersgrenzen*.
- **Ermäßigung für die Rolle** (optional), z. B. für Nachwuchs-Betreuung. Sie *ersetzt* die Geschwisterermäßigung, aber die Person
  zählt weiter als Geschwisterkind. Jüngere Geschwister behalten also ihre Stufe.
- **Zählt als Geschwisterkind**: ob Anmeldungen in dieser Rolle bei der Geschwisterermäßigung mitgezählt werden (meist ja bei
  Kindern und Nachwuchs-Betreuung, nein beim Team).
- **Ratenplan** (*Preise, Ratenplan und Geschwisterermäßigung*): beliebig viele Raten, jede entweder ein
  **fester Betrag** (z. B. Anzahlung 100 €) oder der **Restbetrag** (Preis minus alle festen Raten). Jede
  Rate hat ein festes Fälligkeitsdatum oder „Fällig nach … Tagen“, kann als erstattbar markiert werden und hat den Schalter
  *Ermäßigungen anwenden* – üblich sind Ermäßigungen nur beim Restbetrag, nicht bei der Anzahlung.
- **Geschwisterermäßigung**:
  - *Gestaffelt*: Das 2. Kind bekommt den Betrag einmal, das 3. Kind zweimal und so weiter, bis zur **Obergrenze** (z. B. Obergrenze 3:
    Ab dem 3. Kind bleibt die Ermäßigung beim doppelten Betrag).
  - *Alle Geschwister gleich*: Jedes Kind bekommt den Betrag, sobald mindestens die Mindestanzahl Kinder teilnimmt.
  - *Wer zahlt den vollen Preis*: das älteste Kind (Voreinstellung), die teuerste Anmeldung oder nach Reihenfolge der Anmeldung.
- **Erinnerung … Tage vor Fälligkeit** pro Veranstaltung (optional, sonst die globale Einstellung).

Beispiel: Preis 420 €, Anzahlung 100 € (ohne Ermäßigungen), Restbetrag = 320 €, gestaffelt 25 € mit Obergrenze 3. Drei Geschwister zahlen
Anzahlungen von 3 × 100 € und Restbeträge von 320 €, 295 € und 270 €.

- **Preise nach Alter oder Anmeldedatum**: z. B. ein niedrigerer Preis für jüngere Kinder, ein Frühbucherpreis bis zu einem Datum oder ein höherer
  Preis ab einem Datum. Die erste passende Zeile zählt; sonst der Preis der Rolle.
- **Ermäßigter Platz**: In der Liste der Teilnehmenden legt *Ermäßigter Platz* den Gesamtpreis einer Person fest (0 = gratis), mit einem
  Grund. Preis und Grund können nur Finanzrollen eintragen und sehen; ein ermäßigter Platz bekommt keine Geschwisterermäßigung.
- **Mitgliedsbeitrag als Rate**: Die Art *Mitgliedsbeitrag* verwendet den Jahresbeitrag einer Mitgliedsart; er wird in einem Jahr
  nicht zweimal verrechnet.
- **Nach Zahlung** (pro Rate): den Platz bestätigen, die Person zu einer Gruppe hinzufügen, die Person aus einer Gruppe entfernen.

### Stornobedingungen

Pro Veranstaltung: Stornobedingungen (ein Text) und eine Stornogebühr; pro Rate: erstattbar oder nicht,
und bis wie viele Tage vor dem Beginn. Erziehungsberechtigte sehen, was heute gilt, **bevor** sie im Portal
stornieren. Administratorinnen und Administratoren stornieren in der Liste der Teilnehmenden und geben einen Grund an. Die Stornogebühr wird einmal
verrechnet, wenn eine erstattbare Rate erstattet wird.

### Mitgliedsbeiträge pro Jahr

*Personen → Mitgliedsarten* enthält den Jahresbeitrag pro Art. Die Automatisierung *Mitgliedsbeiträge* erstellt eine Rechnung
pro Familie; Beiträge, die schon als Rate einer Veranstaltung desselben Jahres verrechnet wurden, werden übersprungen.

## 3. Rechnungslauf

*Finanzen → Rechnungen → Rechnungslauf*: Wählen Sie die Veranstaltung und die Rate. Kasmin erstellt einen **Entwurf** pro Familie
(alle Teilnehmenden, die angemeldet oder bestätigt sind oder ein Platzangebot haben; Einträge auf der Warteliste werden nicht verrechnet).
Die Rechnung geht an die Person, die in der Familie die Rechnungen bekommt, sonst an die erste erziehungsberechtigte Person. Ein weiterer Lauf
ergänzt nur Familien, die für diese Rate noch keine Rechnung haben.

Prüfen Sie die Entwürfe (Positionen, Ermäßigungen), wählen Sie sie dann aus und verwenden Sie **Ausstellen und senden**: Jede Rechnung bekommt ihre Nummer und
eine **strukturierte Zahlungsreferenz** (RF-Referenz, ISO 11649, z. B. `RF32R2`) und wird mit der Vorlage
*Rechnung* und dem PDF im Anhang gesendet. Entwürfe können noch gelöscht werden; ausgestellte Rechnungen nicht – sie werden storniert.

Das PDF enthält bei Rechnungen in Euro einen **EPC-QR-Code** („GiroCode“/„Zahlen mit Code“): Banking-Apps füllen Empfänger,
IBAN, Betrag und Referenz automatisch aus.

## 4. Zahlungen und Bankimport

- **Zahlung erfassen** bei einer Rechnung für Barzahlungen oder einzelne Überweisungen (Teilzahlungen sind möglich).
- **Bankimport** (*Finanzen → Zahlungen → Bankimport*): Laden Sie den CSV- oder Excel-Export (XLSX) Ihres Bankkontos hoch
  und geben Sie an, in welcher Spalte Datum, Betrag, Verwendungszweck und (optional) Name und IBAN stehen. Die Zuordnung der Spalten wird gemerkt.
  Nur Eingänge werden importiert; dieselbe Zeile wird beim zweiten Import erkannt. Die Datei wird danach gelöscht.
- Zuordnung:
  - **Automatisch zugeordnet** – der Verwendungszweck enthält die RF-Referenz oder die Rechnungsnummer, und der Betrag ist nicht
    höher als der offene Betrag;
  - **Vorschlag** – derselbe Betrag wie eine offene Rechnung, und der Nachname der zahlenden Person kommt im Namen vor; bestätigen Sie mit einem
    Klick (oder wählen Sie mehrere aus und *Vorschläge bestätigen*);
  - **Nicht zugeordnet** – von Hand zuordnen oder *Ignorieren* (z. B. andere Einnahmen).
- **Mehrere Rechnungen in einer Überweisung**: werden automatisch gebucht, wenn alle Referenzen im Text stehen und die Summe passt;
  sonst verwenden Sie *Aufteilen*. Der Rest bleibt nicht zugeordnet.
- **Zu viel bezahlt**: Der Rest wird ein **Guthaben** der Familie mit einem Vorschlag zur Rückzahlung. Verwenden Sie es für eine andere
  Rechnung oder zahlen Sie es zurück.
- **Rückzahlungen** (*Finanzen → Rückzahlungen*): Betrag, zahlungspflichtige Person, IBAN und Grund. Überweisen Sie bei der Bank und markieren Sie die Rückzahlung dann als
  *Zurückgezahlt* – oder *Als Guthaben behalten*. Kasmin überweist nie selbst Geld.
- Ist eine Rechnung vollständig bezahlt, bekommt die zahlende Person die **Zahlungsbestätigung** (Vorlage *Zahlungsbestätigung*,
  auf Wunsch mit der bezahlten Rechnung im Anhang), und wer das Benachrichtigungsthema *Zahlungen* abonniert hat, wird informiert.

## 5. Erinnerungen, Fristen, Gutschriften

- Die Automatisierung *Zahlungserinnerungen* ([Automatisierungen](automations.md)) läuft täglich und **bittet zuerst um Freigabe**: eine freundliche
  Erinnerung vor der Fälligkeit (Vorlage *Zahlungserinnerung (vor Fälligkeit)*) und Mahnungen nach der Fälligkeit
  (Vorlage *Zahlungserinnerung (überfällig)*), jede einmal pro Rechnung. Bezahlte Rechnungen bekommen keine; Rechnungen, die innerhalb
  der Erinnerungsfrist ausgestellt wurden, bekommen keine Erinnerung „bald fällig“.
- **Mahnspesen** (optional) sind eine eigene Rechnung, die der Mahnung beiliegt; auf Mahnspesen gibt es keine weiteren Spesen.
- Die Automatisierung *Plätze unbezahlter Rechnungen freigeben* schlägt vor, Plätze zu stornieren, die einige Tage nach der letzten
  Mahnung noch unbezahlt sind.
- **Frist verlängern** ändert das Fälligkeitsdatum einer einzelnen Rechnung.
- **Stornieren (Gutschrift)** erstellt eine Gutschrift mit eigener Nummer und dem negativen Betrag; die Rechnung bleibt zur
  Nachvollziehbarkeit mit dem Status *Storniert* erhalten. Was schon bezahlt war, wird ein Guthaben mit einem Vorschlag zur Rückzahlung.
- **Korrekturen**: Ausgestellte Rechnungen ändern sich nie. Wenn eine Stornierung, ein Geschwisterkind oder ein Preis den Betrag ändert, werden Entwürfe
  sofort neu berechnet, und die Automatisierung *Rechnungen korrigieren* schlägt eine Gutschrift und eine neue Rechnung vor. Zahlungen wechseln zur
  neuen Rechnung, nur die Differenz wird als Rückzahlung vorgeschlagen. Die Familie sieht das alte und das neue Dokument.

## 6. Portal

Erziehungsberechtigte sehen unter *Rechnungen* alle ausgestellten Rechnungen ihrer Familie mit offenem Betrag, Fälligkeitsdatum, Referenz
und Status (offen, teilweise bezahlt, bezahlt, überfällig) und können das PDF öffnen.

## 7. Kommandozeile

| Befehl | Zweck |
|---|---|
| `php artisan kasmin:invoices:remind` | stellt alle fälligen Erinnerungen sofort in die Warteschlange, ohne Freigabe (normalerweise macht das die Automatisierung) |
| `php artisan kasmin:login-link <e-mail>` | gibt einen einmaligen Anmeldelink für eine Person aus (für die Unterstützung, oder wenn Mail noch nicht funktioniert) |
