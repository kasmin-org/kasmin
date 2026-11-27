# Mit einem KI-Assistenten umsteigen

Der Umstieg von einem anderen Programm (einer Tabelle, Webling, einer anderen Vereinssoftware) ist vor allem Zuordnungsarbeit: Welche
Spalte ist welches Feld, welche Personen gehören zu einer Familie, welche Zeilen sind doppelt. Ein KI-Assistent, der über **MCP** mit
Kasmin verbunden ist (oder ein Skript mit der REST-API), kann das meiste davon erledigen: den alten Export lesen, zuordnen, Probeläufe starten,
Ihnen das Ergebnis erklären – und erst importieren, nachdem Sie zugestimmt haben.

## Zuerst die eigene Datenschutzerklärung beachten

Ein Umstieg gibt personenbezogene Daten an den KI-Dienst weiter. Prüfen Sie, bevor Sie beginnen:

- [ ] **Rechtsgrundlage und Datenschutzerklärung**: Erlaubt Ihre Datenschutzerklärung, Mitgliederdaten mit diesem KI-Dienst
      zu verarbeiten (Anbieter, Land, Zweck)? Wenn nicht, passen Sie sie zuerst an oder steigen Sie ohne KI um.
- [ ] **Vertrag zur Auftragsverarbeitung** mit dem KI-Anbieter (in der EU: Art. 28 DSGVO), und der Anbieter verwendet
      Ihre Daten nicht zum Trainieren.
- [ ] **Datenminimierung**: Exportieren Sie nur die Felder, die Sie übernehmen; lassen Sie weg, was Kasmin nicht braucht.
- [ ] **Gesundheits- und Sicherheitsdaten** (Allergien, Medikamente …) werden **nicht** an die KI weitergegeben, außer Ihre Organisation
      hat das ausdrücklich beschlossen. Importieren Sie sie getrennt (z. B. indem die Familien sie im Portal eintragen).
- [ ] **Einstellung** *KI-Verarbeitung erlaubt* in Kasmin: „Nicht vertrauliche Daten“ für einen gewöhnlichen Umstieg.
- [ ] **Token** für den Assistenten: MCP eingeschaltet, nur `people:read`, `events:read`, `import:write` (und was Sie
      wirklich brauchen), eine kurze Laufzeit; widerrufen Sie es nach dem Umstieg.
- [ ] Löschen Sie die Exportdateien und die Unterhaltung mit der KI, wenn Sie fertig sind, soweit Ihr Anbieter das erlaubt.

## Schritte

1. Erstellen Sie das Token (*Administration → API-Tokens*) und verbinden Sie den Assistenten mit `https://your-kasmin.example/mcp`
   (siehe [API und MCP](api.md)).
2. Geben Sie dem Assistenten den Export (oder lassen Sie stattdessen Webling über den Import-Assistenten einlesen).
3. Lassen Sie ihn die Spalten den [Importfeldern](import.md#felder) **zuordnen** und **`import_preview`** mit einem gleichbleibenden
   Namen der Quelle (z. B. `webling`) und den IDs der Quelle ausführen.
4. **Prüfen** Sie den Probelauf mit dem Assistenten: Fehler, doppelte Einträge, Vorschläge für Familien („mittel“ = nur dieselbe
   Adresse). Korrigieren Sie die Daten oder ergänzen Sie Schlüssel in `family`.
5. **`import_people`**. Prüfen Sie einige Familien in Kasmin.
6. Wenn etwas schiefgegangen ist: **`rollback_import`** (oder *Personen → Importe → Zurückrollen*), korrigieren, noch einmal importieren.
7. Solange beide Systeme nebeneinander laufen, wiederholen Sie die Schritte 3–5 mit derselben Quelle; Zeilen werden aktualisiert, nicht verdoppelt.

## Beispiele für Anweisungen

- „Hier ist unsere Mitgliederliste als CSV. Ordne die Spalten den Importfeldern von Kasmin zu und zeig mir die Zuordnung als Tabelle.
  Importiere noch nichts.“
- „Führe import_preview mit der Quelle `old-list` aus. Nenne alle Zeilen mit Fehlern und alle Vorschläge für Familien mit der
  Sicherheit mittel, und sag mir, was du ändern würdest.“
- „Der Probelauf sieht gut aus – importiere mit import_people und nenne mir die Nummer des Imports.“
- „Rolle Import 12 zurück, wir haben die falsche Datei verwendet.“
