<?php

return [
    'token' => 'API token', 'tokens' => 'API tokens', 'name' => 'Name', 'user' => 'Acts for', 'scopes' => 'Permissions (scopes)',
    'last_used' => 'Last used', 'expires' => 'Expires', 'never' => 'never', 'revoked' => 'Revoked', 'revoke' => 'Revoke',
    'create' => 'Create token', 'create_hint' => 'The token acts for you with the permissions selected here. Give each integration or AI assistant its own token and only the permissions it needs.',
    'mcp' => 'May be used by AI assistants (MCP)', 'mcp_hint' => 'Additionally limited by the setting "AI processing allowed". Deciding approvals via MCP needs the scope approvals:decide.',
    'created' => 'Token created', 'created_hint' => 'Copy it now – it is shown only this once:',
    'scope_help' => [
        'people:read' => 'read people, families, roles, duplicates and public forms', 'people:write' => 'create and change people, families and memberships, merge duplicates, decide applications',
        'events:read' => 'read events and participants', 'events:write' => 'create and change events, register, change participations, offer places, prices, rounds, documents, templates, groups and rooms',
        'finance:read' => 'read invoices, payments, refunds and the invoice archive', 'finance:write' => 'record and assign payments, issue, send and cancel invoices, settle refunds, costs of events',
        'mail:read' => 'read mail templates, mail log and sender profiles', 'mail:write' => 'change templates, send mails',
        'automations:read' => 'read automations and runs, dry runs', 'automations:run' => 'start runs (as approval requests)', 'automations:write' => 'change automations: mode, trigger, approvers (careful)',
        'approvals:decide' => 'decide approvals (careful)', 'import:write' => 'import and roll back',
        'audit:read' => 'read the audit log, consents and deletion requests, export the data of a person', 'health:read' => 'read health and safety data (careful)', 'health:write' => 'change health and safety data; the legal guardians confirm them (careful)', 'settings:write' => 'change settings, sender profiles and webhooks (careful)',
        'roles:write' => 'give and take roles, except administrator (careful)', 'privacy:write' => 'anonymise people – cannot be undone (careful)',
    ],
];
