# Auslagen und Vorschüsse

Jemand aus dem Team zahlt für den Verein – Bastelmaterial, Zugtickets, den Einkauf fürs Lager – und bekommt das Geld
zurück. Kasmin nimmt den Beleg (ein Foto vom Handy oder ein PDF) entgegen, lässt ihn von der Kassa freigeben, zahlt
per Überweisung oder bar aus und bucht jeden Schritt in der [Buchhaltung](accounting.md). Wer auf die Erstattung
verzichtet, spendet. Ein **Vorschuss** ist Geld, das vorher ausgegeben wird; er wird mit den Belegen
abgerechnet.

Wer es nutzen kann: im Portal jede Person mit einer Rolle im Team (z. B. *Betreuung*) und alle, die schon eine Auslage
oder einen Vorschuss haben – jede Person sieht nur ihre eigenen. In der Verwaltung *Buchhaltung → Auslagen* und
*Vorschüsse* für die Rollen mit dem Recht *Finanzen* (*Administration*, *Vorstand*, *Finanzen*; die
*Rechnungsprüfung* nur lesend). Die Buchhaltung braucht eine erste Periode (*Buchhaltung → Einstellungen → Perioden*).

## 1. Einstellungen

*Buchhaltung → Einstellungen → Rechnungseinstellungen → Auslagen*:

| Einstellung | Bedeutung |
|---|---|
| Freigabe durch eine zweite Person | Vier-Augen-Prinzip: Niemand gibt eine Auslage frei, die selbst eingetragen wurde oder die eigene ist |
| Pauschalen | eine je Zeile als `Name: Betrag`, z. B. `Fahrtkostenpauschale je Veranstaltung: 90` oder `Kilometergeld: 0,42`. Eine Pauschale braucht keinen Beleg und wird je Person und Veranstaltung einmal eingereicht; mit einer Menge (z. B. Kilometer) ist der Betrag Satz × Menge. Standardmäßig leer |

Die Finanz-Rollen bekommen unter *Meine Benachrichtigungen → Auslagen* eine Mail zu neuen Auslagen (sofort oder als
Zusammenfassung).

## 2. Einreichen im Portal

Im Portal öffnet der Link **Auslagen und Vorschüsse** die Seite der Person:

1. **Datum des Belegs**, **Betrag** und **wofür** – oder eine Pauschale und die Menge.
2. Optional: die **Veranstaltung** (ihre Kostenstelle wird verwendet), die **Kategorie** (Aufwandskonto, Standard
   *Sonstige Ausgaben*), die Kostenstelle (wenn Kostenstellen eingeschaltet sind) und der offene **Vorschuss**, den der
   Beleg abrechnet.
3. **Beleg**: Am Handy bietet das Feld die Kamera und die Dateien an; Fotos und PDFs bis 10 MB.
4. *Ich verzichte auf die Erstattung* macht den Betrag nach der Freigabe zur Spende.

Unter dem Formular sieht die Person alle eigenen Auslagen mit ihrem Status (eingereicht, freigegeben, abgelehnt mit dem
Grund, ausbezahlt, gespendet) und die eigenen Vorschüsse mit dem, was noch offen ist. Die **IBAN für Erstattungen** wird
einmal eingetragen; sie wird verschlüsselt gespeichert, nur maskiert angezeigt (`AT** **** **** **** 5864`) und nie in
Mails oder das Protokoll geschrieben. Eine eingereichte oder freigegebene Auslage lässt sich noch in eine Spende
umwandeln (*Auf Erstattung verzichten*).

## 3. Zentral eintragen

Für jemanden, der das Portal nicht nutzt: *Buchhaltung → Auslagen → Auslage eintragen* mit Person, Datum, Betrag und
Zweck (oder Pauschale), Konto, Veranstaltung, Kostenstelle, Vorschuss und optional dem Beleg und der IBAN der Person.
Danach läuft alles wie bei einer Auslage aus dem Portal; die Liste kennzeichnet sie mit *zentral eingetragen*.

## 4. Freigeben und ablehnen

Die Liste zeigt zuerst die offenen Auslagen (eingereicht und freigegeben); der Filter zeigt die anderen. *Beleg* öffnet
das Foto oder PDF. **Freigeben** kann das Aufwandskonto und die Kostenstelle noch korrigieren und bucht:

- den Aufwand auf sein Konto und seine Kostenstelle, gegen **Offene Auslagen** der Person;
- bei einem Beleg zu einem Vorschuss: stattdessen gegen **Vorschüsse** der Person (Status *mit dem Vorschuss
  abgerechnet*).

Die Kostenstelle ist die der Veranstaltung (oder ihrer Reihe), sonst die gewählte, sonst die Standard-Kostenstelle des
Kontos, sonst die Standard-Kostenstelle. Die Buchung bekommt das Datum des Belegs – oder heute, wenn dessen Periode
abgeschlossen ist. **Ablehnen** braucht einen Grund; er geht per Mail an die Person. Auch bei der Freigabe und bei der
Auszahlung bekommt die Person eine Mail.

## 5. Auszahlen

- **Auszahlen** für eine Auslage oder mehrere auswählen und **Ausgewählte auszahlen**: Bank- oder Kassakonto und Tag
  wählen. Die Auslagen werden gebucht (*Offene Auslagen* an das Bank- oder Kassakonto) und als ausbezahlt markiert.
- Von einem **Bankkonto** lädt Kasmin eine **SEPA-Datei** (pain.001.001.09) zum Hochladen im Online-Banking herunter:
  eine Überweisung je Auslage, oder mit *Eine Überweisung je Person* die Summe je Person. Auftraggeber ist die IBAN des
  Bankkontos (oder die IBAN der Rechnungseinstellungen) mit dem Namen der Organisation. Jede Person braucht eine IBAN
  für Erstattungen; Kasmin nennt, wem sie fehlt. Die IBAN wird bei der Auszahlung in die Auslage kopiert.
- **Zahlungscode**: für eine Auslage ein QR-Code für Banking-Apps (IBAN, Betrag und Text ausgefüllt); danach
  *Auszahlen*, um zu buchen.
- Von einem **Kassakonto** braucht es keine IBAN.

## 6. Verzicht als Spende

*Verzicht (Spende)* bei einer freigegebenen Auslage – oder *Auf Erstattung verzichten* durch die Person im Portal –
bucht den Betrag von *Offene Auslagen* an **Spenden** in der Kostenstelle des Aufwands. Aufwand und Spende gleichen sich
dort aus; die Spende bleibt bei der Person sichtbar (für eine spätere Spendenbestätigung).

## 7. Vorschüsse

*Buchhaltung → Vorschüsse → Vorschuss ausgeben*: Person, Betrag, Bank- oder Kassakonto, Tag und Zweck. Die Person
reicht die Belege gegen den Vorschuss ein (im Portal oder zentral). Sind alle Belege entschieden, **Abrechnen**: Die
Person gibt genau das zurück, was noch offen ist, auf ein Bank- oder Kassakonto. Sind die Belege mehr als der Vorschuss,
wird der Unterschied eine freigegebene Auslage der Person und wie jede andere ausbezahlt.

## 8. Buchungen, Schritt für Schritt

Ein Beispiel mit erfundenen Zahlen: Kim kauft Bastelmaterial fürs Sommerlager, Robin verzichtet auf das Porto, Sam
bekommt 300,00 bar für den Einkauf des Lagers.

| Schritt | Soll | Haben | Betrag |
|---|---|---|---|
| Kims Auslage freigegeben | Material (Sommerlager) | Offene Auslagen (Kim) | 61,70 |
| Mit der SEPA-Datei ausbezahlt | Offene Auslagen (Kim) | Bank | 61,70 |
| Robins Auslage freigegeben | Porto (Sommerlager) | Offene Auslagen (Robin) | 18,30 |
| Robin verzichtet auf die Erstattung | Offene Auslagen (Robin) | Spenden (Sommerlager) | 18,30 |
| Vorschuss an Sam | Vorschüsse (Sam) | Kasse | 300,00 |
| Sams Beleg für Lebensmittel freigegeben | Verpflegung (Sommerlager) | Vorschüsse (Sam) | 184,50 |
| Sams Bustickets freigegeben | Fahrtkosten (Sommerlager) | Vorschüsse (Sam) | 43,00 |
| Sam gibt den Rest zurück | Kasse | Vorschüsse (Sam) | 72,50 |

Danach stehen *Offene Auslagen* und *Vorschüsse* wieder auf 0; das Sommerlager hat Kosten von 307,50 und eine Spende
von 18,30, ein Ergebnis von −289,20. Wären Sams Belege 325,00 gewesen, würden die 25,00 über dem Vorschuss eine Auslage
von Sam (*Vorschüsse* an *Offene Auslagen*, 25,00) und wie jede andere ausbezahlt (*Offene Auslagen* an *Kasse*, 25,00).

REST und MCP: siehe [REST-API](api.md) (`/expense-claims`, `/advances`). Ein KI-Assistent bekommt nie die SEPA-Datei und
sieht IBANs nur maskiert.
