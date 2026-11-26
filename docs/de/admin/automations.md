# Automatisierungen und Freigaben

Automatisierungen erledigen wiederkehrende Arbeit: freie Plätze der Warteliste anbieten, Rechnungen und Erinnerungen senden, Informationen
vor einer Veranstaltung, eine Veranstaltung abschließen. Jede Automatisierung hat einen **Auslöser**, eine **Aktion** mit Einstellungen und einen
**Modus**. Alles wird festgehalten: was geplant war, wer entschieden hat, was passiert ist.

*Automatisierungen → Automatisierungen* (Administration und Vorstand können bearbeiten; die Liste sehen alle Verwaltungsrollen).

## Modus der Ausführung

| Modus | Verhalten |
|---|---|
| **Freigabe erforderlich** (Voreinstellung für alles, was Mails oder Rechnungen sendet) | Die Freigebenden bekommen eine E-Mail „Automatisierung X würde jetzt Y tun“ mit der Liste der geplanten Aktionen. Nichts passiert, bis jemand entscheidet. |
| **Automatisch** | Läuft von selbst. Sicherheitsgrenze: Hat ein Lauf mehr Aktionen als *Höchstens … Aktionen ohne Freigabe* (Voreinstellung 200), wartet er trotzdem auf eine Freigabe. |
| **Nur manuell** | Läuft nur mit der Schaltfläche *Jetzt ausführen*. |

**Freigebende** sind alle mit einer der gewählten Rollen (Voreinstellung: Vorstand und Administration). Standardmäßig genügt eine Freigabe; mit
*Nötige Freigaben* müssen mehrere Personen freigeben (was eine von ihnen auslässt, bleibt ausgelassen), und eine Ablehnung bricht ab. Die anderen
Freigebenden erfahren, wer entschieden hat.

## Freigabe per E-Mail

- Die E-Mail enthält die geplanten Aktionen und die Schaltflächen **Ja, ausführen** und **Nein, abbrechen**. Jede Schaltfläche öffnet eine
  Seite – **durch das Öffnen eines Links wird nichts entschieden** (Mailprüfer öffnen Links automatisch). Auf dieser Seite
  bestätigen Sie, können einzelne Aktionen abwählen („mit Änderungen freigeben“) und einen Kommentar ergänzen.
- Jeder Link ist persönlich, funktioniert **einmal** und läuft mit der Anfrage ab (Voreinstellung 7 Tage, `KASMIN_APPROVAL_DAYS`).
  Ist der zweite Faktor eingeschaltet, geben Freigebende auf der Seite den Code ihrer Authenticator-App ein.
- Nach der halben Zeit bekommen die Freigebenden eine Erinnerung. Eine Anfrage, die ohne Entscheidung abläuft, bewirkt nichts; die
  Freigebenden werden informiert (Benachrichtigungsthema *Freigaben*).
- Solange eine Anfrage offen ist, legt der Scheduler für dieselbe Automatisierung und Veranstaltung keine zweite an.

Dieselben Entscheidungen sind unter *Automatisierungen → Läufe und Freigaben* möglich (das Menü zeigt die Zahl der offenen Anfragen).
Jeder Lauf zeigt seinen Plan und nach der Ausführung das Ergebnis pro Aktion.

## Probelauf und „Jetzt ausführen“

- **Probelauf** zeigt, was die Automatisierung jetzt tun würde, ohne etwas zu ändern.
- **Jetzt ausführen** startet sie sofort. Dürfen Sie diese Automatisierung freigeben, läuft sie direkt (Sie haben den Plan gesehen);
  sonst werden die Freigebenden gefragt. Ein Start von Hand ersetzt eine offene Freigabe-Anfrage derselben Automatisierung.

## Auslöser

| Auslöser | Wann |
|---|---|
| Nur per Schaltfläche | nie von selbst |
| Täglich | einmal am Tag nach der eingestellten Uhrzeit |
| Tage vor Beginn einer Veranstaltung | einmal pro Veranstaltung, ab *n* Tagen vor dem Beginn bis zum Beginn (auf Wunsch nur eine Veranstaltungsreihe) |
| Tage nach Ende einer Veranstaltung | einmal pro Veranstaltung, ab *n* Tagen nach dem Ende, eine Woche lang |
| Wöchentlich, monatlich, einmal an einem Tag | nach der eingestellten Uhrzeit; Tag 31 bedeutet in kürzeren Monaten den letzten Tag |
| Status einer Anmeldung ändert sich | auf einen der gewählten Status, auf Wunsch nur eine Reihe und bestimmte Rollen |
| Person kommt in eine Gruppe oder verlässt sie | die gewählte Gruppe |
| Neue Person, Feld einer Person ändert sich | nicht bei Importen und Demodaten |
| Wenn ein Platz frei wird | nach einer Stornierung, einem abgesagten oder abgelaufenen Angebot |

Der Scheduler (Cronjob, siehe [Hosting](hosting.md)) prüft die Automatisierungen jede Stunde (`kasmin:automations:run`).

## Eigene Automatisierungen

*Automatisierungen → Eigene Automatisierung*. Sie werden immer ausgeschaltet angelegt; prüfen Sie sie zuerst mit dem Probelauf.

1. **Auslöser** (siehe oben).
2. **Für wen**: die Person des Auslösers (bei Änderungen), Teilnehmende der Veranstaltung, Mitglieder einer Gruppe oder alle Personen, auf die
   die Bedingungen zutreffen. Ohne Bedingungen bedeutet *alle Personen* niemanden.
3. **Bedingungen**: dieselben Regeln wie bei Gruppen (Alter, Mitgliedschaft, Rolle, Gruppe, Anzahl der Veranstaltungen) und *Nur einmal pro Person*.
4. **Was passiert**, Schritt für Schritt: E-Mail senden, in eine Gruppe aufnehmen oder aus einer Gruppe entfernen, den Status der Anmeldung ändern (bestätigt,
   teilgenommen, nicht erschienen, storniert – nie an der Warteliste vorbei), eine Aufgabe für das Team (Benachrichtigungsthema *Aufgaben aus Automatisierungen*),
   Webhooks aufrufen (`automation.custom`), einen Rechnungsentwurf erstellen (diesen Schritt kann nur ergänzen, wer Rechnungen erstellen darf).

Automatisierungen können andere Automatisierungen starten; Ketten werden nach drei Schritten abgeschnitten, damit nichts im Kreis läuft. Ein Problem in einer Automatisierung
hält nie die Änderung auf, die sie gestartet hat. Mitgelieferte Automatisierungen können ausgeschaltet, aber nicht gelöscht werden.

## Zeitplan

*Veranstaltungen → Zeitplan* zeigt alles rund um eine Veranstaltung: ihre Termine, Runden, Fälligkeiten, Automatisierungen davor und danach (auch
ausgeschaltete) und die bisherigen Läufe. Das Dashboard zeigt die Läufe der nächsten 14 Tage.

## Neue Saison

*Veranstaltungen → Neue Saison* kopiert eine Veranstaltung und zeigt, bevor etwas angelegt wird, was passieren wird: Termine, Plätze, Preise, Zeitplan und wen
*Saison starten* anmelden würde. Die Saison kann im selben Schritt gestartet werden; wer das nicht freigeben darf, sendet nur die Anfrage.

## Freigaben per E-Mail

Freigebende bekommen **eine E-Mail mit allem, was auf sie wartet**, statt einer E-Mail pro Anfrage: alle Anfragen, die seit der letzten
Zusammenfassung gekommen sind, jede mit dem, was passieren würde, und eigenen Schaltflächen *Ja, ausführen* / *Nein, abbrechen*. Eine Anfrage, die nach der halben Zeit noch offen ist,
wird in der nächsten Zusammenfassung noch einmal genannt.

*Einstellungen → Organisation und Rechnungen → Freigaben: Zusammenfassung per E-Mail um*: die Uhrzeit (Voreinstellung 07:00). Mehrere Uhrzeiten sind möglich
(`07:00, 17:00`). Lassen Sie das Feld leer, um jede Anfrage sofort in einer eigenen E-Mail zu bekommen, wie bisher. Offene Freigaben sind immer
auf dem Dashboard und unter *Automatisierungen → Läufe und Freigaben* sichtbar, auch bevor die Zusammenfassung gesendet wird.

## Standard-Automatisierungen

Sie werden mit den Standardinhalten angelegt und sind **ausgeschaltet**, außer *Zahlungserinnerungen*,
*Rechnungen korrigieren* und *Anmelderunden* (diese schlagen nur vor, nichts wird ohne Freigabe gesendet). Prüfen Sie die Einstellungen und schalten Sie ein, was Sie brauchen.

| Automatisierung | Aktion | Voreingestellter Auslöser und Modus |
|---|---|---|
| Freie Plätze der Warteliste anbieten | die nächste Person auf der Warteliste bekommt ein Angebot mit Frist (Voreinstellung 7 Tage); Mail *Platzangebot von der Warteliste* | wenn ein Platz frei wird · Freigabe |
| Warteliste täglich prüfen | dasselbe, z. B. nachdem die Zahl der Plätze erhöht wurde | täglich 08:00 · Freigabe |
| Unbeantwortete Angebote verfallen lassen | nach der Frist gilt das Angebot als abgesagt, und der Platz geht an die nächste Person | täglich 07:30 · automatisch |
| Saison starten | Kinder der vorigen Veranstaltung derselben Reihe werden angemeldet, wenn ihr Alter noch passt; wer zu alt ist, wird für die nächste Rolle (z. B. Nachwuchs-Betreuung) oder zum Ausscheiden vorgeschlagen – als Hinweis an das Team. Kinder auf der Warteliste der vorigen Veranstaltung bleiben auf der Warteliste der neuen, mit dem Datum ihrer ersten Anmeldung. So verliert niemand den Platz | per Schaltfläche · manuell |
| Anzahlungsrechnungen / Restzahlungsrechnungen | Rechnungen der Rate `deposit` / `balance` pro Familie, ausgestellt und gesendet ([Rechnungen](invoices.md)) | per Schaltfläche / 90 Tage davor · Freigabe |
| Anmelderunden | Angebote in den Runden einer Veranstaltung ([Veranstaltungen planen](event-planning.md)); **aktiv** | täglich · Freigabe |
| Zahlungserinnerungen | Erinnerungen vor und Mahnungen nach der Fälligkeit, wie unter *Organisation und Rechnungen* eingestellt ([Rechnungen](invoices.md)); **aktiv** | täglich · Freigabe |
| Rechnungen korrigieren | Gutschrift und neue Rechnung, wo eine ausgestellte Rechnung nicht mehr passt; **aktiv** | täglich · Freigabe |
| Plätze unbezahlter Rechnungen freigeben | storniert Plätze, die einige Tage (Voreinstellung 14) nach der letzten Mahnung noch unbezahlt sind | täglich · Freigabe |
| Mitgliedsbeiträge | jährliche Mitgliedsbeiträge pro Familie | per Schaltfläche · Freigabe |
| Erinnerungen Sicherheitsblatt | Familien mit fehlendem oder nicht bestätigtem Sicherheitsblatt, alle 7 Tage, höchstens 4-mal, für Veranstaltungen in den nächsten 60 Tagen; danach eine Benachrichtigung „fehlt noch“ an das Team | täglich 18:00 · Freigabe |
| Informationen vor der Veranstaltung | Vorlage *Informationen zur Veranstaltung* an alle Familien | 14 Tage davor · Freigabe |
| Veranstaltung abschließen | angemeldete Teilnehmende werden zu „Teilgenommen“ | 1 Tag danach · Freigabe |
| Hinweis: Kind wird 14 | eine Benachrichtigung pro Kind an das Team | täglich 07:00 · automatisch |

## Angebote im Portal

Wird ein Platz angeboten, sehen ihn die Erziehungsberechtigten auf der Seite des Kindes mit **Platz annehmen** und
**Absagen**. Annehmen bucht den Platz (oder macht daraus eine Anfrage, wenn die Veranstaltung Anmeldungen von Hand bestätigt);
bei einer Absage oder ohne Antwort geht er an die nächste Person auf der Warteliste.
