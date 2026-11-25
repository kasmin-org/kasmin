<?php

return [
    'token' => 'API-Token', 'tokens' => 'API-Tokens', 'name' => 'Name', 'user' => 'Handelt für', 'scopes' => 'Berechtigungen (Scopes)',
    'last_used' => 'Zuletzt verwendet', 'expires' => 'Läuft ab', 'never' => 'nie', 'revoked' => 'Widerrufen', 'revoke' => 'Widerrufen',
    'create' => 'Token erstellen', 'create_hint' => 'Das Token handelt in Ihrem Namen mit den hier gewählten Berechtigungen. Geben Sie jeder Anbindung und jedem KI-Assistenten ein eigenes Token mit nur den nötigen Berechtigungen.',
    'mcp' => 'Darf von KI-Assistenten (MCP) verwendet werden', 'mcp_hint' => 'Zusätzlich begrenzt durch die Einstellung „KI-Verarbeitung erlaubt“. Freigaben über MCP entscheiden braucht den Scope approvals:decide.',
    'created' => 'Token erstellt', 'created_hint' => 'Jetzt kopieren – es wird nur dieses eine Mal angezeigt:',
    'scope_help' => [
        'people:read' => 'Personen und Familien lesen', 'people:write' => 'Personen anlegen und ändern',
        'events:read' => 'Veranstaltungen und Teilnehmende lesen', 'events:write' => 'Veranstaltungen anlegen und ändern, anmelden, Teilnahmen ändern',
        'finance:read' => 'Rechnungen lesen', 'finance:write' => 'Zahlungen erfassen',
        'mail:read' => 'E-Mail-Vorlagen und Protokoll lesen', 'mail:write' => 'E-Mails senden',
        'automations:read' => 'Automatisierungen und Läufe lesen, Probeläufe', 'automations:run' => 'Läufe starten (als Freigabe-Anfrage)', 'automations:write' => 'Automatisierungen ändern: Modus, Auslöser, Freigebende (Vorsicht)',
        'approvals:decide' => 'Freigaben entscheiden (Vorsicht)', 'import:write' => 'importieren und zurückrollen',
        'audit:read' => 'Audit-Protokoll lesen', 'health:read' => 'Gesundheits- und Sicherheitsdaten lesen (Vorsicht)', 'health:write' => 'Gesundheits- und Sicherheitsdaten ändern; die Erziehungsberechtigten bestätigen sie (Vorsicht)', 'settings:write' => 'Einstellungen ändern (Vorsicht)',
    ],
];
