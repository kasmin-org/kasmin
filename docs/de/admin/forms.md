# Formulare und Sicherheitsblatt

Kasmin hat frei einstellbare **Personenformulare**. Jede Bezeichnung und jeder Text wird einmal eingegeben, in der Sprache Ihrer Installation.
Mit den Standardinhalten werden zwei Formulare angelegt:

- **Sicherheitsblatt** – Versicherung, Ernährung, Unverträglichkeiten, Allergien (mit Schweregrad), Dauermedikamente (Name, Dosis,
  Zeitpunkt, wer sie gibt), besondere medizinische Bedürfnisse, Impfungen (Jahr / nein / unbekannt), Schwimmen, Einwilligungen zu Fotos
  (intern und Veröffentlichung getrennt), Hinweise für dieses Jahr. Gilt **bis zum Alter von 16 Jahren** (einstellbar) und
  muss **jedes Jahr bestätigt** werden.
- **Allgemeine Angaben** – T-Shirt-Größe und „Ich brauche neue T-Shirts“.

## Formulare bearbeiten

Administratorinnen und Administratoren bearbeiten Formulare unter *Formulare*: Felder mit Typ (Text, langer Text, Auswahl, Mehrfachauswahl, Ja/Nein, Zahl,
Datum, Jahr oder nein/unbekannt, Telefon, E-Mail, „Keine oder Text“, wiederholbare Gruppe, Person, Einwilligung), Bezeichnungen und **Hilfetexte (Tooltips)**,
Pflichtfeld, **Vertraulichkeit** (normal, Gesundheit, Ausweis-/Versicherungsnummer), Übernahme ins nächste
Jahr und Verwendung in Listen. Jede Änderung erzeugt eine neue **Version des Formulars**; bestehende Antworten behalten ihre.

„Keine“ ist eine echte Antwort und etwas anderes als „nicht beantwortet“.

- **Auswahlmöglichkeiten** haben einen Wert und einen Text, können „Andere“ mit Text erlauben und eine fertige Liste verwenden, z. B. die
  Krankenversicherungen in Österreich, Deutschland oder der Schweiz.
- **Zahlen und Datumsangaben** können einen kleinsten und größten Wert haben.
- **Nummer prüfen**: Textfelder können die Prüfziffer einer österreichischen, deutschen oder Schweizer Versicherungsnummer prüfen. Erweiterungen
  ergänzen weitere Formate und Listen in einem Service-Provider (`Formats::register()`, `ChoiceLists::register()`).
- **Person**: eine erziehungsberechtigte Person des Kindes oder eine andere Person, auf Wunsch mit ihrer Versicherungsnummer. Eine Nummer, die schon
  bei einem Geschwisterkind angegeben wurde, wird eingesetzt – nur bei einer Person, die für beide Kinder erziehungsberechtigt ist.

Neue Installationen mit der Zeitzone Wien, Berlin oder Zürich bekommen die Versicherungen und das Nummernformat dieses Landes.
Ein Update ändert bestehende Formulare nicht; ändern Sie die Felder im Formular selbst, wenn Sie die neuen Typen möchten.

Sobald es Antworten gibt, können nur Personen mit Zugriff auf die **Sicherheitsblätter** vertrauliche Felder entfernen oder umbenennen, sie
weniger vertraulich machen oder das Sicherheitsblatt löschen. Das Recht für Formulare allein öffnet nie Gesundheitsdaten.

## Export und Import

*Formulare → Exportieren* speichert die Definition eines Formulars als JSON-Datei (ohne Antworten), *Importieren* liest sie ein. Die Datei
enthält einen internen Schlüssel des Formulars und jedes Felds, den Kasmin einmal aus dem Namen oder der Bezeichnung bildet und nie anzeigt;
IDs sind in jeder Installation andere, die Schlüssel bleiben gleich. Ein Formular, das aus demselben Formular exportiert wurde – oder, wenn es
keines gibt, das Formular mit demselben Namen –, bekommt eine neue Version; Felder werden über ihren Schlüssel zugeordnet, Felder, die nicht in
der Datei stehen, werden entfernt, Antworten bleiben. Dasselbe Format gilt für die API (`GET`/`PUT /api/v1/forms/{form}/definition`,
`{form}` = ID oder Name) und MCP (`get_form_definition`, `save_form_definition`).

Formulare haben eindeutige Namen. Ein Feld behält seinen internen Schlüssel, wenn sich seine Bezeichnung ändert. Früher gegebene Antworten und
Platzhalter in Mailvorlagen funktionieren also weiter.

## Notfallkontakte

Die **Erziehungsberechtigten sind immer die ersten Notfallkontakte**. Sie werden automatisch mit ihren Telefonnummern übernommen.
Weitere Notfallkontakte (z. B. Großeltern) gehören zur **Familie** und erscheinen auf dem Sicherheitsblatt jedes
Kindes der Familie. Die Erziehungsberechtigten legen die Reihenfolge auf der Seite *Notfallkontakte* fest.

## Bestätigung und Verlauf

Erziehungsberechtigte füllen das Formular im Portal aus; die Werte des Vorjahres sind schon eingetragen. Ist die Bestätigung fällig,
können sie alles unverändert mit einem Klick bestätigen. Jedes Speichern und jede Bestätigung ist eine neue Version mit Datum und
Person; der Verlauf zeigt, welche Felder sich geändert haben.

Eine Veranstaltung kann eine **neuere Bestätigung** verlangen (*Veranstaltungen → Sicherheitsblatt nicht älter als … Tage*): Ab dieser Zahl von Tagen
vor dem Beginn zählen früher bestätigte Blätter als nicht bestätigt.

## Mails und Erinnerungen

Die Automatisierung *Erinnerungen Sicherheitsblatt* sendet zuerst die Bitte, das Blatt auszufüllen – oder die jährliche Bestätigung, wenn es
nur bestätigt werden muss –, und danach Erinnerungen. Nach der Bestätigung bekommt die erziehungsberechtigte Person eine Dankesmail; schalten Sie die
Mailvorlage aus, wenn Sie das nicht möchten. Bestehende Installationen bekommen die zwei neuen Vorlagen mit dem Update.

*Veranstaltungen → Listen → Fehlende Sicherheitsblätter* führt die Familien an; wählen Sie einige aus und senden Sie ihnen mit einem Klick eine Erinnerung
(braucht das Recht, Veranstaltungen oder Sicherheitsblätter zu bearbeiten; eine Familie wird innerhalb von 12 Stunden nicht zweimal erinnert).

Webhook `form.confirmed`: Formular, Person und Zeit, nie Werte oder Feldnamen.

## Vertraulichkeit

- Werte werden in der Datenbank **verschlüsselt**.
- Felder zu Gesundheit und Ausweis sind **vertraulich**: Sie erscheinen nie in Mails, Benachrichtigungen oder im Audit-Protokoll (das Protokoll
  hält fest, *welche* Felder sich geändert haben, nie die Werte). Die einzige Ausnahme: Eine erziehungsberechtigte Person kann **sich selbst** nach der Bestätigung
  eine Zusammenfassung senden, ohne Versicherungs- und Ausweisnummern (Voreinstellung: keine Zusammenfassung). Die Datei wird beim Senden der Mail
  erzeugt und nicht mit der Mail gespeichert; sie kann 48 Stunden lang gesendet werden.
- In der Verwaltung sehen nur die **Rolle Sicherheitsdaten** und **Administratorinnen und Administratoren** die Sicherheitsdaten; jede Ansicht wird protokolliert.
- Kinder mit eigenem Zugang sehen ihr Sicherheitsblatt, können es aber nicht ändern.
