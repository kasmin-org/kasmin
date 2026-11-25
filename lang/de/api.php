<?php

return [
    'token' => 'API-Token', 'tokens' => 'API-Tokens', 'name' => 'Name', 'user' => 'Handelt für', 'scopes' => 'Berechtigungen (Scopes)',
    'last_used' => 'Zuletzt verwendet', 'expires' => 'Läuft ab', 'never' => 'nie', 'revoked' => 'Widerrufen', 'revoke' => 'Widerrufen',
    'create' => 'Token erstellen', 'create_hint' => 'Das Token handelt in Ihrem Namen mit den hier gewählten Berechtigungen. Geben Sie jeder Anbindung und jedem KI-Assistenten ein eigenes Token mit nur den nötigen Berechtigungen.',
    'mcp' => 'Darf von KI-Assistenten (MCP) verwendet werden', 'mcp_hint' => 'Zusätzlich begrenzt durch die Einstellung „KI-Verarbeitung erlaubt“. Freigaben über MCP entscheiden braucht den Scope approvals:decide.',
    'created' => 'Token erstellt', 'created_hint' => 'Jetzt kopieren – es wird nur dieses eine Mal angezeigt:',
    'scope_help' => [
        'people:read' => 'Personen, Familien, Rollen, Dubletten und öffentliche Formulare lesen', 'people:write' => 'Personen, Familien und Mitgliedschaften anlegen und ändern, Dubletten zusammenführen, Bewerbungen entscheiden',
        'events:read' => 'Veranstaltungen und Teilnehmende lesen', 'events:write' => 'Veranstaltungen anlegen und ändern, anmelden, Teilnahmen ändern, Plätze anbieten, Preise, Runden, Dokumente, Vorlagen, Gruppen und Zimmer',
        'finance:read' => 'Rechnungen, Zahlungen, Rückzahlungen und Rechnungsarchiv lesen', 'finance:write' => 'Zahlungen erfassen und zuordnen, Rechnungen ausstellen, senden und stornieren, Rückzahlungen erledigen, Kosten von Veranstaltungen',
        'mail:read' => 'E-Mail-Vorlagen, Protokoll und Absenderprofile lesen', 'mail:write' => 'Vorlagen ändern, E-Mails senden',
        'automations:read' => 'Automatisierungen und Läufe lesen, Probeläufe', 'automations:run' => 'Läufe starten (als Freigabe-Anfrage)', 'automations:write' => 'Automatisierungen ändern: Modus, Auslöser, Freigebende (Vorsicht)',
        'approvals:decide' => 'Freigaben entscheiden (Vorsicht)', 'import:write' => 'importieren und zurückrollen',
        'audit:read' => 'Audit-Protokoll, Einwilligungen und Löschanfragen lesen, Daten einer Person exportieren', 'health:read' => 'Gesundheits- und Sicherheitsdaten lesen (Vorsicht)', 'health:write' => 'Gesundheits- und Sicherheitsdaten ändern; die Erziehungsberechtigten bestätigen sie (Vorsicht)', 'settings:write' => 'Einstellungen, Absenderprofile und Webhooks ändern (Vorsicht)',
        'roles:write' => 'Rollen vergeben und entziehen, außer Administrator (Vorsicht)', 'privacy:write' => 'Personen anonymisieren – nicht umkehrbar (Vorsicht)',
    ],
];
