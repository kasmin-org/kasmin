# Anmeldung: Fragen, öffentliche Formulare, Familien

## Fragen einer Veranstaltung

1. *Formulare → Neu*: Art **Fragen einer Veranstaltung**. Jede Frage wird *pro teilnehmender Person* oder *einmal pro Familie*
   gestellt; Einwilligungen, die als Pflicht markiert sind, müssen gegeben werden.
2. *Veranstaltungen → Bearbeiten → Rollen, Plätze und Altersgrenzen*: Wählen Sie das Formular unter **Fragen bei der Anmeldung**.
   Jede Rolle kann ihr eigenes Formular haben.

Antworten können bis **Antworten änderbar bis** geändert werden (Voreinstellung: Beginn der Veranstaltung). Sie erscheinen auf der Liste
*Antworten*, bei der teilnehmenden Person (*Antworten*) und in Mails als `{{ registration.answers.<key> }}`. Felder mit der
Vertraulichkeit *Gesundheit* oder *Ausweis-/Versicherungsnummer* werden verschlüsselt. Nur die Rolle Sicherheitsdaten sieht sie, und
sie kommen nie in Mails oder auf diese Liste. Der Text einer Einwilligung wird so gespeichert, wie er angezeigt wurde.

Vor der Anmeldung sehen Erziehungsberechtigte Plätze, Preis, Raten und Stornobedingungen.

## Öffentliche Formulare

*Formulare → Öffentliche Formulare*: Adresse `/f/<name>`, Titel und Text in beiden Sprachen, Beginn und Ende.

| Art | Nach der Bestätigung |
|---|---|
| Anmeldung zu einer Veranstaltung | die Person wird in der gewählten Rolle angemeldet (Altersgrenzen, Plätze und Warteliste gelten) |
| Bewerbung | wartet unter *Formulare → Einsendungen*; nach der Freigabe: Mitgliedschaft, Gruppe und Willkommensmail. Ohne Freigabe geschieht das sofort |
| Informiert bleiben | Einwilligung zum Newsletter, auf Wunsch eine Gruppe |

Nichts passiert, bevor der Link in der Mail geöffnet **und** mit der Schaltfläche bestätigt wurde (3 Tage gültig). Bekannte Personen
werden an Adresse **und** Name erkannt; ihre Daten werden nie überschrieben. Die Seite zeigt nie, ob eine Adresse bekannt ist.
Eingesendete Daten werden verschlüsselt und gelöscht, sobald sie ausgeführt, abgelehnt oder abgelaufen sind.

## Erwachsene und Gäste

Rollen, bei denen sich *die Person selbst* anmeldet, erscheinen im Portal unter *Meine Anmeldungen*. Mit **Gäste** in der Veranstaltung
kann eine teilnehmende Person so viele Gäste einladen: Jeder Gast bekommt einen persönlichen Link, meldet sich selbst an und bekommt
eine eigene Rechnung.

## Familien

*Einstellungen → Organisation und Rechnungen → Familien*:

| Einstellung | Voreinstellung |
|---|---|
| Erziehungsberechtigte pro Familie (höchstens) | 5 – Erziehungsberechtigte laden einander im Portal ein; Administratorinnen und Administratoren werden benachrichtigt |
| Eigener Zugang ab Alter | 14 – Erziehungsberechtigte legen den Zugang für ihr Kind an; das Kind sieht sein Sicherheitsblatt, kann es aber nicht ändern |
| Erziehungsberechtigung endet mit Alter | 18 – beide Seiten bekommen eine Mail; künftige Rechnungen gehen an die Person |
| Was ein Kind selbst anmeldet | bleibt verborgen; oder die Erziehungsberechtigten erfahren, dass es eine Anmeldung gibt; oder ist sichtbar |
| Auf öffentlichen Formularen nach dem Newsletter fragen | ein |

## Seite der Person und Seite der Familie

- *Personen → (Person)* zeigt die Stammdaten und einen **Überblick**: Erziehungsberechtigte, Kinder, Familien, Rollen, Gruppen, Mitgliedschaften,
  Veranstaltungen mit Rolle und Status, Rechnungen, Einwilligungen und die Anmeldung (Adresse, letzte Anmeldung, zweiter Faktor). Veranstaltungen,
  Rechnungen und Einwilligungen erscheinen nur für Rollen mit dem Recht dafür; Antworten aus Formularen wie dem Sicherheitsblatt bleiben bei der
  Rolle Sicherheitsdaten.
- *Familien → (Familie)* enthält die Adresse, die Mitglieder und wer die Rechnungen bekommt. Die Seite zeigt außerdem die Notfallkontakte der Familie
  (Rolle Sicherheitsdaten), ihre Rechnungen (Finanzrollen) und die letzten Mails an sie.

## Doppelte Einträge

*Personen → Doppelte Einträge* (Administration, Vorstand) führt wahrscheinliche Doppelte an: gleicher Name, ähnlicher Name und gleiches
Geburtsdatum, oder gleiche Adresse und ähnlicher Vorname. Mit **Zusammenführen** wählen Sie jedes Feld aus; alles andere wechselt zum
behaltenen Eintrag. Das kann nicht rückgängig gemacht werden. Ein Zusammenführen gibt nie mehr Rechte. Es wird abgelehnt, wenn beide
Einträge eine verrechnete Anmeldung zur selben Veranstaltung haben.

## Portal: Meine Daten

Erziehungsberechtigte ändern Telefonnummern, E-Mail-Adressen, die Adresse der Familie und die Person, die die Rechnungen bekommt, selbst; wer das Thema
*Geänderte Angaben* abonniert hat, wird benachrichtigt. Eine neue E-Mail-Adresse zählt, nachdem sie mit einem Link bestätigt wurde (24 Stunden); alle anderen
Adressen der Person werden informiert. Links, die an die alte Adresse für die Anmeldung gesendet wurden, enden. Die Mail mit dem Link und die Seite, die er öffnet, sagen beide,
welche Adresse durch welche ersetzt wird oder dass eine Adresse dazukommt.

Wer in der Verwaltung arbeitet und zu keiner Familie gehört, wird zur Verwaltung geleitet: Die Startseite führt dorthin, und das Portal sagt das,
statt nach einer Familie zu fragen.

### Geschlecht eines Kindes

Wenn Erziehungsberechtigte ein Kind anlegen, müssen sie das Geschlecht wählen: weiblich, männlich oder divers – damit werden die Zimmer geplant, deshalb wird „keine Angabe“
dort nicht angeboten. *Einstellungen → Organisation und Rechnungen → Familien* enthält pro Geschlecht einen optionalen Hinweis, der unter der Auswahl erscheint, sobald dieses
Geschlecht gewählt wird. Weiblich und männlich haben standardmäßig keinen; divers hat diesen, den Sie ändern oder leeren können:

> Wir trennen die Kinder in den Zimmern nach Geschlecht. Bitte melden Sie sich direkt bei uns und sagen Sie uns, in welchem Zimmer das Kind schlafen möchte, damit wir
> gut planen können. Gibt es genug diverse Kinder, kommen sie gemeinsam in ein Zimmer.

*Daten herunterladen* liefert eine Datei mit allem, was über die Person und die Kinder gespeichert ist (Art. 15 und 20 DSGVO), ohne Daten, die der
Person verborgen sind. Administratorinnen und Administratoren können sie auf der Seite der Person erstellen.

## Stornierungen und Bestätigungen

- Wird eine Anmeldung storniert – durch die Erziehungsberechtigten, durch das Team oder weil eine Rechnung nicht bezahlt wurde –, bekommt die Familie die Mail
  *Bestätigung der Abmeldung*. Ein Angebot, das nicht rechtzeitig beantwortet wurde, endet mit der Mail *Platzangebot abgelaufen*. Beide Texte sind
  Mailvorlagen und können geändert werden.
- Nach der Veranstaltung gibt es die **Teilnahmebestätigung** als PDF: für das Team bei den Teilnehmenden der Veranstaltung
  (*Teilnahmebestätigung*) und für die Person oder die Erziehungsberechtigten im Portal neben der Anmeldung. Sie nennt die
  Person, die Veranstaltung, den Zeitraum und den bezahlten Betrag, z. B. für Arbeitgeber oder Versicherungen, die einen Teil der Kosten erstatten.

## Schutz vor Spam

Öffentliche Formulare haben ein verstecktes Feld für Bots, eine Grenze pro Adresse und einen Arbeitsnachweis: Der Browser löst eine kleine Aufgabe (etwa eine Sekunde), bevor er
sendet. Das braucht JavaScript und HTTPS, und kein Dritter ist beteiligt. `KASMIN_PROOF_OF_WORK=false` schaltet es ab. Für die API enthält die Definition des
Formulars eine `challenge`; senden Sie `pow` als `<challenge>:<number>`, sodass der SHA-256 dieses Textes mit 14 Nullbits beginnt.
