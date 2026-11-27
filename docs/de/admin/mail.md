# Mail

## Versand: Mailkonto und Absender

*Kommunikation → E-Mail-Versand* ist eine Seite für die ganze Installation:

- **Absender**: der Name und die Adresse, mit denen jede Mail hinausgeht, und auf Wunsch eine Antwortadresse für die Antworten.
  Es gibt einen Absender. Vorlagen und Aussendungen wählen keinen aus.
- **Mailkonto**: wie die Mails gesendet werden – SMTP (SSL/TLS auf Port 465 oder STARTTLS auf 587, das Zertifikat wird immer
  geprüft) oder direkte Zustellung – und auf Wunsch IMAP. Nur mit IMAP („senden und empfangen“) kann Kasmin eine Kopie
  jeder gesendeten Mail in einem Ordner ablegen (z. B. `Sent`) und Antworten und Rückläufer Personen und Familien zuordnen. Die Versandgrenzen
  (pro Minute, Stunde, Tag) richten sich nach den Grenzen Ihres Anbieters – Mails über der Grenze gehen beim nächsten Durchlauf hinaus.
- *Testmail* prüft die Verbindung; *DKIM-Schlüssel erzeugen* ist für die direkte Zustellung.

Warum zwei Teile auf einer Seite: Das Konto ist die technische Verbindung (Server, Benutzername, Passwort), der Absender ist das, was
die Empfängerinnen und Empfänger sehen. Bis Version 0.27 gab es Listen mit mehreren Konten und mehreren „Absenderprofilen“, aus denen
pro Vorlage gewählt wurde. Eines von jedem genügt für eine Organisation und ist viel leichter richtig zu halten, deshalb gibt es die Listen nicht mehr; eine
Installation, die mehrere hatte, verwendet weiter ihr Standardkonto und dessen ersten Absender.

## Vorlagen

*Kommunikation → Mailvorlagen*: ein Text pro Vorlage, bearbeitet in einem Editor mit Formatierung; jedes Speichern ist eine neue
Version. Eine Vorlage hat keine Fassungen pro Sprache – eine Installation arbeitet in einer Sprache (siehe
[System](system.md#sprache)), und der Text ist einfach das, was Sie geschrieben haben.

Jede Vorlage gehört zu einer **Gruppe**, die sagt, worum es in der Mail geht – denn davon hängt ab, welche Platzhalter
es gibt. Eine Mail an alle kennt keine Rechnungsnummer:

| Gruppe | Wofür | Platzhalter zusätzlich zu den allgemeinen |
|---|---|---|
| Allgemein | Mail an alle oder an eine Gruppe, Newsletter, Mails zum Konto | keine |
| Veranstaltung und Formulare | Infos zu einer Veranstaltung, Sicherheitsblatt (Bitte, Erinnerung, Dank) | Veranstaltung |
| Anmeldung und Warteliste | Bestätigung, Wartelistenplatz, Platzangebot, Abmeldung | Veranstaltung, Anmeldung |
| Rechnung und Zahlung | Rechnung, Zahlungserinnerung, Mahnung, Zahlungsbestätigung | Rechnung, Veranstaltung |
| Mitgliedschaft und Gruppen | Platzangebot einer Gruppe | Gruppe |

Die allgemeinen gibt es in jeder Gruppe: Anrede, Empfänger:in, Familie, Kinder, Organisation, Links. Die Liste der
Vorlagen ist nach Gruppe gegliedert. *Platzhalter einfügen* und *Bedingung einfügen* bieten nur die Platzhalter der Gruppe
der Vorlage an, und das Speichern lehnt einen Platzhalter einer anderen Gruppe mit einer Meldung ab, die ihn nennt. Die Gruppe einer
Standardvorlage ist fest; bei eigenen Vorlagen wählen Sie sie.

Niemand muss die Platzhalter oder ihre Schreibweise auswendig kennen:

- **Platzhalter einfügen** (Schaltfläche des Editors, auch neben dem Betreff und dem HTML-Quelltext) führt jeden
  Platzhalter mit einem Namen und einem Beispiel an und setzt den gewählten an die Stelle der Schreibmarke.
- **Bedingung einfügen** baut ein „Wenn“: Wählen Sie das Feld (zum Beispiel *Veranstaltung: Ort*), den Vergleich (ausgefüllt / ja,
  leer / nein, gleich, ungleich, größer als, kleiner als), bei Bedarf einen Wert, den Text für „dann“ und auf Wunsch
  den Text für „sonst“. Kasmin schreibt das in der richtigen Schreibweise in die Vorlage.
- **Fehler verhindern das Speichern** und werden beim Feld rot genannt, in einfachen Worten: ein Platzhalter, der nicht geschlossen ist,
  eine Klammer ohne ihr Gegenstück, ein `if` ohne `endif`, ein unbekannter Platzhalter. Alle Fehler werden auf einmal angeführt.

Die Platzhalter (abgeschottet, es kann kein Code ausgeführt werden; vertrauliche Felder wie Gesundheitsdaten stehen nicht
zur Verfügung):

| Platzhalter | Inhalt |
|---|---|
| `{{ salutation }}` | „Guten Tag Vorname Nachname,“ für Erwachsene (förmlich), „Hallo Vorname,“ für Kinder – geschlechtsneutral |
| `{{ recipient.first_name }}`, `.last_name`, `.full_name`, `.age` | die Empfängerin oder der Empfänger |
| `{{ children }}` | Liste der Kinder, um die es in der Mail geht (`{% for child in children %}{{ child.first_name }}{% endfor %}`) |
| `{{ family.name }}` | Familie |
| `{{ event.title }}`, `.starts_at`, `.ends_at`, `.location`, `.meeting_point` | Veranstaltung |
| `{{ registration.status }}`, `.waitlist_position`, `.waitlist_total`, `.child` | Anmeldung |
| `{{ invoice.number }}`, `.total`, `.open`, `.paid`, `.due_on`, `.reference`, `.iban`, `.overdue`, `.children` | Rechnung (Mails zu Rechnung, Erinnerung und Zahlung), siehe [Rechnungen](invoices.md) |
| `{{ organisation.name }}` | Ihre Organisation |
| `{{ links.login }}`, `{{ links.portal }}` | persönlicher Anmeldelink (eingeschränkt, 30 Tage gültig) und Portal |

Erlaubt sind `{% if %}`, `{% for %}`, `{% set %}` und die Filter `upper`, `lower`, `capitalize`, `title`, `default`,
`join`, `length`, `first`, `last`, `trim`, `nl2br`.

- **Schaltflächen**: Ein Link allein in einem Absatz wird zu einer Schaltfläche in der Farbe der Organisation. `{{ links.login }}` kann
  als Ziel eines Links eingetragen werden.
- **Bilder** (PNG, JPG, GIF, 2 MB) werden im Editor eingefügt und beim Senden in die Mail eingebettet.
- **HTML-Quelltext**: ein Schalter unter dem Editor, für alles, was der Editor nicht kann.
- **Vorschau**: Wählen Sie eine Person aus der Liste (Tippen grenzt sie ein) und eine Veranstaltung; die Vorschau zeigt die Mail so, wie diese
  Person sie bekäme, für Computer und Handy. *Testmail an mich* sendet sie an Sie selbst. Persönliche
  Links anderer werden nie gezeigt oder gesendet. Das HTML einer Mail wird immer in einem abgeschotteten Frame angezeigt.
- **Versionen** (in der Vorlage): frühere Texte ansehen und wiederherstellen, auch den ersten.
- **Mit Standard vergleichen**: zeigt den Unterschied zum mitgelieferten Text. *Neuer Standard* erscheint, wenn ein Update einen neuen Text gebracht hat;
  übernehmen Sie ihn oder behalten Sie Ihren eigenen. Updates ändern Ihre Texte nie.

## Aussehen

*Einstellungen → Organisation und Rechnungen → Aussehen der E-Mails*: Logo, Farbe und Fußzeile aller Mails.

## Aussendungen

*Kommunikation → Aussendungen*: Vorlage (jene der Gruppe *Allgemein*; mit den Teilnehmenden einer Veranstaltung als Empfängerkreis
auch *Veranstaltung und Formulare* – andere Gruppen brauchen Daten, die eine Aussendung nicht hat, und Mails des Systems werden nie angeboten), Empfängerkreis (Gruppen, Teilnehmende einer Veranstaltung mit Status), senden an
Erziehungsberechtigte / die Person selbst / beide, eine Mail pro Familie. *Vorbereiten* erstellt alle Mails und hält sie zurück: Blättern Sie sie durch,
bearbeiten Sie auf Wunsch einzelne Mails, dann *Senden*. Wer sich abgemeldet hat, keine Adresse oder eine unzustellbare Adresse hat, wird ausgenommen und angeführt.

*Durchsehen* zeigt die fertigen Mails eine nach der anderen, wer ausgenommen ist, und den Fortschritt beim Senden. Eine Aussendung kann dort angehalten,
fortgesetzt und abgebrochen werden.

## Unzustellbare Adressen

*Kommunikation → Unzustellbare Adressen* führt Adressen an, die es nicht gibt (dauerhafter Fehler oder Rückläufer; Hinweise auf eine Verzögerung zählen nicht).
Aussendungen lassen sie aus; Rechnungen und andere Einzelmails werden trotzdem versucht. Die Markierung verschwindet, wenn die Adresse geändert wird, oder mit
*Adresse funktioniert wieder*. Rückläufer brauchen ein Konto mit IMAP.

## Aufbewahrung

*Einstellungen → Aufbewahrung → Mailprotokoll: Jahre*: Jahre, nach denen erledigte Mails gelöscht werden (0 = solange es die Person gibt). Mails mit
Rechnungen bleiben mindestens so lange wie die Rechnungen.

## Versand

Mails laufen über eine Warteschlange, die der Scheduler bei jedem Durchlauf (alle 5 Minuten) abarbeitet (`php artisan schedule:run` über Cron oder die Adresse
für Web-Cron, siehe [Hosting](hosting.md)). Fehlgeschlagene Versuche werden mit wachsenden Wartezeiten wiederholt; das Mailprotokoll unter
*Kommunikation → Mailprotokoll* zeigt jede Mail mit ihrem Status und kann sie erneut senden. Erziehungsberechtigte sehen die Mails, die an sie
gesendet wurden, im Portal.

## Links in der Fußzeile und Einwilligung

Jede Mail enthält einen Link zu den E-Mail-Einstellungen der Empfängerin oder des Empfängers: den Newsletter abbestellen, keine Informationsmails
mehr erhalten oder die Löschung der Daten beantragen. Das Öffnen des Links ändert nichts; es muss eine Schaltfläche gedrückt werden. Löschanfragen
werden nicht automatisch ausgeführt – Administratorinnen und Administratoren werden benachrichtigt und entscheiden, was nach dem Gesetz aufbewahrt werden muss.

## Benachrichtigungen für die Verwaltung

*System → Meine Benachrichtigungen*: Alle mit Zugang zur Verwaltung wählen pro Thema. Angeboten werden nur Themen, für die die nötigen Rechte da sind,
und die Rechte werden bei jedem Senden noch einmal geprüft. Administratorinnen und Administratoren, die nichts gewählt haben, bekommen Löschanträge
sofort und Fehler in Automatisierungen einmal am Tag. Die Seite zeigt auch die letzten Benachrichtigungen.

Themen (neue Anmeldungen, Warteliste, geänderte Angaben, Sicherheitsblätter, unzustellbare Mails, Löschanträge …) können Sie
sofort erhalten oder als tägliche Zusammenfassung (07:00, die letzten 24 Stunden) oder wöchentliche Zusammenfassung (montags, die letzten 7 Tage). Sie enthalten nie
vertrauliche Werte.

## Testmodus

*Einstellungen → Organisation und Rechnungen → Mail-Testmodus: alles senden an* (Administratorinnen und Administratoren; `KASMIN_MAIL_TEST_ADDRESS` für Docker): **Jede** Mail geht an
diese Adresse, auch Anmeldelinks. Der Betreff zeigt die echte Empfängeradresse, und die Verwaltung zeigt einen Hinweis. Stellen Sie sicher, dass Sie
dieses Postfach lesen können, sonst kann sich niemand anmelden.

## Anhänge

Vorlagen und Aussendungen können bis zu fünf Dateien haben (PDF, JPG, PNG, je 5 MB); Aussendungen können auch Dokumente von Veranstaltungen verwenden. Fehlt eine
Datei, wird die Mail nicht gesendet, und der Fehler steht im Mailprotokoll.

## Freigabe großer Aussendungen

Aussendungen mit mehr Mails, als unter *Einstellungen* eingestellt ist (Voreinstellung 50, 0 = nie), warten auf eine Freigabe, wie Automatisierungen: Die Freigebenden bekommen eine
Mail, und nichts wird gesendet, bevor jemand entscheidet.

## Private Anmeldungen

Ist jemand nur wegen einer Anmeldung mit dem eigenen Zugang im Empfängerkreis, geht die Mail an diese Person und nicht an die
Erziehungsberechtigten (außer *Was ein Kind selbst anmeldet* steht auf „ist für die Erziehungsberechtigten sichtbar“).

## Abbestellen

Der Link in der Fußzeile bietet an: Newsletter, alle Informationsmails, einzelne Veranstaltungsreihen und Gruppen, Löschung der Daten. Mailprogramme können
mit einem Klick abbestellen. Die letzte Auswahl zählt. Rechnungen und Mails zu eigenen Anmeldungen werden immer gesendet.
