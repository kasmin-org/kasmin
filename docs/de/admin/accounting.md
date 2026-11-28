# Buchhaltung

Kasmin führt eine doppelte Buchhaltung: Jede Buchung hat Zeilen mit Soll und Haben, und beide Seiten ergeben denselben Betrag.
Rechnungen, Zahlungen, Rückzahlungen und Auslagen werden automatisch gebucht; alles andere geben Sie unter *Buchhaltung →
Buchungen* ein. Alles finden Sie im Bereich **Buchhaltung**.

Auslagen des Teams und Vorschüsse: siehe [Auslagen und Vorschüsse](expenses.md).

## Grundbegriffe

| Begriff | Beantwortet | Beispiel |
|---|---|---|
| **Konto** | *was* gebucht wurde | Verpflegung, Bank, Mitgliedsbeiträge |
| **Kostenstelle** | *wofür* | Sommerlager, Alumni-Wochenende, Allgemein |
| **Fonds** | *wem das Geld* über die Jahre gehört | Fonds Sommerlager, Allgemeiner Fonds |

Konten der Art *Vermögen* (Bank, Kasse, Forderungen) sagen, **wo das Geld liegt**; Fonds sind Teil des **Eigenkapitals** und
sagen, **wofür es gehört**. Beide Seiten sind gleich groß, aber unabhängig voneinander – das Geld des Fonds Sommerlager kann
teils auf der Bank, teils in der Kasse liegen:

| Wo liegt es? | | Wofür gehört es? | |
|---|---|---|---|
| Bank | 6.000 | Fonds Sommerlager | 5.200 |
| Kasse | 200 | Fonds Wochenende | 1.200 |
| Offene Rechnungen | 300 | | |
| Offene Auslagen | −100 | | |
| **Summe** | **6.400** | **Summe** | **6.400** |

Darum ist der Stand eines Fonds nicht der Stand eines Kontos: Offene Rechnungen und noch nicht erstattete Auslagen zählen mit.

## Perioden

Eine Periode ist ein Zeitraum, z. B. 19. Oktober bis 18. Oktober, damit ein im Herbst verrechneter Mitgliedsbeitrag zum Jahr des
nächsten Sommerlagers gehört. *Buchhaltung → Einstellungen → Buchungsperioden → Neue Periode*: Die erste Periode startet die
Buchhaltung; jede weitere folgt lückenlos auf die vorige und übernimmt deren aktive Kostenstellen und Umlageschlüssel. Belege nach
der letzten Periode legen die nächste selbst an (höchstens zwei Jahre im Voraus).

Die Daten einer **offenen** Periode lassen sich nachträglich ändern (*Name und Daten ändern*): Buchungen gehören über ihr Datum zu
einer Periode, also wandern sie mit, und die Nachbarperiode zieht mit. Kasmin zeigt vorher, wie viele Buchungen danach zu einer
anderen Periode gehören. Eröffnungswerte werden nie gespeichert, sondern berechnet – sie stimmen daher immer.

Eine **abgeschlossene** Periode ist gesperrt: Nichts mit einem Datum darin kann gebucht, geändert oder gelöscht werden.
*Wiedereröffnen* zeigt die Perioden, die mitgeöffnet werden – jede spätere abgeschlossene, weil sich ihre Eröffnungswerte ändern –,
und fragt nach einer Begründung.

## Buchungen

*Buchhaltung → Buchungen → Neue Buchung*: Datum, Text, Belegnummer und Beleg (Foto oder PDF) und beliebig viele Zeilen – z. B. ein
Einkauf für Verpflegung und Material, bar bezahlt:

| Konto | Soll | Haben |
|---|---|---|
| Verpflegung | 30,00 | |
| Material | 12,50 | |
| Kasse | | 42,50 |

Erträge und Aufwände bekommen immer eine Kostenstelle; ohne Angabe die Standard-Kostenstelle des Kontos oder die
Standard-Kostenstelle. Die Nummern laufen über alle Jahre. Eine Buchung wird nie geändert oder gelöscht: **Stornieren** bucht dieselben
Beträge auf den Gegenseiten, so bleibt alles nachvollziehbar.

## Kostenstellen

*Globale Einstellungen → Kostenstellen verwenden* schaltet sie ein. Intern hat jede Buchung immer eine Kostenstelle; es gibt immer eine
Standard-Kostenstelle, *Allgemein*. Ausgeschaltet wird alles auf sie gebucht, und das Feld ist ausgeblendet. Wieder ausschalten geht
nur, solange es genau eine Kostenstelle gibt und sie ihr Ergebnis trägt.

Eine Kostenstelle ist **eine über alle Jahre** und **je Periode aktiv oder nicht**: Eine neue Periode übernimmt die aktiven, inaktive
verschwinden aus der Auswahl, behalten aber ihre Buchungen in früheren Perioden, und ein Projekt, das wiederkommt, wird einfach wieder
aktiviert – sein Fonds läuft weiter. Jede Kostenstelle hat eine Art:

- **Trägt ihr Ergebnis**: Beim Abschluss geht ihr Ergebnis in ihren Fonds.
- **Wird umgelegt**: Sie hat keinen Fonds; beim Abschluss werden ihre Kosten nach dem Umlageschlüssel der Periode auf Kostenstellen
  verteilt, die ihr Ergebnis tragen. Umlagen gehen nie an eine Kostenstelle, die selbst umgelegt wird.

**Löschen** geht nicht bei der Standard-Kostenstelle, solange ein Schlüssel einer offenen Periode ihr eine Umlage gibt, und solange sie
Buchungen in abgeschlossenen Perioden hat (diese wiedereröffnen oder die Kostenstelle stattdessen inaktiv schalten). Ihre Buchungen werden in
eine andere Kostenstelle verschoben; eingegebene Buchungen in offenen Perioden lassen sich stattdessen endgültig löschen, nachdem Sie den
angezeigten Satz eingetippt haben, z. B. *3 Buchungen sollen endgültig gelöscht werden.* Vorher wird eine Sicherung erstellt.

## Fonds

Fonds heißen auch **Rücklagen** (in einer österreichischen Vereinsbilanz meist *zweckgebundene Rücklagen*). Stellen Sie sie sich als
**Töpfe** vor: Jeder Fonds sammelt über die Jahre die Ergebnisse der Kostenstellen, die ihr Ergebnis in ihn tragen, und Geld, das per
Vorstandsbeschluss hineingebucht wird (*Geld in einen anderen Fonds umbuchen*, z. B. um für neue Zelte zu sparen). Ein Fonds kann nach
einem Verlustjahr negativ werden; der Abschluss zeigt dann eine Warnung.

Fonds sind **optional**: *Globale Einstellungen → Fonds verwenden* (nur mit Kostenstellen). Ausgeschaltet gehen alle Ergebnisse in den
*Allgemeinen Fonds*, das freie Eigenkapital. Ein Fonds kann einem Bank- oder Kassakonto zugeordnet werden; Kasmin vergleicht dann das
Konto mit der Summe seiner Fonds und zeigt die Differenz. Löschen lässt sich ein Fonds, wenn er nichts enthält und keine Kostenstelle ihn
verwendet.

## Umlage, Schritt für Schritt

1. **Die Verwaltung hat Kosten.** Während des Jahres fallen Kosten an, die keinem Projekt gehören: Website, Software, Bankspesen, Porto –
   sagen wir **2.000,00**. Sie werden auf die Kostenstelle *Verwaltung* gebucht.
2. **Die Verwaltung hat keinen Fonds**, also *wird sie umgelegt* und muss nach dem Abschluss auf 0 stehen: Ihre Kosten werden nach dem
   **Schlüssel** der Periode auf die Projekte verteilt (*Buchungsperioden → Umlageschlüssel*).
3. **Ein Schlüssel kann Fixbeträge enthalten.** Zum Beispiel: *Das Wochenende zahlt fix 300,00, die Jugendgruppe fix 100,00, den Rest
   trägt das Sommerlager.* Im normalen Jahr: Wochenende 300,00, Jugendgruppe 100,00, Sommerlager 2.000,00 − 400,00 = 1.600,00.
4. **Zuerst die Fixbeträge, dann Prozente vom verbleibenden Betrag; was dann übrig ist, bekommt die eine Rest-Kostenstelle, oder es
   verteilt sich anteilig am Ertrag** der so markierten Kostenstellen.
5. **Die Kosten sind kleiner als die Fixbeträge.** Hat die Verwaltung nur **200,00** gekostet, wären die Fixbeträge (400,00) mehr als die
   Kosten – die Verwaltung stünde im Plus, und das Wochenende würde für das Lager zahlen. Kasmin **kürzt deshalb jeden Fixbetrag im
   selben Verhältnis** (hier auf die Hälfte: Wochenende 150,00, Jugendgruppe 50,00, Lager 0,00) und meldet das beim Abschließen in einer
   eigenen Meldung. Wollen Sie das nicht, brechen Sie ab und ändern den Schlüssel dieser Periode. Die nächste Periode übernimmt den
   Schlüssel **wie eingestellt**, nicht die gekürzten Beträge.
6. **Die Verwaltung ist im Plus** (z. B. nach einer großen Spende von 500,00 mehr als ihre Kosten): Die Fixbeträge zahlen nichts und
   bekommen nichts; der Überschuss geht an die Prozente und den Rest – hier bekommt das Lager +500,00.

| Kosten der Verwaltung | Wochenende (fix 300) | Jugendgruppe (fix 100) | Sommerlager (Rest) |
|---|---|---|---|
| 2.000,00 | 300,00 | 100,00 | 1.600,00 |
| 400,00 | 300,00 | 100,00 | 0,00 |
| 200,00 | 150,00 (gekürzt) | 50,00 (gekürzt) | 0,00 |
| Überschuss 500,00 | 0,00 | 0,00 | −500,00 (bekommt 500,00) |

Beträge sind ganze Cent; was beim Runden übrig bleibt, geht an den größten Anteil, so stimmt die Summe immer genau. Diese Beispiele sind
Tests von Kasmin.

## Abschluss

*Buchungsperioden → Abschließen* zeigt zuerst eine Vorschau: je Kostenstelle das Ergebnis, die Umlage und das Ergebnis danach; je Fonds
den Wert vorher, das Ergebnis und den Wert danach; und jede Warnung (gekürzte Fixbeträge, eine Kostenstelle mit Erträgen, die in keinem
Schlüssel vorkommt, ein Fonds, der negativ wird). Fehler (eine umgelegte Kostenstelle ohne Schlüssel, eine frühere Periode noch offen)
verhindern den Abschluss. Das Abschließen bucht die Umlagen, überträgt jedes Ergebnis in seinen Fonds, stellt Erträge und Aufwände auf 0
und sperrt die Periode. Perioden werden in der Reihenfolge ihrer Daten abgeschlossen.

## Bank- und Kassakonten

*Buchhaltung → Bank- und Kassakonten* zeigt den Stand jedes Geldkontos. Tippen Sie den Stand laut Kontoauszug ein, um zu vergleichen, und
sehen Sie die Fonds, die dem Konto zugeordnet sind. *Kontoblatt* öffnet alle Zeilen des Kontos mit dem laufenden Stand.

## Berichte

*Buchhaltung → Berichte*: Bilanz an einem Tag, Erfolgsrechnung einer Periode (auf Wunsch einer Kostenstelle), Kostenstellen (Erträge,
Aufwände, Umlage, Ergebnis, Budget und Differenz), Fondsspiegel (Anfang, Ergebnis, Umbuchungen, Ende – vorläufig, solange die Periode
offen ist), Kontoblatt eines Kontos und offene Posten je Person. Jeder Bericht lässt sich als Excel, CSV oder PDF herunterladen.
