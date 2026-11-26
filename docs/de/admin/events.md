# Veranstaltungen, Anmeldung und Warteliste

Fragen bei der Anmeldung, öffentliche Formulare und Gäste: [Anmeldung](registration.md). Listen: [Listen](lists.md).

Vorlagen, Termine, öffentliche Seiten und Runden: [Veranstaltungen planen](event-planning.md).

## Veranstaltungsreihen und Veranstaltungen

Veranstaltungen derselben Art bilden eine **Reihe** (z. B. die Reihe der Lager, die Reihe der Ehemaligentreffen). Eine Veranstaltung
hat Termine, Ort, Treffpunkt, einen Status (Entwurf → Angekündigt → Anmeldung offen → Anmeldung geschlossen → Läuft → Beendet
→ Archiviert) und eine oder mehrere **Rollen für Teilnehmende** (Kind, Nachwuchs-Betreuung, Team, Gast). Pro Rolle:

- **Plätze** (leer = unbegrenzt) und **Reserveplätze**
- **Mindestalter und Höchstalter** – berechnet zum Beginn der Veranstaltung oder zu einem anderen Stichtag
- **Anmeldung durch**: Erziehungsberechtigte, die Person selbst oder nur das Team

Anmeldungen sind standardmäßig **sofort verbindlich**. Schalten Sie das aus, dann sind Anmeldungen Anfragen, die das Team
bestätigt.

## Anreise mit dem Bus

In der Veranstaltung unter *Anreise mit dem Bus*: Schalten Sie **Für diese Veranstaltung ist ein Bus gebucht** ein und tragen Sie die **Sitzplätze** ein (ohne Fahrerin oder Fahrer).

- **Alle fahren mit dem Bus**, solange nichts anderes eingestellt ist: Wer teilnimmt (angemeldet, bestätigt), braucht einen Sitzplatz. Personen auf der
  Warteliste und stornierte Anmeldungen zählen nicht.
- Die Teilnehmenden der Veranstaltung und das Dashboard zeigen, wie voll der Bus ist, z. B. *Bus: 48 von 50 Plätzen belegt, 2 frei. Mit dem Auto: 6.* Sind
  mehr Personen im Bus, als er Plätze hat, wird die Zeile rot (**überbucht**). Sie nennt, wie viele zu viel sind und wie viele Erwachsene
  noch im Bus sitzen. Administration, Vorstand und die Lagerleitung der Veranstaltung sehen das.
- **Erwachsene** – ist der Bus zu klein, stellen Sie Erwachsene auf **Fährt mit dem Auto**: im Menü einer Zeile, oder Sie haken mehrere Zeilen an und wählen
  *Anreise mit dem Auto* unter *Mehrfachaktionen*. Die Zahl im Bus sinkt sofort.
- **Kinder** – ein Kind fährt normalerweise mit dem Bus. Bringen es die Erziehungsberechtigten selbst, z. B. weil das Lager auf dem Weg in ihren
  Urlaub liegt, stellen Sie das Kind auf dieselbe Weise auf *Fährt mit dem Auto*. Die Spalte *Anreise* zeigt dann *Auto (wird gebracht)*, und der Platz ist frei.
- *Fährt mit dem Bus* setzt jemanden wieder in den Bus.
- **Listen** (*Veranstaltungen → Listen*): Die *Busliste* enthält genau die Personen im Bus, mit Kästchen zum Abhaken für Hin- und
  Rückfahrt. *Anreise mit dem Auto* führt alle anderen an: Erwachsene mit ihrer Telefonnummer, Kinder mit der erziehungsberechtigten Person, die sie bringt, und
  deren Telefonnummer. So weiß das Team am Treffpunkt, wer nicht fehlt, und im Lager, wer noch ankommt.
- Die API liefert und nimmt `travel` (`bus` oder `car`) bei einer Teilnahme.

Ist kein Bus gebucht, wird nichts davon angezeigt.

## Gruppen, Zimmer und Dienste

In der Veranstaltung teilt **Gruppen, Zimmer, Dienste** (Schaltfläche oben) die Teilnehmenden ein – so oft wie nötig: z. B. eine Einteilung
*Gruppen* mit fünf Gruppen, eine Einteilung *Zimmer* mit den Zimmern des Hauses, eine Einteilung *Küchendienst* mit einer Schicht pro Tag.

1. **Neue Einteilung**: Wählen Sie die Art, einen Namen, wie viele Gruppen oder Zimmer und auf Wunsch die Plätze in jeder (bei Zimmern: die Betten). Wählen Sie,
   wer eingeteilt wird (z. B. Kinder und Nachwuchs-Betreuung) und worauf der Vorschlag achtet:
   - *Alter mischen* und *Geschlechter mischen* – für Gruppen und Dienste: Jede Gruppe bekommt jüngere und ältere Kinder und etwa denselben Anteil
     jedes Geschlechts.
   - *Geschlechter trennen* – für Zimmer: Niemand teilt ein Zimmer mit einem anderen Geschlecht. In einem Zimmer sind ähnliche Altersstufen zusammen.
   - *Geschwister* getrennt (üblich bei Gruppen), zusammen (üblich bei Zimmern) oder wie es kommt. Geschwister sind Kinder derselben Familie oder mit
     denselben Erziehungsberechtigten.
   - Nachwuchs-Betreuung und Teammitglieder werden zuerst verteilt: eine Person in jede Gruppe, bevor eine zweite dazukommt.
2. **Vorschlag für alle ohne Platz** füllt die Gruppen. Die Größen bleiben gleich, und die Plätze eines Zimmers werden nie überschritten. Wer
   nicht hineinpasst, bleibt unter *Noch nicht eingeteilt*.
3. **Von Hand ändern**: Jede Person hat eine Auswahl, mit der sie in eine andere Gruppe oder ein anderes Zimmer wechselt. Direkt im Kasten einer Gruppe
   können Sie sie umbenennen, eine Notiz ergänzen (z. B. wer sie leitet) oder ihre Plätze ändern. *Eine mehr* fügt eine Gruppe hinzu; eine leere kann entfernt werden.
4. Späte Anmeldungen erscheinen unter *Noch nicht eingeteilt*; der Vorschlag teilt nur sie ein und **verschiebt nie Personen, die schon einen Platz haben**.
   *Neu beginnen* nimmt alle heraus und schlägt neu vor. Wer storniert, fällt von selbst heraus.
5. Gelbe Hinweise zeigen, was zu prüfen ist: Personen ohne Platz, ein Zimmer mit mehr Personen als Plätzen, ein gemischtes Zimmer, Geschwister zusammen, wo
   sie getrennt sein sollten.
6. **Listen drucken** (oder *Veranstaltungen → Listen → Gruppen, Zimmer, Dienste*): jede Einteilung mit ihren Gruppen und Personen als PDF, XLSX oder CSV.

Der Vorschlag verwendet das Geschlecht und das Geburtsdatum der Person; Personen ohne Angabe zum Geschlecht werden eingeteilt, ohne darauf zu achten.
Die Lagerleitung der Veranstaltung kann einteilen; andere Rollen mit Zugriff auf die Veranstaltung sehen das Ergebnis.

## Warteliste

Sind die buchbaren Plätze einer Rolle vergeben, kommen weitere Anmeldungen auf die **Warteliste**, gereiht nach
Anmeldedatum. Erziehungsberechtigte sehen im Portal den Platz und die Länge der Liste („Platz 3 von 9 auf der Warteliste“).
Storniert jemand, rücken alle dahinter nach.

- **Reihung**: pro Rolle, Kriterien nacheinander – Vorrang, zuletzt dabei, Anzahl bisheriger Veranstaltungen. Zuletzt entscheidet
  immer das Anmeldedatum. Das Portal zeigt die Regel, aber nie die Gründe anderer.
- **Vorrang** (Geschwister nimmt teil, soziale Gründe, anderer Grund) setzt das Team; ein Geschwisterkind, das teilnimmt, zählt automatisch (nicht, wenn es
  sich selbst angemeldet hat). Vorrang und Notizen sind intern.
- *Veranstaltungen → Warteliste* zeigt die Reihung mit ihrem Grund und offene Angebote mit ihren Fristen. Das Team kann einen Platz anbieten (außer der Reihe
  nur mit Begründung), einen Reserveplatz vergeben, Vorrang setzen oder jemanden mit Begründung **überspringen**: Der Platz auf der Liste bleibt, aber es gibt keine
  Angebote, bis das zurückgenommen wird.
- Freie Plätze werden auch angeboten, nachdem die Anmeldung geschlossen wurde.

**Reserveplätze** vergibt das System nicht: Die Warteliste beginnt früher (buchbare Plätze = Plätze −
Reserveplätze). Nur das Team vergibt einen Reserveplatz an jemanden auf der Warteliste, mit einem internen Grund
(z. B. besondere Bedürfnisse). Die Reihung der anderen ändert sich dadurch nicht, und das Portal zeigt keine Reserveplätze.

Freie Reserveplätze können dort für die Warteliste **freigegeben** werden, mit Begründung. Mit *Reserveplätze: erinnern am* bekommen alle Administratorinnen
und Administratoren an diesem Tag eine Mail, wenn noch Reserveplätze frei sind; *An diesem Tag automatisch freigeben* ist standardmäßig aus.

## Portal für Erziehungsberechtigte

Erziehungsberechtigte registrieren sich unter `/register` mit Name und E-Mail-Adresse und erhalten einen Anmeldelink. Im Portal
legen sie Kinder an, sehen die offenen Veranstaltungen, die zum Alter des Kindes passen, melden an (oder setzen das Kind auf die Warteliste) und stornieren.
