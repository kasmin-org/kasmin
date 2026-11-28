# REST-API, MCP und Webhooks

Alles, was eine Anbindung oder ein KI-Assistent braucht, läuft über **API-Tokens**. Ein Token handelt im Namen der
Administratorin oder des Administrators, die oder der es erstellt hat, begrenzt auf die dafür gewählten **Berechtigungen (Scopes)**.
Es funktioniert nicht mehr, sobald diese Person die Rolle Administration verliert.

## API-Tokens

*Administration → API-Tokens* (Administratorinnen und Administratoren): **Token erstellen**, dann einen Namen, die Scopes und ein Ablaufdatum wählen. Das Token
(`kas_…`) wird **einmal** angezeigt – kopieren Sie es in das andere System. Widerrufen Sie Tokens, die Sie nicht mehr brauchen; die Liste zeigt, wann
jedes Token zuletzt verwendet wurde. Jeder schreibende Zugriff über die API steht im Audit-Protokoll, mit dem Token als Akteur.

| Scope | Erlaubt |
|---|---|
| `people:read` / `people:write` | Personen, Familien, Kontaktdaten, Erziehungsberechtigte, Mitgliedschaften, Gruppen und Mitgliedsarten lesen / anlegen und ändern; Rollen lesen; doppelte Einträge anführen / zusammenführen; öffentliche Einsendungen lesen / entscheiden |
| `events:read` / `events:write` | Veranstaltungen, Teilnehmende, Runden, Dokumente, Vorlagen, Listen, Gruppen und Zimmer, Statistik und Dashboard lesen / Veranstaltungen (inkl. Preise und Ratenplan), Reihen, Vorlagen, Runden und Dokumente anlegen und ändern, anmelden, Teilnahmen ändern, Plätze anbieten, Gruppen und Zimmer |
| `finance:read` / `finance:write` | Rechnungen (inkl. PDF), Zahlungen, Rückzahlungen, das Rechnungsarchiv und die Kosten einer Veranstaltung lesen; die Buchhaltung lesen (Konten, Kostenstellen, Fonds, Perioden, Buchungen, Berichte) / Rechnungen ausstellen, senden und stornieren, Zahlungen erfassen und zuordnen, Rückzahlungen erledigen, die Kosten einer Veranstaltung ändern; buchen und stornieren, Perioden abschließen und wiedereröffnen, Kontenplan, Kostenstellen, Fonds und Umlageschlüssel ändern |
| `mail:read` / `mail:write` | Mailvorlagen und ihre Versionen, Aussendungen, das Mailprotokoll, unzustellbare Adressen und Absenderprofile lesen / Vorlagen ändern und wiederherstellen, Aussendungen anlegen, vorbereiten und freigeben |
| `automations:read` | Automatisierungen und Läufe lesen, Probeläufe |
| `automations:write` | Automatisierungen ändern: aktiv, Modus, Auslöser, Einstellungen, Freigebende. Damit lässt sich eine Freigabe abschalten – geben Sie den Scope nur Tokens, über die ein Mensch bestimmt. Über MCP brauchen Änderungen an einer Freigabe zusätzlich `approvals:decide` |
| `automations:run` | Läufe starten – **als Freigabe-Anfragen** (die Freigebenden entscheiden) |
| `approvals:decide` | Freigaben entscheiden (und Läufe direkt starten). Geben Sie den Scope nur Tokens, über die ein Mensch bestimmt |
| `import:write` | Personen importieren, Importe zurückrollen |
| `audit:read` | das Audit-Protokoll, Einwilligungen und Löschanfragen lesen; mit `people:read` die Daten einer Person exportieren |
| `health:read` | vertrauliche Werte von Formularen, z. B. des Sicherheitsblatts, und die vertraulichen Listen einer Veranstaltung (absichtlich getrennt; jeder Lesezugriff wird protokolliert) |
| `health:write` | vertrauliche Werte ändern; die Erziehungsberechtigten müssen sie im Portal bestätigen |
| `settings:write` | Einstellungen lesen und ändern – nicht Bankdaten, öffentliche Adresse, Testmodus, Einbettung, Zeitzone und KI-Einstellungen; Absenderprofile ändern; Webhooks |
| `roles:write` | Rollen geben und nehmen – nie die Rolle Administration. Geben Sie den Scope nur Tokens, über die ein Mensch bestimmt |
| `privacy:write` | Personen anonymisieren (kann nicht rückgängig gemacht werden). Geben Sie den Scope nur Tokens, über die ein Mensch bestimmt |

Begrenzung: 120 Anfragen pro Minute und Token.

## REST-API v1

Basisadresse `https://your-kasmin.example/api/v1`, Header `Authorization: Bearer kas_…`, JSON hinein und heraus.

- **Dokumentation** mit jedem Endpunkt, jedem Parameter und jeder Antwort: `https://your-kasmin.example/docs/api`
  (OpenAPI 3.1 als JSON: `/docs/api.json`; jede Version liefert außerdem `openapi.json` mit).
- Alles, was die Verwaltung kann, gibt es auch hier (siehe den [Vergleich](#vergleich-verwaltung-rest-und-mcp)
  am Ende). Ein Endpunkt ruft dieselbe Funktion auf wie die Seite. Mails, Einträge im Audit-Protokoll und Regeln sind also dieselben.
- Listen, die lang werden können (`/people`, `/households`, `/events`, `/invoices`, `/payments`, `/refunds`,
  `/invoice-archive`, `/accounting/bookings`, `/automation-runs`, `/outgoing-mails`, `/public-submissions`, `/consents`), kommen in Seiten:
  `page` und `per_page` (Voreinstellung 50, höchstens 200) in der Anfrage und `meta` mit `total`, `page` und `last_page` in
  der Antwort. Sie sind nach dem Neuesten sortiert, Personen und Familien nach Namen.
- Anlegen antwortet mit `201`. Wo es den Eintrag schon gab (eine Person, die schon Mitglied der Gruppe
  in `POST /groups/{id}/members` oder der Familie ist oder schon erziehungsberechtigt ist), lautet die Antwort `200`, und nichts
  ändert sich. Eine Anfrage, die die Verwaltung ablehnen würde, antwortet mit `422` und einer `message`; ein fehlender Scope mit `403`.
- Die API ist nur für die Verwaltung gedacht: Ein Token handelt immer für eine Administratorin oder einen Administrator, und es gibt keine API für
  Familien oder andere Personen im Portal.
- IDs von Personen, Familien und Veranstaltungen sind ihre öffentlichen IDs (ULIDs), keine Nummern der Datenbank. Alles andere
  (Teilnahmen, Rechnungen, Zahlungen, Gruppen, Einteilungen, Einheiten, Mitgliedsarten, Rollen, Formulare, Mailvorlagen,
  Automatisierungen, Raten …) hat eine Nummer – siehe [IDs und Namen](#ids-und-namen).
- `GET /me` zeigt das verwendete Token: Name, Scopes, Ablauf und die Person, für die es handelt.

### IDs und Namen

Mitgliedsarten, Rollen, Formulare, Mailvorlagen, Automatisierungen und die Raten eines Ratenplans haben einen **Namen**,
der pro Art eindeutig ist (Raten: pro Veranstaltung), und werden über ihre **Datenbank-ID** angesprochen. Jede Liste liefert
`id` und `name` (Raten: `id` und `label`).

- Wo ein solcher Eintrag angesprochen wird – im Pfad (`/membership-types/{type}`, `/forms/{form}`,
  `/mail-templates/{template}`, `/automations/{automation}`) oder in einem Feld (`type` einer Mitgliedschaft, `role`,
  `template` einer Aussendung, `registration_form`, `membership_type`, die Mailvorlagen, das Formular und `approver_roles`
  einer Automatisierung) –, senden Sie die **ID** oder den **genauen Namen**. Groß- und Kleinschreibung sowie Leerzeichen am
  Anfang und Ende spielen keine Rolle. Eine Zahl gilt immer als ID.
- Einen Namen, den mehrere Einträge haben, lehnt die API mit `422` und dem Hinweis ab, dass der Name nicht eindeutig ist;
  verwenden Sie dann die ID. Nichts gefunden: `404` im Pfad, `422` in einem Feld.
- Bis 0.30 hatten diese Einträge einen sichtbaren Schlüssel (`deposit`, `safety_sheet` …). Ein solcher Schlüssel wird weiter
  angenommen, wo eine ID oder ein Name steht, ist aber in keiner Antwort mehr enthalten – außer in den Dateien von
  Formulardefinitionen (siehe unten) und von `kasmin:config:export`, die von einer Installation zur anderen gehen, wo die
  IDs andere sind.
- Einen Eintrag mit einem Namen anzulegen oder umzubenennen, der schon vergeben ist, lehnt die API ab (`422`), wie die Verwaltung.
- Felder eines Formulars behalten ihren Schlüssel: Die Antworten einer Person (`values`) und die Platzhalter von Mailvorlagen
  beziehen sich darauf. Er entsteht aus der Bezeichnung, wenn das Feld hinzugefügt wird, und ändert sich nicht, wenn die
  Bezeichnung geändert wird. `GET /forms` führt die Felder mit `id`, `key` und `label` an; `values` kann ein Feld über seinen
  Schlüssel oder seine ID nennen.

```
curl -H "Authorization: Bearer kas_…" https://your-kasmin.example/api/v1/events
```

**Gefährliche Aktionen brauchen einen Menschen** (X05): Wird eine Automatisierung über die API gestartet (Rechnungsläufe, Aussendungen …), entsteht eine
Freigabe-Anfrage, außer das Token hat `approvals:decide`.

### Personen

Scopes `people:read` / `people:write`.

- `GET|POST /people`, `GET|PATCH /people/{id}` – suchen (`search`, `group`, `updated_since`), lesen, anlegen (die
  Felder einer Importzeile, festgehalten als Import, der zurückgerollt werden kann) und Name, Geburtsdatum, Geschlecht
  und Sprache ändern.
- Kontaktdaten: `GET|POST /people/{id}/contact-points` (`type` `email` oder `phone`, `value`, `purposes`,
  `is_primary`), `DELETE /people/{id}/contact-points/{number}`. Die Adresse, mit der sich eine Person anmeldet, kann nicht entfernt werden.
- Erziehungsberechtigte: `GET|POST /people/{id}/guardians` (`guardian` = ID der Person),
  `DELETE /people/{id}/guardians/{guardian id}` beendet die Erziehungsberechtigung. Die Einstellung *Erziehungsberechtigte pro Familie (höchstens)*
  gilt auch hier; Familien werden nicht geändert.
- Mitgliedschaften einer Person: `GET|POST /people/{id}/memberships` (`type` = ID oder Name einer Mitgliedsart, `starts_on`,
  `ends_on`), `PATCH /people/{id}/memberships/{number}` mit `ends_on` beendet eine Mitgliedschaft (`null` lässt sie wieder
  laufen). Eine Person hat pro Art höchstens eine laufende Mitgliedschaft.
- Mitgliedsarten: `GET|POST /membership-types`, `PATCH /membership-types/{type}` (`{type}` = ID oder Name; `name`,
  eindeutig, `pays_fee`, `fee`, `revenue_account` und `cost_center`: worauf der Beitrag in der
  Buchhaltung gebucht wird, die ID oder der genaue Name; `null` leert).
- Doppelte Einträge: `GET /duplicates` führt die Paare an, die wahrscheinlich dieselbe Person sind, mit dem Grund.
  `POST /duplicates/merge` (`keep`, `duplicate` und `fields` wie `{"birth_date": "duplicate"}` für die Werte,
  die vom doppelten Eintrag kommen sollen) verschiebt alles zur Person, die bleibt, und löscht den doppelten Eintrag – das
  kann nicht rückgängig gemacht werden. Es wird abgelehnt, wenn das Zusammenführen jemandem mehr Rechte gäbe oder beide eine verrechnete
  Anmeldung zur selben Veranstaltung haben. `POST /duplicates/dismiss` (`first`, `second`) markiert zwei Personen als verschieden.
- Formulare: `GET /forms`, `GET /people/{id}/forms`, `GET|PATCH /people/{id}/forms/{form}` (`{form}` = ID oder Name).
  Vertrauliche Werte brauchen `health:read` / `health:write`. `GET|PUT /forms/{form}/definition` liest und ersetzt die
  Definition eines Formulars (`settings:write` zum Ersetzen); `PUT` mit einem Namen, den kein Formular hat, legt ein neues
  Formular an. Die Definition ist dieselbe Datei wie *Exportieren* in der Verwaltung: Sie enthält die internen Schlüssel
  des Formulars und seiner Felder, damit sie in eine andere Installation passt. Ein Feld ohne `key` ist ein neues Feld.

Beispiel: `POST /people/{id}/memberships` mit `{"type": "Ordentliches Mitglied (Team, Vorstand)", "starts_on": "2027-01-01"}`
(oder der `id` aus `GET /membership-types`).

### Familien

Scopes `people:read` / `people:write`.

- `GET /households` (`search`), `GET /households/{id}` – mit Mitgliedern und `invoice_recipient`.
- `POST /households` (`name`, `street`, `postal_code`, `city`, `country`) legt eine Familie ohne
  Mitglieder an; `PATCH /households/{id}` ändert sie. `invoice_recipient` ist die ID einer der erziehungsberechtigten Personen der
  Familie (`null` = wird automatisch gewählt); jemand anderer wird abgelehnt.
- `POST /households/{id}/members/{person}` mit `role` `guardian` (erziehungsberechtigt) oder `child` fügt eine Person hinzu oder
  ändert ihre Rolle; `DELETE /households/{id}/members/{person}` nimmt sie heraus. Wie in der Verwaltung werden die
  Erziehungsberechtigten einer Familie die Erziehungsberechtigten ihrer Kinder; eine beendete Erziehungsberechtigung bleibt beendet, und
  wer aus einer Familie herausgenommen wird, bleibt erziehungsberechtigt.

Beispiel: `POST /households` mit `{"name": "Familie Esche", "street": "Eichenweg 1", "postal_code": "1010", "city": "Wien", "country": "AT"}`.

### Rollen

Lesen mit `people:read`, Ändern mit dem eigenen Scope `roles:write`.

- `GET /roles` – alle Rollen; `admin` sagt, ob die Rolle Zugang zur Verwaltung hat.
- `GET /people/{id}/roles` – die Rollen einer Person mit ihrem Zeitraum.
- `POST /people/{id}/roles` (`role` = ID oder Name, `event` nur bei Rollen, die für die eigenen Veranstaltungen gelten, `starts_on`,
  `ends_on`) und `DELETE /people/{id}/roles/{number}`.
- **Ein Token kann die Rolle `administrator` nie geben oder nehmen** (403). In der Verwaltung dürfen nur Administratorinnen und Administratoren
  Rollen ändern; ein Token ist kein Mensch, deshalb bleibt diese Rolle dort bei einem Menschen. Die letzte Administration
  kann nirgends entfernt werden.
- Eine Person, die eine Rolle mit Zugang zur Verwaltung bekommt, bekommt auch einen Zugang für ihre erste E-Mail-Adresse, wie in der
  Verwaltung. Geben Sie `roles:write` nur Tokens, über die ein Mensch bestimmt.

### Veranstaltungen und Teilnehmende

Scopes `events:read` / `events:write`.

- `GET|POST /events`, `GET|PATCH /events/{id}` mit den Feldern des Veranstaltungsformulars: Grunddaten (Titel, Termine,
  Status, `series_id`, Anmeldezeitraum, Ort, Kontakt, `bus_booked`, `bus_seats`) und `roles` (`role`,
  `capacity`, `reserve_places`, `min_age`, `max_age`, `price`, `registration_form` = ID oder Name eines Formulars mit Fragen einer Veranstaltung …).
  Ein gebuchter Bus braucht die Zahl seiner Sitzplätze. Die Antwort hat `bus` mit `riders`, `cars`, `free`, `over`,
  `adults_on_bus` und `children_by_car`. Das Bild wird in der Verwaltung gesetzt.
- Preise und Ratenplan, in denselben Anfragen: `sibling_mode` (`none`, `staggered`, `all`), `sibling_order`
  (`oldest`, `highest_price`, `registration`), `sibling_amount`, `sibling_cap_position`, `sibling_min_children`,
  `cancellation_fee`, `cancellation_text.de` / `.en`, `reminder_days_before` (Liste von Tagen), `prices` (Preise nach
  Alter oder Datum: `role`, `min_age`, `max_age`, `from_date`, `until_date`, `price` – die Liste ersetzt die
  gespeicherte) und `installments` (`id`, `label` = der Name, eindeutig innerhalb der Veranstaltung, `kind` = `fixed`, `rest`
  oder `member`, `amount`, `due_date`, `due_days`, `membership_type` = ID oder Name, `refundable`, `refund_until_days`,
  `confirms_place`, `paid_add_group`, `paid_remove_group`, `discounts_apply`, `revenue_account`, `cost_center`). Raten werden wie Rollen geführt: Ein Eintrag mit
  der `id` einer Rate ändert sie, ein Eintrag ohne `id` ändert die Rate mit demselben Namen oder legt eine neue an, und die Raten,
  die nicht angeführt sind, werden entfernt. Wie im Veranstaltungsformular können Preise geändert werden, während es Rechnungen gibt:
  Ausgestellte Rechnungen bleiben, wie sie sind. Eine Rate, zu der es schon Rechnungen gibt, kann nicht
  entfernt werden (422). Die Währung wird angezeigt, kann aber nicht geändert werden, wie im Formular.
- Buchhaltung: `cost_center` der Veranstaltung und `revenue_account` / `cost_center` einer Rate sagen, worauf ihre
  Rechnungen gebucht werden (siehe [Rechnungen – Buchungen](invoices.md)). Senden Sie die ID oder den genauen Namen; `null`
  leert das Feld. Die Antwort hat den Namen und die ID (`cost_center_id`, `revenue_account_id`). Ein unbekannter Name wird
  abgelehnt (422).
- Anmelderunden: `GET|PUT /events/{id}/rounds`. `PUT` sendet `rounds` (`id` für eine bestehende Runde, `role`,
  `kind` = `returning`, `selection`, `waitlist` oder `open`, `mode` = `accept` oder `payment`, `opens_at`, `closes_at`,
  `min_events`, `group`) und ersetzt die Liste; Runden, die nicht angeführt sind, werden entfernt.
- Kosten (`finance:read` / `finance:write`): `GET|POST /events/{id}/costs` (`label`, `category`, `amount`),
  `PATCH /costs/{number}`, `DELETE /costs/{number}`.
- Dokumente: `GET /events/{id}/documents`; `POST /events/{id}/documents` als `multipart/form-data` mit `title`,
  `visibility` (`participants` oder `public`) und `file` (PDF, JPEG oder PNG, höchstens 10 MB, wie im Formular);
  `GET /event-documents/{number}/download` (`events:read`); `DELETE /event-documents/{number}`.
- Veranstaltungsvorlagen: `GET /event-templates`; `POST /event-templates` mit `event` und `title` speichert eine Veranstaltung als
  Vorlage (oder senden Sie die Felder einer Veranstaltung, um eine Vorlage neu anzulegen); `PATCH /event-templates/{id}`,
  `DELETE /event-templates/{id}`. `POST /events` mit `template` (oder `copy_of` = eine Veranstaltung), `title` und
  `starts_at` legt eine Veranstaltung als Kopie an, mit allem, was eingestellt ist, und allen Terminen verschoben, wie es
  *Aus Vorlage* und *Kopieren* tun; Personen, Rechnungen und Kosten werden nicht kopiert.
- Veranstaltungsreihen: `GET|POST /event-series` (`name`, `cost_center` = ID oder genauer Name der Kostenstelle, auf die
  ihre Veranstaltungen gebucht werden), `PATCH /event-series/{number}`.
- `GET /events/{id}/participations` (`status`, `role`), `POST /events/{id}/registrations` (`person`, `role`).
- `PATCH /participations/{number}` ändert `status` oder `travel` (`bus`, `car`). Mit `status` `cancelled` wird ein
  optionaler `reason` gespeichert; die Familie wird informiert, der Platz geht an die Warteliste, und Rechnungsentwürfe werden
  neu berechnet. Eine Teilnahme mit dem Status Storniert, Abgesagt, Teilgenommen oder Nicht erschienen kann nicht storniert werden.
- Einen Platz außer der Reihe anbieten: `POST /events/{id}/offers` mit `person`, `role`, `until` (Datum) und `reason`
  (Pflicht). Die Familie bekommt das Angebot per Mail und antwortet im Portal.
- Reserveplätze: `POST /participations/{number}/reserve-place` (`reason` ist Pflicht) gibt einen freien Reserveplatz an
  jemanden auf der Warteliste; `POST /events/{id}/reserve-places/release` (`role`, `count`, `reason`) gibt Reserveplätze,
  die nicht gebraucht werden, für die Warteliste frei.
- `GET /events/{id}/schedule` – die Termine der Veranstaltung, die davor und danach geplanten Automatisierungen und ihre Läufe.

Beispiel: `POST /events/{id}/offers` mit `{"person": "01J…", "role": "child", "until": "2027-05-31", "reason": "Geschwisterkind einer Betreuungsperson"}`.

### Gruppen, Zimmer und Dienste einer Veranstaltung

Scopes `events:read` / `events:write`.

- `GET /events/{id}/divisions` – die Einteilungen mit ihren Einheiten, den Personen in jeder Einheit, wer noch nicht eingeteilt ist
  (`not_placed`) und was von Hand zu prüfen ist (`warnings`).
- `POST /events/{id}/divisions` mit `kind` (`groups`, `rooms`, `duty`), `name`, `units` (wie viele), `capacity`
  (pro Einheit) und `options` (`roles`, `balance_age`, `balance_gender`, `separate_gender`, `siblings` = `apart`,
  `together` oder `any`; die Voreinstellungen hängen von der Art ab). `DELETE /divisions/{number}` entfernt eine Einteilung.
- `POST /divisions/{number}/propose` teilt alle ein, die noch keine Einheit haben; mit `restart` `true` werden alle neu
  eingeteilt.
- `PUT /divisions/{number}/members/{participation}` mit `unit` (Nummer, oder `null`, um die Person herauszunehmen).
- `POST /divisions/{number}/units` fügt eine Einheit hinzu, `PATCH /units/{number}` ändert `name`, `note` und `capacity`,
  `DELETE /units/{number}` entfernt eine Einheit, in der niemand ist.

### Listen einer Veranstaltung

- `GET /events/{id}/lists` – die Listen, die das Token lesen darf, mit Titeln.
- `GET /events/{id}/lists/{key}` (`role` optional) – `headers` und `rows` wie auf der Seite *Listen*. `events:read`
  genügt für `participants`, `figures`, `contacts`, `bus`, `cars`, `groups`, `birthdays`, `answers` und `missing`.
- Die Listen aus dem Sicherheitsblatt – `emergency`, `kitchen`, `medical`, `medication`, `insurance`, `swimmers`,
  `consents`, `changes` – sind vertraulich: Sie brauchen zusätzlich `health:read`, und jeder Lesezugriff steht im
  Audit-Protokoll (`list.viewed`), wie in der Verwaltung.

### Gruppen

Scopes `people:read` / `people:write`: `GET|POST /groups`, `GET|PATCH /groups/{number}`,
`POST /groups/{number}/members` (`person`), `DELETE /groups/{number}/members/{person}`.

### Rechnungen, Zahlungen und Rückzahlungen

Scopes `finance:read` / `finance:write`.

- `GET /invoices` (`status`, `event`), `GET /invoices/{number}`, `GET /invoices/{number}/pdf`. `installment` ist die
  ID der Rate aus dem Ratenplan (`null` bei Einzelrechnungen und Mitgliedsbeiträgen), `installment_name` das, wofür sie ist.
- `POST /invoices/{number}/issue` stellt einen Entwurf aus (Nummer, Referenz, Fälligkeitsdatum) und sendet ihn an die zahlungspflichtige Person, wie
  *Ausstellen und senden*; mit `send` `false` wird er nur ausgestellt. `POST /invoices/{number}/send` sendet eine ausgestellte
  Rechnung noch einmal. Nur Entwürfe können ausgestellt werden, und Entwürfe können nicht gesendet werden.
- `POST /invoices/{number}/cancel` mit `reason` (**Pflicht**, steht auf der Gutschrift) storniert eine ausgestellte
  Rechnung mit einer Gutschrift. Zahlungen zur Rechnung werden ein Guthaben der Familie mit einem Vorschlag zur Rückzahlung. Die
  Antwort ist die Gutschrift.
- `POST /invoices/{number}/payments` (`amount`, `paid_on`, `method`, `note`) erfasst eine Zahlung.
- `GET /payments` (`match` = `auto`, `manual`, `suggested`, `unmatched`, `credit`, `refunded`).
  `POST /payments/{number}/assign` ordnet eine noch nicht zugeordnete Zahlung einer offenen Rechnung zu (`invoice` = ihre
  Nummer; ohne sie wird die vorgeschlagene Rechnung bestätigt). Was zu viel bezahlt wurde, wird ein Guthaben mit einem Vorschlag
  zur Rückzahlung.
- `GET /refunds` (`status` = `proposed`, `done`, `declined`), `POST /refunds/{number}/done` mit `date` (der Tag der
  Überweisung, nicht in der Zukunft), `POST /refunds/{number}/keep`, wenn die Familie das Guthaben behält.
- `GET /invoice-archive` (`search`, `status`) – die Rechnungen aus dem bisherigen System, nur zum Lesen.
- Nur in der Verwaltung: der Bankimport aus einer Datei, das Aufteilen einer Zahlung auf mehrere Rechnungen, das Verwenden eines Guthabens für
  eine andere Rechnung, der ermäßigte Preis eines Platzes und Einzelrechnungen.

Beispiel: `POST /invoices/{number}/cancel` mit `{"reason": "Lager abgesagt"}`.

### Buchhaltung

Scopes `finance:read` / `finance:write`; Begriffe und Regeln stehen unter [Buchhaltung](accounting.md).

- Konten, Kostenstellen, Fonds und Perioden werden mit ihrer `id` oder ihrem genauen Namen (`name`) angegeben – Konten auch mit
  ihrer Kontonummer (`number`) –, im Pfad wie im Inhalt; jede Liste enthält beides. Ein Name, der auf zwei Einträge passt (ein
  doppelt vergebener Periodenname, ein Name, der zugleich die ID eines anderen Eintrags ist), antwortet mit `422`: Nehmen Sie dann
  die ID. Beträge sind Dezimalzahlen in der Währung der Installation. Was die Verwaltung ablehnt (eine abgeschlossene Periode, Soll
  und Haben nicht gleich, eine inaktive Kostenstelle …), antwortet mit `422` und derselben `message`.
- **Kontenplan:** `GET|POST /accounting/accounts` (Filter `type`, `money` = `bank`, `cash` oder `any`, `active`),
  `PATCH|DELETE /accounting/accounts/{id}` mit `name`, `number`, `type`, `money`, `iban`, `default_cost_center`, `active` und
  `sort`. Die Liste enthält den Stand von heute, Bank- und Kassakonten auch die Fonds darauf und die Differenz, wie auf der Seite
  *Bank- und Kassakonten*. Systemkonten behalten ihre Art, ebenso Konten mit Buchungen; nur eigene Konten ohne Buchungen können
  gelöscht werden (die anderen schalten Sie mit `active` `false` ab).
- **Kostenstellen:** `GET|POST /accounting/cost-centers` (`name`, `kind` = `carries` (trägt ihr Ergebnis) oder `allocated`
  (wird umgelegt), `fund`, `sort`; eine neue ist in allen offenen Perioden aktiv), `PATCH /accounting/cost-centers/{id}` (`name`,
  `fund`, `sort`), `POST /accounting/cost-centers/{id}/kind` (`kind`, `fund`), `PUT /accounting/cost-centers/{id}/periods/{period}`
  mit `active` `true` oder `false` (die Antwort enthält `warnings`, z. B. Geld, das noch im Fonds liegt),
  `POST /accounting/cost-centers/{id}/default` macht sie zur Standard-Kostenstelle. `DELETE /accounting/cost-centers/{id}` mit
  `move_to` (die Kostenstelle, die die Buchungen bekommt) oder mit `delete_bookings` `true` und `confirm_count` – der Zahl ihrer
  Buchungen (`bookings` in der Liste) statt des Satzes, den man in der Verwaltung eintippt; löschen lassen sich nur eingegebene
  Buchungen in offenen Perioden, und vorher wird eine Datensicherung erstellt. `cannot_delete` in der Liste nennt die Gründe, warum
  eine Kostenstelle nicht gelöscht werden kann.
- **Fonds:** `GET|POST /accounting/funds` (`name`, `account` = ein Bank- oder Kassakonto, `note`, `sort`),
  `PATCH|DELETE /accounting/funds/{id}`; `POST /accounting/funds/{id}/transfers` (`to`, `amount`, `text` = der Beschluss, `date`)
  verschiebt Geld in einen anderen Fonds, mit einer Buchung der Art `fund_transfer`. Löschen lässt sich nur ein Fonds, der nichts
  enthält und den keine Kostenstelle verwendet, nie der Allgemeine Fonds. Kostenstellen und Fonds anlegen geht nur mit den
  Schaltern unten.
- **Perioden:** `GET|POST /accounting/periods` (`name`, `starts_on` – nur bei der ersten Periode, später der Tag nach der letzten –,
  `ends_on`). `PATCH /accounting/periods/{id}` ändert `name`, `starts_on` und `ends_on` einer offenen Periode; `moved` sagt, wie
  viele Buchungen jetzt zu einer anderen Periode gehören, mit `dry_run` `true` wird nichts gespeichert.
- **Umlageschlüssel:** `GET /accounting/periods/{id}/allocation-keys` (einer je umzulegender Kostenstelle),
  `PUT /accounting/periods/{id}/allocation-keys/{cost_center}` mit `rules` (`target`, `kind` = `fixed`, `percent`, `rest` oder
  `share`, `amount` bei `fixed`, `percent` bei `percent`) ersetzt den Schlüssel einer umzulegenden Kostenstelle in einer offenen
  Periode, mit den Prüfungen der Seite; eine leere Liste entfernt ihn.
- **Abschluss:** `GET /accounting/periods/{id}/closing` ist die Vorschau: `rows` je Kostenstelle (`before`, `allocation`, `after`),
  `allocations`, `funds` (`before`, `result`, `after`), `warnings`, `errors` und `can_close`. `POST /accounting/periods/{id}/close`
  schließt ab, wie die Vorschau es zeigt. `POST /accounting/periods/{id}/reopen` mit `reason` und `confirm` `true` öffnet die
  Periode und jede spätere abgeschlossene wieder; ohne `confirm` antwortet es mit `422` und den Perioden (`periods`), die
  mitgeöffnet würden.
- **Buchungen:** `GET /accounting/bookings` (`from`, `to`, `period`, `account`, `cost_center`, `kind`, `search`; in Seiten, mit den
  Zeilen), `GET /accounting/bookings/{id}` (die `id`, nicht die Buchungsnummer `number`), `POST /accounting/bookings` (`date`,
  `text`, `receipt`, `lines` mit `account`, `debit` oder `credit`, `cost_center`, `fund`, `text`),
  `POST /accounting/bookings/{id}/reverse` storniert (`text`, `date`; Umlagen und Abschlüsse werden nicht storniert, sondern beim
  Wiedereröffnen entfernt).
- **Berichte:** `GET /accounting/reports/{report}` – `balance` (Bilanz, `date`), `income` (Erfolgsrechnung, `period`,
  `cost_center`), `cost_centers` (`period`), `funds` (Fondsspiegel, `period`), `ledger` (Kontoblatt, `account`, `from`, `to`) oder
  `open_items` (offene Posten, `date`); ohne Angabe gelten heute und die aktuelle Periode. Die Antwort enthält `title`, `headers` und
  `rows` wie auf der Seite; mit `format` `csv`, `xlsx` oder `pdf` ist sie die Datei von *Export* (protokolliert).
- **Schalter:** `use_cost_centers` und `use_funds` in `GET|PATCH /settings` (`settings:write`), mit den Regeln der globalen
  Einstellungen.
- Nur in der Verwaltung: die Belegdatei (Foto oder PDF) einer Buchung; die Antwort sagt `has_receipt_file`.

Beispiel: `POST /accounting/bookings` mit `{"date": "2027-03-01", "text": "Einkauf", "lines": [{"account": "Verpflegung", "debit": 30},
{"account": "Material", "debit": 12.5}, {"account": "Kasse", "credit": 42.5}]}`.

### Mail

Scopes `mail:read` / `mail:write`.

- `GET /mail-templates`, `GET|PATCH /mail-templates/{template}`, `POST /mail-templates/{template}/preview`
  (`{template}` = ID oder Name). Eine Vorlage hat einen Text: `subject`, `html` und optional `text`.
- **Texte sind einzelne Werte.** Namen, Bezeichnungen und Texte (Formulare mit ihren Feldern und Auswahlmöglichkeiten, Mitgliedsarten,
  Automatisierungen, Bezeichnungen von Raten, Stornobedingungen, Mailvorlagen) sind einfache Zeichenketten. Bis 0.27 waren
  sie Objekte mit einem Text pro Sprache (`{"de": …, "en": …}`); solche Eingaben werden weiter angenommen, und es wird der Text in
  der Sprache der Installation genommen. Personen und Familien haben kein `locale` mehr.
- Versionen: `GET /mail-templates/{template}/versions` und `POST /mail-templates/{template}/versions/{version}/restore`.
  Das Wiederherstellen erzeugt eine neue Version; nichts geht verloren.
- `GET|POST /mailings` (`template` = ID oder Name der Vorlage), `GET /mailings/{number}`, `POST /mailings/{number}/prepare`,
  `POST /mailings/{number}/release` (bittet um die Freigabe, außer das Token hat `approvals:decide`).
- `GET /outgoing-mails` (`status`, `person`) – das Mailprotokoll mit dem gespeicherten Text. **Persönliche Links zur Anmeldung und zur
  Freigabe sind nie Teil der Antwort**: Gespeicherte Mails enthalten einen Platzhalter, der Link entsteht erst beim Senden einer Mail.
- `GET /bad-addresses` – Adressen, die als nicht vorhanden zurückgekommen sind; `POST /bad-addresses/{number}/clear`, wenn eine
  Adresse wieder funktioniert.
- `GET /sender-profiles` – der eine Absender der Installation (Name, Absender, Antwortadresse, Name des Mailkontos).
  `POST /sender-profiles` legt ihn an, wenn es noch keinen gibt (ein zweiter wird abgelehnt);
  `PATCH /sender-profiles/{number}` (`name`, `from_name`, `from_address`, `reply_to`, `mail_account`) brauchen
  `settings:write`, weil die Maileinstellungen in der Verwaltung ein eigenes Recht sind.
- Nur in der Verwaltung: Mailkonten. Ihre Passwörter und DKIM-Schlüssel sind nie Teil einer Antwort.

### Öffentliche Formulare

Scopes `people:read` / `people:write`.

- `GET /public-forms` – die Formulare mit ihrer Adresse und wohin sie führen.
- `GET /public-submissions` (`status` = `pending`, `done`, `declined`, `failed`) – was gesendet und bestätigt wurde.
  Antworten auf vertrauliche Fragen sind nie Teil der Antwort (`hidden` nennt sie).
- `POST /public-submissions/{number}/accept` führt eine wartende Bewerbung aus,
  `POST /public-submissions/{number}/reject` (`note`) lehnt sie ab und löscht ihre Antworten.

### Datenschutz

Lesen mit `audit:read`, Anonymisieren mit dem eigenen Scope `privacy:write`.

- `GET /consents` (`kind`, `person`) – Einwilligungen und Abmeldungen mit ihrem Nachweis.
- `GET /deletion-requests` – Personen, die die Löschung beantragt haben, mit dem, was gelöscht würde und was erhalten bliebe.
- `GET /people/{id}/export` – die Kopie der Daten einer Person und der Kinder, für die sie erziehungsberechtigt ist,
  dieselbe wie in der Verwaltung. Braucht `audit:read` und `people:read`, und `health:read`, wenn der Export
  Antworten auf vertrauliche Fragen enthält (z. B. ein Sicherheitsblatt). Jeder Export steht im Audit-Protokoll.
- `POST /people/{id}/anonymise` mit `reason`. Das kann nicht rückgängig gemacht werden. Mit `preview` `true` wird nichts geändert, und
  die Antwort zeigt, was gelöscht würde und was erhalten bliebe. Wo die Verwaltung ablehnt – z. B. weil die Person noch eine
  Rolle hat –, lautet die Antwort `422` mit `reasons`.
- Nur in der Verwaltung: der Lauf, der alte vertrauliche Angaben nach der Aufbewahrungsfrist löscht.

### Auswertungen

- `GET /statistics` (`event`, `role`, `groups`) – was die Seite *Statistik* zeigt: Altersverteilung, Altersgruppen,
  Teilnehmende pro Jahr und mit `finance:read` Kosten und Ermäßigungen. Scope `events:read`.
- `GET /statistics/age-groups` (`event`, `role`, `groups`, `fields[]`) – die Mitglieder der Altersgruppen, eine Zeile je Person
  mit den gewählten Feldern (`group`, `first_name`, `last_name`, `age`, `birth_date`, `gender`, `role`; Standard: alle), als
  `headers` und `rows` – das, was *Export* auf der Seite *Statistik* enthält. Scopes `events:read` und `people:read`; wird protokolliert.
- `GET /dashboard` – die nächste Veranstaltung jeder Reihe (Anmeldungen, freie Plätze, Warteliste, Bus), was zu erledigen ist, und
  Sicherheitsblätter bestätigt und fehlend (nur Zahlen). Scope `events:read`; Zahlen zu Geld nur mit `finance:read`,
  Läufe von Automatisierungen nur mit `automations:read`.

### Automatisierungen

- `GET /automations`, `GET|PATCH /automations/{automation}` (ID oder Name) für `active`, `mode`, `trigger`, `trigger_config`,
  `config`, `approver_roles`, `approvals_needed`, `max_items` und `name`, mit den Prüfungen des Formulars der Automatisierung.
  `trigger_config` und `config` ersetzen das gespeicherte Objekt. Die Aktion kann nicht geändert werden. `approver_roles` sind
  IDs von Rollen (in einer Anfrage IDs oder Namen); `approver_roles_default` ist `true`, solange keine Freigebenden gespeichert
  sind: `approver_roles` zeigt dann die voreingestellten Rollen. Eine leere Liste für `approver_roles` stellt die Voreinstellung
  wieder her.
- In `config` sind Mailvorlagen (`template`, `steps[].template`) und das Formular der Erinnerung an das Sicherheitsblatt
  (`form`) in der Antwort IDs; eine Anfrage kann IDs oder Namen senden. Die Rate eines Rechnungslaufs (`installment`) ist ihre
  Stelle im Ratenplan der Veranstaltung, für die die Automatisierung läuft: `pos:1`, `pos:2`, `pos:3` … oder `pos:last`. Ein
  Schlüssel einer Rate aus früheren Versionen (z. B. `deposit`) funktioniert weiter.
- Eigene Automatisierungen (`automations:write`): `POST /automations` mit `name` (eindeutig), `trigger` und `config`
  (`target`, `conditions`, `steps`) legt eine mit der Aktion `custom` an; sie wird ausgeschaltet angelegt, wie in der
  Verwaltung. `DELETE /automations/{automation}` löscht eine eigene Automatisierung. **Standard-Automatisierungen können nicht
  gelöscht werden** (403); schalten Sie sie stattdessen aus.
- `POST /automations/{automation}/preview` (Probelauf), `POST /automations/{automation}/run`, `GET /automation-runs`,
  `GET /automation-runs/{number}`, `POST /automation-runs/{number}/decision`. Ein Lauf nennt seine Automatisierung mit
  `automation` (ID) und `automation_name`.

### Import, Einstellungen und Audit-Protokoll

- `GET /imports`, `POST /imports/people/preview`, `POST /imports/people/families`, `POST /imports/people`,
  `POST /imports/{number}/rollback` (`import:write`).
- `GET|PATCH /settings` (`settings:write`).
- `GET /audit` (`audit:read`).

### Webhooks über die API

Scope `settings:write` (in der Verwaltung: Administratorinnen und Administratoren).

- `GET|POST /webhooks` (`name`, `url` mit https, `events`, `active`), `PATCH /webhooks/{number}`,
  `DELETE /webhooks/{number}`.
- **Das Signatur-Geheimnis wird einmal angezeigt**: in der Antwort auf `POST /webhooks` und auf `POST /webhooks/{number}/secret`,
  das es ersetzt. Keine andere Antwort enthält es, und es kann nicht gesetzt oder gelesen werden.

### Nur in der Verwaltung

Eigene Benachrichtigungen (über welche Ereignisse eine Person informiert wird) gehören der angemeldeten Person, deshalb gibt es dafür
keinen Endpunkt. API-Tokens, Systemstatus, Datensicherungen und Updates gibt es absichtlich nur in der Verwaltung.

## Anfragen sicher wiederholen

Senden Sie bei einer schreibenden Anfrage den Header `Idempotency-Key: <your unique key>` mit. Kommt dieselbe Anfrage innerhalb von 24 Stunden noch einmal, wird sie nicht
noch einmal ausgeführt; Sie bekommen die erste Antwort mit `Idempotent-Replayed: true`. Derselbe Schlüssel mit einer anderen Anfrage wird abgelehnt (422); solange
die erste noch läuft, bekommen Sie 409.

## Öffentliche Veranstaltungen und Formulare

Ohne Token, für Ihre eigene Website: `GET /api/v1/public/events`, `GET /api/v1/public/forms/{name}` und
`POST /api/v1/public/forms/{name}`. Verfügbar sind nur Veranstaltungen mit öffentlicher Seite und aktive Formulare. Das Absenden eines Formulars sendet nur die
Mail zur Bestätigung, und die Antwort ist für bekannte und unbekannte Adressen dieselbe.

## MCP für KI-Assistenten

Kasmin hat einen eingebauten MCP-Server (Model Context Protocol, Streamable HTTP) unter `https://your-kasmin.example/mcp`.
Er bietet die REST-API als Tools an, mit demselben Token und denselben Scopes. Ein Tool ruft den REST-Endpunkt auf. Es gelten also dieselben
Prüfungen, und das Audit-Protokoll zeigt die Änderung mit „mcp“. Jeder Endpunkt für die Verwaltung hat genau ein Tool
(die Teilnahme hat zwei, für Status und Anreise); ein Test geht die Routen der API durch und schlägt fehl, wenn eine
vergessen wurde (`tests/Feature/Api/McpParityTest.php`). Tools nennen Mitgliedsarten, Rollen, Formulare, Mailvorlagen und
Automatisierungen über ihre ID oder ihren genauen Namen, wie die REST-API (`automation`, `template`, `type`, `form`, `role`).

| Bereich | Tools | Scopes |
|---|---|---|
| Token | `who_am_i` | – |
| Personen | `search_people`, `get_person` / `create_person`, `update_person` | `people:read` / `people:write` |
| Kontaktdaten | `list_contact_points` / `add_contact_point`, `remove_contact_point` | `people:read` / `people:write` |
| Erziehungsberechtigte | `list_guardians` / `add_guardian`, `end_guardianship` | `people:read` / `people:write` |
| Familien | `list_households`, `get_household` / `create_household`, `update_household`, `add_household_member`, `remove_household_member` | `people:read` / `people:write` |
| Mitgliedschaften | `list_membership_types`, `list_memberships` / `create_membership_type`, `update_membership_type`, `add_membership`, `update_membership` | `people:read` / `people:write` |
| Rollen | `list_roles`, `list_person_roles` / `assign_role`, `remove_role` | `people:read` / `roles:write` **und** `approvals:decide` |
| Doppelte Einträge | `list_duplicates` / `merge_duplicates`, `dismiss_duplicate` | `people:read` / `people:write` |
| Gruppen | `list_groups`, `get_group` / `create_group`, `update_group`, `add_group_member`, `remove_group_member` | `people:read` / `people:write` |
| Veranstaltungen | `list_events`, `get_event`, `get_event_schedule` / `create_event`, `update_event` (das ganze Veranstaltungsformular: Bus, Preise, Ratenplan) | `events:read` / `events:write` |
| Veranstaltungsreihen und Vorlagen | `list_event_series`, `list_event_templates` / `create_event_series`, `update_event_series`, `create_event_template`, `update_event_template`, `delete_event_template` | `events:read` / `events:write` |
| Anmelderunden, Dokumente | `list_registration_rounds`, `list_event_documents` / `save_registration_rounds`, `delete_event_document` | `events:read` / `events:write` |
| Kosten einer Veranstaltung | `list_event_costs` / `add_event_cost`, `update_event_cost`, `delete_event_cost` | `finance:read` / `finance:write` |
| Teilnehmende und Plätze | `list_participants` / `register_participant`, `update_participation_status`, `set_participation_travel`, `offer_place`, `assign_reserve_place`, `release_reserve_places` | `events:read` / `events:write` |
| Gruppen, Zimmer, Dienste einer Veranstaltung | `list_divisions` / `create_division`, `delete_division`, `propose_division`, `move_division_member`, `add_division_unit`, `update_division_unit`, `delete_division_unit` | `events:read` / `events:write` |
| Listen und Auswertungen | `list_event_lists`, `get_event_list`, `get_statistics`, `get_age_group_members`, `get_dashboard` | `events:read` (vertrauliche Listen: `health:read`; Geld: `finance:read`; Mitglieder der Altersgruppen: `people:read`) |
| Rechnungen | `list_invoices`, `get_invoice` / `record_payment`, `issue_invoice`, `send_invoice`, `cancel_invoice` | `finance:read` / `finance:write` |
| Zahlungen, Rückzahlungen, Archiv | `list_payments`, `list_refunds`, `list_archived_invoices` / `assign_payment`, `mark_refund_done`, `keep_refund_as_credit` | `finance:read` / `finance:write` |
| Buchhaltung: Kontenplan, Kostenstellen, Fonds | `list_accounts`, `list_cost_centers`, `list_funds` / `create_account`, `update_account`, `delete_account`, `create_cost_center`, `update_cost_center`, `change_cost_center_kind`, `set_cost_center_active`, `make_default_cost_center`, `delete_cost_center`, `create_fund`, `update_fund`, `transfer_between_funds`, `delete_fund` | `finance:read` / `finance:write` |
| Buchhaltung: Perioden, Schlüssel, Abschluss | `list_periods`, `get_allocation_keys`, `close_period_preview` / `create_period`, `update_period`, `save_allocation_key`, `close_period`, `reopen_period` | `finance:read` / `finance:write` |
| Buchhaltung: Buchungen, Berichte | `list_bookings`, `get_booking`, `get_report` / `create_booking`, `reverse_booking` | `finance:read` / `finance:write` |
| Automatisierungen | `list_automations`, `get_automation`, `preview_automation`, `list_approvals`, `get_automation_run` / `create_automation`, `update_automation`, `delete_automation` / `request_automation_run` / `decide_approval` | `automations:read` / `automations:write` / `automations:run` / `approvals:decide` |
| Importe | `import_preview`, `suggest_import_families`, `import_people`, `list_imports`, `rollback_import` | `import:write` |
| Formulare | `list_forms`, `get_form_definition`, `get_person_forms`, `get_person_form` / `update_person_form` / `save_form_definition` | `people:read` / `people:write` / `settings:write` (vertrauliche Antworten: `health:read`, `health:write`) |
| Öffentliche Formulare | `list_public_forms`, `list_public_submissions` / `accept_public_submission`, `reject_public_submission` | `people:read` / `people:write` |
| Mailvorlagen | `list_mail_templates`, `get_mail_template`, `preview_mail_template`, `list_mail_template_versions` / `update_mail_template`, `restore_mail_template_version` | `mail:read` / `mail:write` |
| Aussendungen | `list_mailings`, `get_mailing` / `create_mailing`, `prepare_mailing`, `request_mailing_release` | `mail:read` / `mail:write` |
| Mailprotokoll, Absenderprofile | `list_outgoing_mails`, `list_bad_addresses`, `list_sender_profiles` / `clear_bad_address` / `create_sender_profile`, `update_sender_profile` | `mail:read` / `mail:write` / `settings:write` |
| Datenschutz | `list_consents`, `list_deletion_requests` / `export_person_data` / `anonymise_person` | `audit:read` / `audit:read` und `people:read` / `privacy:write` |
| Einstellungen, Webhooks | `get_settings`, `update_settings`, `list_webhooks`, `update_webhook`, `delete_webhook` | `settings:write` |
| Audit-Protokoll | `read_audit_log` | `audit:read` |

Tools, die nur lesen, sind als nur lesend gekennzeichnet. Tools, die löschen, nicht rückgängig gemacht werden können oder Mails an Familien senden, sind als
destruktiv gekennzeichnet. So kann ein MCP-Client nachfragen, bevor er sie aufruft. Optionale Felder werden mit `clear` über ihren Namen geleert.

Absichtlich nicht über MCP verfügbar:

- **Dateien**: ein Dokument einer Veranstaltung hochladen, ein Dokument herunterladen, das PDF einer Rechnung und die Dateien eines
  Berichts der Buchhaltung (`get_report` antwortet mit der Tabelle). Ein Tool-Aufruf überträgt nur JSON; verwenden Sie die Verwaltung
  oder die REST-API.
- **Einen Webhook anlegen, ein neues Signatur-Geheimnis, eine neue Adresse eines Webhooks**: Das Geheimnis wird einmal angezeigt und gehört
  der Person, die das empfangende System einrichtet, und ein Assistent darf Daten der Organisation nicht an eine
  Adresse seiner Wahl senden. `update_webhook` benennt einen Webhook um, ändert die Ereignisse und schaltet ihn ein oder aus.
- Einstellungen, die auch die REST-API nicht anbietet (Bankdaten, öffentliche Adresse, Mail-Testmodus, Einbettung, die KI-Einstellung
  selbst), API-Tokens und die eigenen Einstellungen einer Person für Benachrichtigungen (nur in der Verwaltung).

### Was ein Assistent nicht kann

**Ein Assistent kann eine Freigabe nicht abschalten.** Über MCP erlaubt `automations:write` allein, mit `update_automation`
den Namen, den Auslöser und die Einstellungen einer Automatisierung zu ändern und sie ein- oder auszuschalten. Ein Token ohne
`approvals:decide` wird abgelehnt (403), wenn es versucht,

- zu ändern, wer wann freigibt: `mode`, `max_items`, `approvals_needed` oder `approver_roles` – auch bei einer neuen
  Automatisierung: `create_automation` ohne diese Felder legt eine Automatisierung an, die ausgeschaltet ist und vor jedem Lauf
  die Freigebenden fragt (Modus *confirm*). Eine eigene Automatisierung zu löschen und neu anzulegen ist daher kein
  Weg an einer Freigabe vorbei;
- den Auslöser oder die Einstellungen einer Automatisierung im Modus *automatic* zu ändern (sie läuft ohne Freigabe) oder
  eine solche Automatisierung einzuschalten. Ausschalten und Umbenennen bleiben möglich;
- die Einstellung `mailing_approval_above` (ab wie vielen Mails eine Aussendung eine Freigabe braucht) mit
  `update_settings` zu ändern;
- eine Rolle zu geben oder zu nehmen (`assign_role`, `remove_role`): Eine Rolle kann jemanden zur Freigabe berechtigen oder die
  Verwaltung öffnen. Die zwei Tools werden nur mit `roles:write` **und** `approvals:decide` angeboten. Die Rolle
  *administrator* kann mit einem Token nie gegeben oder genommen werden, wie in der REST-API.

Diese Grenzen gelten nur für MCP; in der REST-API genügen `automations:write`, `settings:write` und `roles:write`.

**Gesundheits- und Sicherheitsdaten folgen der Einstellung „KI-Verarbeitung erlaubt“.** Mit „Nicht vertrauliche Daten“ zählen die Scopes
`health:read` und `health:write` über MCP nicht, was immer das Token hat:

- `get_event_list` lehnt die vertraulichen Listen ab (Notfallkontakte, Küche, Medizinisches, Medikamente, Versicherung, Schwimmen,
  Einwilligungen, Änderungen), und `list_event_lists` nennt sie nicht;
- `get_person_form` lässt die Antworten auf vertrauliche Fragen weg (ihre Felder stehen in `hidden`), und
  `update_person_form` lehnt es ab, sie zu schreiben;
- `export_person_data` wird abgelehnt, wenn der Export Antworten auf vertrauliche Fragen oder Notfallkontakte
  der Familie enthält;
- `list_public_submissions` zeigt nie Antworten auf vertrauliche Fragen, bei jeder Einstellung.

Mit „Alle Daten“ zählen die Scopes wie in der REST-API, und jeder Lesezugriff auf vertrauliche Daten steht im Audit-Protokoll.

**Die Buchhaltung fragt zweimal.** `reopen_period` wird ohne `confirm` `true` abgelehnt und nennt die Perioden, die mitgeöffnet
würden; `delete_cost_center` löscht Buchungen nur mit `confirm_count`, der Zahl dieser Buchungen. Der Assistent soll dem Menschen
`close_period_preview` vor `close_period` und die Zeilen vor `create_booking` zeigen; `close_period`, `reopen_period`,
`reverse_booking` und die Tools zum Löschen sind als destruktiv markiert.

**Was nicht rückgängig gemacht werden kann, braucht einen eigenen Scope und einen Menschen.** `anonymise_person` braucht `privacy:write`
(nichts anderes schließt es ein), und der Assistent wird angewiesen, es zuerst mit `preview` aufzurufen und erst nach der
Freigabe durch einen Menschen zu handeln; dasselbe gilt für `merge_duplicates`, `cancel_invoice`, `issue_invoice` und die Tools, die
löschen.

Voraussetzungen:

1. *Administration → Globale Einstellungen → KI-Assistenten*: **KI-Verarbeitung erlaubt** darf nicht auf „Keine“ stehen. Mit
   „Nicht vertrauliche Daten“ bleiben Gesundheits- und Sicherheitsdaten über MCP gesperrt, auch mit dem Scope `health:read`.
   Erlauben Sie nur, was Ihre Datenschutzerklärung und ein Vertrag zur Auftragsverarbeitung mit dem KI-Anbieter abdecken.
2. Ein Token, bei dem **Darf von KI-Assistenten (MCP) verwendet werden** eingeschaltet ist, und nur mit den Scopes, die der Assistent braucht.
   Der Assistent sieht nur die Tools, die seine Scopes erlauben.

Beispiel für die Konfiguration eines MCP-Clients (das genaue Format hängt vom Client ab):

```json
{
  "mcpServers": {
    "kasmin": {
      "type": "http",
      "url": "https://your-kasmin.example/mcp",
      "headers": { "Authorization": "Bearer kas_…" }
    }
  }
}
```

Empfehlung: Geben Sie einem KI-Assistenten `automations:run`, aber nicht `approvals:decide` – er kann Läufe vorbereiten, die
Freigebenden entscheiden per E-Mail. Geben Sie ihm `automations:write` nur, wenn er Automatisierungen einrichten soll; ohne
`approvals:decide` bleiben die Freigaben, wie sie sind. Geben Sie `finance:write`, `privacy:write`, `settings:write` und
`roles:write` nur für die jeweilige Aufgabe und mit einem Ablaufdatum: Damit kann ein Assistent ohne eine zweite Person Rechnungen
ausstellen und stornieren, Personen anonymisieren und Einstellungen ändern.

## Webhooks

*Administration → Webhooks* (Administratorinnen und Administratoren): Kasmin ruft Ihre HTTPS-Adresse auf, wenn etwas passiert. Ereignisse:
`participation.created`, `participation.status_changed`, `person.created`, `invoice.issued`, `invoice.paid`,
`payment.received`, `form.confirmed`, `automation.approval_requested`, `automation.run_finished`, `import.finished`.

Jeder Aufruf ist ein `POST` mit JSON (`event`, `occurred_at`, `data`, `delivery`) und den Headern `X-Kasmin-Event`,
`X-Kasmin-Delivery`, `X-Kasmin-Timestamp` und `X-Kasmin-Signature: sha256=<HMAC-SHA256 of "timestamp.body" with
the secret>`. Prüfen Sie die Signatur und lehnen Sie alte Zeitstempel ab (z. B. älter als 5 Minuten):

```php
$expected = 'sha256='.hash_hmac('sha256', $_SERVER['HTTP_X_KASMIN_TIMESTAMP'].'.'.$body, $secret);
$valid = hash_equals($expected, $_SERVER['HTTP_X_KASMIN_SIGNATURE'])
    && abs(time() - (int) $_SERVER['HTTP_X_KASMIN_TIMESTAMP']) < 300;
```

Antworten Sie mit einem Status 2xx. Fehlgeschlagene Zustellungen werden nach 1, 5, 30, 120 und 720 Minuten wiederholt; das Protokoll der Zustellungen
zeigt jeden Versuch und erlaubt, erneut zu senden. Die Inhalte enthalten keine Gesundheits- oder Sicherheitsdaten.

## Vorlagen und Automatisierungen als Dateien

```
php artisan kasmin:config:export kasmin-config.json         # templates and automations
php artisan kasmin:config:import kasmin-config.json --dry-run
php artisan kasmin:config:import kasmin-config.json [--update]
```

Der Import legt an, was fehlt, und lässt bestehende Einträge in Ruhe, außer `--update` ist angegeben (Vorlagen bekommen dann eine
neue Version). Vorlagen werden zuerst in der abgeschotteten Umgebung für Vorlagen geprüft; importierte Automatisierungen sind ausgeschaltet.
Die Datei ordnet Vorlagen und Automatisierungen über ihren internen Schlüssel zu, der bei den Standard-Einträgen in jeder
Installation gleich ist und in der Verwaltung nie angezeigt wird.

## Vergleich: Verwaltung, REST und MCP

Legende: ja / teils / nein / entfällt (absichtlich nur in der Verwaltung). MCP-Tools rufen die REST-API auf, MCP kann also nie mehr
als REST; was REST anbietet und MCP absichtlich nicht (Dateien, Geheimnisse von Webhooks), steht im Abschnitt zu MCP.

| Bereich | Browser | REST | MCP | Lücke |
|---|---|---|---|---|
| Personen: suchen, lesen, anlegen, ändern | ja | ja | ja | – |
| Kontaktdaten, Erziehungsberechtigte | ja | ja | ja | – |
| Familien (households) | ja | ja | ja | – |
| Doppelte Einträge: anführen, zusammenführen, als verschieden markieren | ja | ja | ja | – |
| Mitgliedschaften einer Person (Art zuweisen) | ja | ja | ja | – |
| Mitgliedsarten | ja | ja | ja | – |
| Rollen: anführen, einer Person zuweisen | ja | ja (nicht die Rolle Administration) | ja (braucht zusätzlich approvals:decide) | – |
| Gruppen, Mitglieder | ja | ja | ja | – |
| Warteliste einer Gruppe | ja | nein | nein | beide |
| Veranstaltungsreihen, Veranstaltungsvorlagen | ja | ja | ja | – |
| Veranstaltungen: Grunddaten, Rollen | ja | ja | ja | – |
| Veranstaltungen: Bus gebucht, Sitzplätze | ja | ja | ja | – |
| Veranstaltungen: Preise, Raten, Runden, Kosten, Dokumente | ja | ja (Bild in der Verwaltung) | teils (Dokumente: anführen und löschen) | MCP: keine Dateien (hochladen, herunterladen) |
| Anmeldung, Teilnehmende, Anreise | ja | ja | ja | – |
| Angebote, Reserveplätze, Vorrang auf der Warteliste | ja | teils (Angebote, Reserveplätze) | teils (wie REST) | REST und MCP: Vorrang auf der Warteliste und Überspringen |
| Gruppen, Zimmer, Dienste einer Veranstaltung | ja | ja | ja | – |
| Listen einer Veranstaltung (Teilnehmende, Bus, Küche …) | ja | ja (JSON; Gesundheitslisten brauchen health:read) | ja (Gesundheitslisten nur mit „Alle Daten“) | Dateien (PDF, XLSX) nur in der Verwaltung |
| Teilnahmebestätigung (PDF) | ja | nein | nein | REST |
| Formulare: Definition | ja | ja | ja | – |
| Formulare: Antworten einer Person | ja | ja | ja (vertrauliche Antworten nur mit „Alle Daten“) | – |
| Öffentliche Formulare und ihre Einsendungen | ja | teils (lesen, annehmen, ablehnen) | teils (wie REST) | REST und MCP: ein öffentliches Formular anlegen und ändern |
| Rechnungen: anführen, lesen, PDF, Zahlung erfassen | ja | ja | ja (kein PDF) | entfällt für MCP: Dateien |
| Rechnungen: ausstellen, senden, stornieren/Gutschrift, ermäßigter Platz | ja | teils (ausstellen, senden, stornieren) | teils (wie REST) | REST und MCP: ermäßigter Platz, Einzelrechnung |
| Zahlungen: anführen, Bankimport, zuordnen | ja | teils (anführen, zuordnen) | teils (wie REST) | REST und MCP: Bankimport, aufteilen, ein Guthaben verwenden |
| Rückzahlungen, Guthaben | ja | ja | ja | – |
| Rechnungsarchiv | ja | ja (lesen; Import in der Verwaltung) | ja (lesen) | – |
| Buchhaltung: Kontenplan, Kostenstellen, Fonds, Umbuchungen zwischen Fonds | ja | ja | ja | – |
| Buchhaltung: Perioden, Umlageschlüssel, Abschluss, Wiedereröffnen | ja | ja | ja | – |
| Buchhaltung: Buchungen (eingeben, stornieren), Journal | ja | ja (Belegdatei in der Verwaltung) | ja (ohne Belegdatei) | REST und MCP: Belegdatei |
| Buchhaltung: Bank- und Kassakonten, Berichte | ja | ja (JSON, CSV, XLSX, PDF) | ja (Tabelle) | n/a für MCP: Dateien |
| Buchhaltung: Schalter (Kostenstellen, Fonds) | ja | ja | ja (`update_settings`) | – |
| Mailvorlagen: lesen, Vorschau, ändern | ja | ja | ja | – |
| Versionen von Mailvorlagen, wiederherstellen | ja | ja | ja | – |
| Aussendungen: anlegen, vorbereiten, freigeben | ja | ja | ja | – |
| Gesendete Mails, unzustellbare Adressen | ja | ja | ja | – |
| Mailkonten, Absenderprofile | ja | teils (Absenderprofile) | teils (Absenderprofile) | entfällt für Konten und Passwörter |
| Automatisierungen: lesen, ändern, Probelauf, ausführen, Freigaben | ja | ja | ja | – |
| Eigene Automatisierungen: anlegen, löschen | ja | ja | ja (Einstellungen zur Freigabe brauchen approvals:decide) | – |
| Neue Saison / Zeitplan einer Veranstaltung | ja | teils (Zeitplan) | teils (Zeitplan) | REST und MCP: Neue Saison |
| Import von Personen | ja | ja | ja | – |
| Statistik, Zahlen des Dashboards | ja | ja | ja | – |
| Einstellungen | ja | ja | ja (mailing_approval_above braucht approvals:decide) | – |
| Audit-Protokoll | ja | ja | ja | – |
| Einwilligungen, Löschanfragen, Datenexport, anonymisieren | ja | ja (Lauf zur Aufbewahrung in der Verwaltung) | ja (Export mit Gesundheits- oder Sicherheitsdaten nur mit „Alle Daten“) | – |
| Benachrichtigungen (eigene Abonnements) | ja | entfällt (nur in der Verwaltung) | nein | gehören der angemeldeten Person |
| Webhooks | ja | ja | teils (anführen, umbenennen, Ereignisse, schalten, löschen) | entfällt für MCP: anlegen, Adresse und Signatur-Geheimnis (absichtlich nicht für einen Assistenten) |
| API-Tokens, Systemstatus, Datensicherungen, Update, Upgrade | ja | nein | nein | entfällt (absichtlich nur in der Verwaltung) |
| Portal der Familie (eigene Daten, Kinder, Sicherheitsblatt) | ja | entfällt | entfällt | entschieden: Die API ist nur für die Verwaltung gedacht |
