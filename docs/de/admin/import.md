# Personen und Familien importieren

Kasmin importiert Personen, Erziehungsberechtigte, Familien, Gruppen, Mitgliedschaften und frühere Teilnahmen aus einer CSV- oder Excel-Datei,
aus **Webling**, über die REST-API oder durch einen KI-Assistenten (MCP). Jeder Import:

- kann zuerst als **Probelauf** laufen: was angelegt, aktualisiert oder übersprungen würde, mit Fehlern pro Zeile;
- bildet **Familien** aus flachen Listen (siehe unten);
- ist **wiederholbar**: Zeilen behalten ihre Quelle und `source_id`. Derselbe Export kann also noch einmal importiert werden (z. B. wöchentlich
  bis zum Umstieg), ohne dass doppelte Einträge entstehen; Änderungen in der Quelle aktualisieren die Person;
- ist ein **Import**, der **zurückgerollt** werden kann (*Personen → Importe → Zurückrollen*), solange danach nichts geändert
  wurde.

Gesundheits- und Sicherheitsdaten werden auf diesem Weg nicht importiert.

## Import-Assistent

*Personen → Personen importieren*:

1. **Quelle**: eine CSV- oder Excel-Datei, deren erste Zeile die Spaltennamen enthält, dazu ein Name der Quelle (z. B. `old-list`); oder
   Webling mit seiner Adresse und einem API-Schlüssel mit Leserecht (wird nicht gespeichert). Dateien werden gleich nach dem Einlesen gelöscht; die Zeilen bleiben höchstens eine Stunde im Zwischenspeicher, während Sie im Assistenten arbeiten.
2. **Felder zuordnen**: Wählen Sie für jedes Kasmin-Feld die Spalte. Spalten, die wie die Felder heißen (oder wie die
   Standardfelder von Webling), werden automatisch zugeordnet.
3. **Probelauf**, Fehler und Vorschläge für Familien prüfen, dann **Importieren**.

## Felder

| Feld | Bedeutung |
|---|---|
| `source_id` | ID im Quellsystem (nötig für wiederholte Importe) |
| `first_name`, `last_name` | Pflicht |
| `birth_date` | `YYYY-MM-DD` oder `DD.MM.YYYY` |
| `gender` | |
| `email`, `phone` | der Person selbst (Erwachsene; sie bekommen einen Zugang) |
| `street`, `postal_code`, `city`, `country` | Adresse der Familie |
| `guardian1_first_name`, `guardian1_last_name`, `guardian1_email`, `guardian1_phone` | erste erziehungsberechtigte Person (macht die Zeile zu einem Kind; die erziehungsberechtigte Person bekommt einen eigenen Eintrag und einen Zugang) |
| `guardian1_name` | wahlweise der ganze Name in einem Feld („Vorname Nachname“) |
| `guardian2_…` | zweite erziehungsberechtigte Person, dieselben Felder |
| `family` | optionaler Schlüssel der Familie: Zeilen mit demselben Schlüssel bilden eine Familie |
| `groups` | Namen von Gruppen, durch Beistriche getrennt (werden angelegt, wenn sie fehlen) |
| `membership_type`, `membership_start`, `membership_end` | Mitgliedschaft (Schlüssel der Art, z. B. `child`) |
| `participations` | frühere Teilnahmen: `Titel der Veranstaltung:role:status;…`, z. B. `Summer camp 2026:child:attended` (die Veranstaltung muss es geben) |

## Familien bilden

Zeilen werden zu einer Familie verbunden, wenn sie eine **E-Mail-Adresse**, eine **Telefonnummer** oder **dieselben
Erziehungsberechtigten** (mit demselben Nachnamen) gemeinsam haben – Sicherheit *hoch*. Zeilen, die nur die **Adresse** gemeinsam haben, werden als
Vorschläge mit der Sicherheit *mittel* angezeigt und nicht automatisch zusammengeführt; ergänzen Sie eine Spalte `family`, um sie zusammenzuführen.

## Webling

Der Assistent und die Kommandozeile lesen Mitglieder und ihre Mitgliedergruppen über die Webling-API. Die Gruppen werden zu
Gruppen in Kasmin. Weil sich die Felder in Webling von Organisation zu Organisation unterscheiden, ordnen Sie eigene Felder (z. B. die Namen
der Erziehungsberechtigten) in Schritt 2 zu.

```
KASMIN_WEBLING_KEY=… php artisan kasmin:import:people --webling=https://example.webling.eu [--mapping=fields.json] [--import]
php artisan kasmin:import:people members.csv --source=old-list [--import]
```

Ohne `--import` zeigt der Befehl nur den Probelauf. `fields.json` ordnet Kasmin-Felder den Feldern in Webling zu, z. B.
`{"first_name": "Vorname", "last_name": "Name", "guardian1_name": "Erziehungsberechtigte/r 1"}`.

Siehe auch [Mit einem KI-Assistenten umsteigen](migrate-with-ai.md).

## Rechnungen aus dem bisherigen System

*Finanzen → Rechnungsarchiv* bewahrt alte Rechnungen zum Nachschlagen auf. Sie können **nur gelesen** werden: Für sie wird nichts gesendet, gemahnt oder gebucht.

**Rechnungen importieren** (Finanzen und Administration) nimmt

- eine **Tabelle** (CSV oder XLSX) mit einer Rechnung pro Zeile. Spalten (englische oder deutsche Namen): `number`, `date`, `name`, `e-mail`, `amount`,
  `status` (`paid`, `open`, `cancelled`), `paid` (Datum), `note`. Beträge wie `1.250,50 €` oder `1250.50` werden verstanden, Datumsangaben wie
  `03.03.2025` oder `2025-03-03`. Gehört die E-Mail-Adresse zu einer Person in Kasmin, wird die Rechnung mit dieser Person verknüpft.
- auf Wunsch ein **ZIP mit den PDFs**, benannt wie die Rechnungsnummern (`R142.pdf`, `2025-0142.pdf`; Groß- und Kleinschreibung, Leerzeichen und Zeichen spielen keine Rolle).

Das Ergebnis nennt, was importiert wurde und was nicht gepasst hat (Zeilen ohne Nummer, PDFs ohne Rechnung). Ein Import kann wiederholt werden:
Zeilen, deren Nummer es schon gibt, werden aktualisiert, nichts wird verdoppelt.

Nach dem Import **geht die eigene Nummerierung nach der höchsten importierten Nummer weiter** – mit `R142` als letzter alter Rechnung bekommt die nächste
Rechnung von Kasmin die Nummer 143. Die Nummer geht nie zurück, und bei einem eigenen Nummernkreis pro Jahr wird nichts geändert.

