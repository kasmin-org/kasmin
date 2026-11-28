# Datenschutz: Löschung, Aufbewahrung, Audit-Protokoll

## Löschanfragen

Personen können mit dem Link in jeder Mail die Löschung ihrer Daten beantragen. *Administration → Löschung und
Aufbewahrung* führt die offenen Anfragen an. **Prüfen** zeigt, was gelöscht wird (Kontaktdaten, Zugang, Formularangaben inkl.
Sicherheitsblätter, Mails, Verbindungen zu Familie und Gruppen) und was erhalten bleiben muss, mit dem Grund:

- Rechnungen innerhalb der gesetzlichen Aufbewahrungsfrist (Einstellung, Voreinstellung 7 Jahre) – der Name bleibt auf der Rechnung;
- Teilnahmen, anonymisiert, für Statistiken (Geburtsjahr, Rolle, Jahr);
- das Audit-Protokoll, das nicht geändert werden kann und die Anonymisierung festhält. **Ältere Einträge können noch den früheren
  Namen enthalten** (z. B. bei einer Namensänderung); Datensicherungen enthalten die Daten, bis sie gelöscht werden.

Namen werden auch aus Importprotokollen, Plänen von Automatisierungen, Benachrichtigungen, Webhook-Zustellungen, Antworten und aus
Rechnungen entfernt, deren Aufbewahrungsfrist abgelaufen ist. Personen mit einer aktiven Rolle müssen sie zuerst verlieren.

**Jetzt anonymisieren** ersetzt den Namen, kürzt das Geburtsdatum auf das Jahr und löscht den Rest. Das kann nicht
rückgängig gemacht werden. Informieren Sie die Person selbst.

## Aufbewahrung vertraulicher Daten

Angaben aus Formularen mit Gesundheits- oder Ausweisdaten (z. B. das Sicherheitsblatt) werden jede Nacht automatisch gelöscht, wenn die
letzte Veranstaltung der Person länger als die eingestellte Zahl von Jahren zurückliegt (Voreinstellung 7, *Administration → Organisation →
Aufbewahrung*) – außer die Person ist für eine kommende Veranstaltung angemeldet. Die Seite zeigt, was fällig ist, und kann
es sofort ausführen; jede Löschung wird protokolliert. Kommandozeile: `php artisan kasmin:privacy:retention [--dry-run]`.

## Audit-Protokoll

*Administration → Audit-Protokoll* (Administration und Rechnungsprüfung): jede Änderung, Anmeldung, Mail, jeder Export, jeder schreibende
API-/MCP-Aufruf, jeder Lauf einer Automatisierung und jede Anonymisierung, mit Zeit, Akteur (Person, API-Token, MCP, Automatisierung, System), Objekt,
IP-Adresse und ID der Anfrage. Filtern Sie nach Aktion (z. B. `login.`), Akteur und Datum. Das Protokoll kann nicht geändert oder gelöscht werden.

Das Protokoll enthält selbst personenbezogene Daten und wird deshalb knapp gehalten: Nach 30 Tagen wird die IP-Adresse auf ihren Netzwerkteil gekürzt und der Browser auf
seine Art (z. B. „Firefox, Windows“), und Einträge, die älter als 10 Jahre sind, löscht der nächtliche Lauf. Beide Fristen werden unter
*Administration → Organisation → Aufbewahrung* eingestellt (0 = nie).

## Einwilligungen und Abmeldungen

*Kommunikation → Einwilligungen und Abmeldungen* führt Einwilligungen zum Newsletter, Abmeldungen, Löschanfragen und erledigte
Löschungen mit Datum und Quelle an.
