# Rechnungen und Zahlungen

Kasmin erstellt Rechnungen pro Familie, sendet sie als PDF mit einem QR-Code für Banking-Apps, importiert Kontoauszüge,
ordnet Zahlungen zu und sendet Erinnerungen und Zahlungsbestätigungen. Sobald die Buchhaltung begonnen hat, werden
Rechnungen, Zahlungen und Rückzahlungen darin von selbst gebucht (Abschnitt 7).

Wer damit arbeiten kann: die Rollen *Administration*, *Vorstand* und *Finanzen*; die *Rechnungsprüfung* kann alles ansehen, aber
nichts ändern. Erziehungsberechtigte sehen die Rechnungen ihrer Familie im Portal.

## 1. Daten der Organisation

*Buchhaltung → Rechnungseinstellungen*:

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

Auf der Rechnung ist jede Ermäßigung eine **eigene Position** unter der Zeile, zu der sie gehört – z. B. *Restbetrag Ben 320,00* und
darunter *Geschwisterermäßigung (2. Kind) −25,00* –, so muss niemand den Betrag nachrechnen, und die Statistik zählt die Ermäßigungen
nach Art. Rechnungen, die vor Version 0.31 ausgestellt wurden, behalten ihr bisheriges Aussehen (Ermäßigung in derselben Zeile).

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

*Buchhaltung → Rechnungen → Rechnungslauf*: Wählen Sie die Veranstaltung und die Rate. Kasmin erstellt einen **Entwurf** pro Familie
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
- **Bankimport** (*Buchhaltung → Zahlungen → Bankimport*): Laden Sie den CSV- oder Excel-Export (XLSX) Ihres Bankkontos hoch
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
- **Rückzahlungen** (*Buchhaltung → Rückzahlungen*): Betrag, zahlungspflichtige Person, IBAN und Grund. Überweisen Sie bei der Bank und markieren Sie die Rückzahlung dann als
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

## 7. Buchungen

Sobald die Buchhaltung begonnen hat – mit ihrer ersten Periode (*Buchhaltung → Einstellungen → Perioden*) –, bucht
Kasmin die Belege dieser Seite selbst in der doppelten Buchhaltung. Niemand tippt diese Buchungen, und sie werden nie
geändert: Was sich später ändert, ist eine eigene Buchung.

| Was passiert | Soll | Haben | Datum |
|---|---|---|---|
| Rechnung ausgestellt | *Forderungen* der zahlenden Person: der Gesamtbetrag; *Gewährte Ermäßigungen*: jede Ermäßigung | das Ertragskonto jeder Zeile: der Betrag vor der Ermäßigung | Rechnungsdatum |
| Gutschrift (Stornierung, Korrektur) | die Konten der Rechnung, umgekehrt | | Datum der Gutschrift |
| Zahlung einer Rechnung zugeordnet | *Bank* (Barzahlungen: *Kasse*) | *Forderungen* der zahlenden Person | Zahlungstag |
| Mehr bezahlt als offen | *Bank* | *Forderungen*, der Rest *Guthaben von Familien* | Zahlungstag |
| Guthaben für eine Rechnung verwendet | *Guthaben von Familien* | *Forderungen* | Tag der Verwendung |
| Rechnung nach einer Zahlung storniert | *Forderungen* | *Guthaben von Familien* | Datum der Gutschrift |
| Rückzahlung überwiesen | *Guthaben von Familien* | *Bank* | Tag der Rückzahlung |

**Ertragskonto einer Zeile**: Mahnspesen gehen auf *Mahngebühren*, Stornogebühren auf *Stornogebühren*. Sonst das
Ertragskonto der Rate (*Veranstaltungen → bearbeiten → Preise, Ratenplan und Geschwisterermäßigung*), wenn eines
eingestellt ist; Mitgliedsbeiträge – eine Rate der Art *Mitgliedsbeitrag* oder die jährliche Rechnung über den
Mitgliedsbeitrag – auf das Konto der Mitgliedsart (*Personen → Mitgliedsarten*), sonst *Mitgliedsbeiträge*; andere Zeilen
einer Veranstaltung auf *Einnahmen aus Veranstaltungen*; Rechnungen ohne Veranstaltung auf *Sonstige Einnahmen*.

**Kostenstelle einer Zeile** (das Feld erscheint, solange *Kostenstellen verwenden* eingeschaltet ist): die der Rate,
bei Mitgliedsbeiträgen die der Mitgliedsart (z. B. das Lager, wenn der Beitrag ein fixer Teil seiner Kosten ist), die
der Veranstaltung, die der Veranstaltungsreihe; ohne all das die Standard-Kostenstelle des Kontos, sonst die
Standard-Kostenstelle.

Gut zu wissen:

- Zahlungen aus dem Bankimport, die **noch nicht zugeordnet sind, werden nicht gebucht**. Sie werden gebucht, sobald Sie
  sie zuordnen, mit dem Zahlungstag. Ordnen Sie sie zu, bevor Sie eine Periode abschließen.
- Belege mit einem Datum **vor der ersten Periode** werden nicht gebucht; was dann offen ist (Rechnungen, Guthaben),
  gehört in die Eröffnungsbuchung.
- Ein Bankkonto im Kontenplan mit der IBAN oder dem Namen, die der Bankimport nennt, bekommt dessen Zahlungen; alle
  anderen Überweisungen gehen auf *Bank*.
- Schlägt eine Buchung fehl – z. B. weil ihre Periode abgeschlossen ist –, wird die Rechnung oder Zahlung trotzdem
  gespeichert, und die Administration bekommt eine Systemmeldung. Beheben Sie die Ursache und buchen Sie dann mit
  `php artisan kasmin:book-documents` nach, was fehlt. Derselbe Befehl bucht die Belege der ersten Periode, die
  entstanden sind, bevor die Buchhaltung begonnen hat. Er bucht jeden Beleg nur einmal und kann daher jederzeit laufen.

Beispiel (erfundene Zahlen): zwei Geschwister, Preis je 450,00, Anzahlung 100,00 schon verrechnet, Geschwisterermäßigung
20,00 für das zweite Kind; die Veranstaltung hat die Kostenstelle *Lager*. Die Restzahlung ist 350,00 + 330,00 = 680,00:

| Konto | Soll | Haben | Kostenstelle |
|---|---|---|---|
| Forderungen (zahlende Person) | 680,00 | | |
| Einnahmen aus Veranstaltungen | | 350,00 | Lager |
| Einnahmen aus Veranstaltungen | | 350,00 | Lager |
| Gewährte Ermäßigungen | 20,00 | | Lager |

Die Familie überweist 700,00; 20,00 sind zu viel und werden ein Guthaben:

| Konto | Soll | Haben |
|---|---|---|
| Bank | 700,00 | |
| Forderungen (zahlende Person) | | 680,00 |
| Guthaben von Familien | | 20,00 |

Die 20,00 werden zurücküberwiesen: *Guthaben von Familien* 20,00 an *Bank* 20,00. Danach schuldet die Familie nichts mehr
(Forderungen 0,00), das Guthaben ist 0,00, auf der Bank sind 680,00 mehr, und *Lager* zeigt Erträge von 700,00 abzüglich
20,00 Ermäßigungen.

## 8. Kommandozeile

| Befehl | Zweck |
|---|---|
| `php artisan kasmin:invoices:remind` | stellt alle fälligen Erinnerungen sofort in die Warteschlange, ohne Freigabe (normalerweise macht das die Automatisierung) |
| `php artisan kasmin:book-documents [--from=2027-01-01]` | bucht die Rechnungen, Gutschriften, Zahlungen und Rückzahlungen, die noch keine Buchung haben (ab der ersten Periode oder ab dem angegebenen Tag) |
| `php artisan kasmin:login-link <e-mail>` | gibt einen einmaligen Anmeldelink für eine Person aus (für die Unterstützung, oder wenn Mail noch nicht funktioniert) |
