# Rollen und Rechte

## Rollen zuweisen

*Personen → Person → Rollen* (nur Administratorinnen und Administratoren): Rolle, von, bis. Eine Person mit E-Mail-Adresse bekommt
automatisch einen Zugang. Bitte ernennen Sie mindestens **zwei Administratorinnen oder Administratoren**, damit die Organisation
nicht von einer Person abhängt. Die letzte Administration kann nicht entfernt werden.

## Rechte

*Administration → Rollen* (nur Administratorinnen und Administratoren): Zugriff pro Bereich – kein Zugriff, ansehen oder bearbeiten –
und eigene Rollen. Voreinstellung:

| Bereich | Administration | Vorstand | Finanzen | Sicherheitsdaten | Lagerleitung | Rechnungsprüfung |
|---|---|---|---|---|---|---|
| Personen, Familien, Gruppen | bearbeiten | bearbeiten | ansehen | ansehen | ansehen | – |
| Veranstaltungen und Teilnehmende | bearbeiten | bearbeiten | nur Preise | ansehen | bearbeiten | nur Liste |
| Formulare | bearbeiten | bearbeiten | – | – | – | – |
| Finanzen | bearbeiten | bearbeiten | bearbeiten | – | – | ansehen |
| Aussendungen und Mailprotokoll | bearbeiten | bearbeiten | – | – | – | – |
| Mailkonten und Absender | bearbeiten | – | – | – | – | – |
| Automatisierungen | bearbeiten | bearbeiten | – | – | – | – |
| Einstellungen | bearbeiten | bearbeiten | Rechnungsdaten | – | – | – |
| Datenschutz und Einwilligungen | bearbeiten | bearbeiten | – | – | – | ansehen |
| Sicherheitsblätter und Gesundheitsdaten | bearbeiten | – | – | bearbeiten | – | – |
| Protokoll | bearbeiten | – | – | – | – | ansehen |

Nur für Administratorinnen und Administratoren, gleich welche Rechte eine Rolle hat: Rollen, API-Tokens, Webhooks, Systemstatus,
öffentliche Adresse, Websites für die Einbettung, Zeitzone und KI-Einstellungen.

## Geschützte Zugänge

Die E-Mail-Adresse einer Person mit Verwaltungsrolle ist der Schlüssel zur Verwaltung. Nur Administratorinnen und Administratoren
und die Person selbst können sie ändern. Das gilt auch für Importe und für das Zusammenführen doppelter Einträge. Nur
Administratorinnen und Administratoren können eine solche Person löschen.

## Nur eigene Veranstaltungen

Eine Rolle kann für **Alles** gelten oder **Nur eigene Veranstaltungen** (*Administration → Rollen → Gilt für*). Lagerleitung und
Betreuung sind auf eigene Veranstaltungen eingestellt, auch in bestehenden Installationen; eine Administratorin oder ein
Administrator kann das ändern.

Eigene Veranstaltungen sind Veranstaltungen,

- für die die Rolle zugewiesen wurde (*Person → Rollen → Veranstaltung*), und
- an denen die Person im Team oder als Nachwuchs-Betreuung teilnimmt. Wer sich nur selbst angemeldet hat, zählt erst, wenn das Team
  die Anmeldung bestätigt.

Sie zählen bis 180 Tage nach dem Ende (*Administration → Organisation → Eigene Veranstaltungen: Tage Zugriff nach dem Ende*). Personen mit einer solchen Rolle sehen diese Veranstaltungen, deren Teilnehmende und die Erziehungsberechtigten dieser Teilnehmenden,
die Listen und den Zeitplan. Hat sich ein Kind selbst angemeldet, werden seine Erziehungsberechtigten nicht angezeigt. Alles andere ist gesperrt: andere
Veranstaltungen, Familien, Gruppen, Importe, Statistik, Aussendungen, die Liste „Zu erledigen“ und die Suche nach anderen Personen.
Gesundheitsdaten brauchen zusätzlich das Recht *Sicherheitsblätter und Gesundheitsdaten* in der Rolle und gelten dann ebenfalls nur
für die eigenen Veranstaltungen.

**Betreuende** haben keinen Zugang zur Verwaltung. Sie finden *Meine Veranstaltungen (Team)* im Portal, mit Teilnehmenden, Kontakten,
Busliste und Geburtstagen. Jede Ansicht wird protokolliert, und ein Link in einer Mail reicht nicht, um sie zu öffnen.
