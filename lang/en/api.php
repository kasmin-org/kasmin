<?php

return [
    'token' => 'API token', 'tokens' => 'API tokens', 'name' => 'Name', 'user' => 'Acts for', 'scopes' => 'Permissions (scopes)',
    'last_used' => 'Last used', 'expires' => 'Expires', 'never' => 'never', 'revoked' => 'Revoked', 'revoke' => 'Revoke',
    'create' => 'Create token', 'create_hint' => 'The token acts for you with the permissions selected here. Give each integration or AI assistant its own token and only the permissions it needs.',
    'mcp' => 'May be used by AI assistants (MCP)', 'mcp_hint' => 'Additionally limited by the setting "AI processing allowed". Deciding approvals via MCP needs the scope approvals:decide.',
    'created' => 'Token created', 'created_hint' => 'Copy it now – it is shown only this once:',
    'scope_help' => [
        'people:read' => 'read people and families', 'people:write' => 'create and change people',
        'events:read' => 'read events and participants', 'events:write' => 'register, change participations',
        'finance:read' => 'read invoices', 'finance:write' => 'record payments',
        'mail:read' => 'read mail templates and log', 'mail:write' => 'send mails',
        'automations:read' => 'read automations and runs, dry runs', 'automations:run' => 'start runs (as approval requests)',
        'approvals:decide' => 'decide approvals (careful)', 'import:write' => 'import and roll back',
        'audit:read' => 'read the audit log', 'health:read' => 'read health and safety data (careful)', 'settings:write' => 'change settings (careful)',
    ],
];
