# Vorlagen, Kopien, Termine, öffentliche Seiten und Runden

## Vorlagen und Kopien

- *Veranstaltungen → Veranstaltungsvorlagen*: alles, womit eine neue Veranstaltung beginnt (Rollen, Altersgrenzen, Preise, Ratenplan,
  Stornobedingungen, Fragen, Dokumente, Runden). Mit den Standardinhalten werden drei Arten angelegt.
- *Veranstaltungen → Aus Vorlage* legt eine Veranstaltung an; *Kopieren* bei einer Veranstaltung erstellt die nächste; *Als Vorlage
  speichern* behält sie als Art.
- Alle Termine verschieben sich um gleich viele Tage. Personen, Rechnungen und Kosten werden nie kopiert. Eine neue Veranstaltung ist
  ein Entwurf.

## Status nach Termin

*Ankündigen am*, *Anmeldung öffnet* und *Anmeldung schließt* ändern den Status, ebenso der Beginn (*Läuft*) und das Ende
(*Beendet*). Jeder Termin wirkt einmal: Eine spätere Änderung von Hand bleibt. Jede Änderung steht im Audit-Protokoll.

## Dokumente und Bild

Dokumente (PDF, JPG, PNG, bis 10 MB) sind für Teilnehmende und ihre Erziehungsberechtigten im Portal sichtbar oder für alle auf
der öffentlichen Seite. Personen auf der Warteliste sehen sie nicht. Die Dateien werden nicht öffentlich gespeichert.

## Öffentliche Seite und Einbettung

*Öffentliche Seite* zeigt `/events/<id>`: Termine, Ort, Preise, Plätze, öffentliche Dokumente und die Schaltflächen zur Anmeldung.
Entwürfe und archivierte Veranstaltungen werden nie angezeigt.

Um die Seite oder ein öffentliches Formular auf Ihrer Website zu zeigen, tragen Sie die Website unter *Einstellungen → Organisation
und Rechnungen → Websites, die öffentliche Seiten einbetten dürfen* ein und verwenden Sie `?embed=1`:

```html
<iframe src="https://camp.example.org/f/alumni?embed=1" style="width:100%;height:900px;border:0"></iframe>
```

Alle anderen Seiten können nie in Frames angezeigt werden. Die Anmeldung von Kindern öffnet sich außerhalb des Frames, weil sie
einen Zugang braucht.

## Anmelderunden

Ohne Runden hängt die Anmeldung nur vom Status ab. Mit Runden für eine Rolle:

| Runde | Was passiert |
|---|---|
| Wiederkehrende | Personen, die bei der vorigen Veranstaltung der Reihe in dieser Rolle dabei waren, bekommen ein Angebot (das Alter muss passen) |
| Auswahl | Personen mit mindestens *n* Teilnahmen als Kind in der Reihe bekommen Angebote: zuerst das Vorjahr, dann die Anzahl der Teilnahmen, dann die früheste Anmeldung; auf Wunsch nur Mitglieder einer Gruppe |
| Warteliste | ab ihrem Beginn werden freie Plätze der Warteliste angeboten |
| Offen für alle | Plätze werden direkt vergeben; davor kommen neue Anmeldungen auf die Warteliste |

Die Automatisierung *Anmelderunden* läuft täglich und **bittet um Freigabe**, bevor etwas gesendet wird; jede Familie bekommt eine Mail.

- *Antwort im Portal*: annehmen oder absagen, bis die Runde schließt.
- *Bezahlung der ersten Rate*: Die Rechnung wird mit dem Angebot gesendet, die Zahlung nimmt den Platz an. Unbezahlte Plätze werden
  drei Tage nach der Frist freigegeben, weil Überweisungen Zeit brauchen; die Rechnung wird dann zur Korrektur vorgeschlagen.

Wer abgesagt hat, bekommt kein zweites Angebot. *Teilnehmende → Platz anbieten* bietet außer der Reihe an, mit Begründung.
