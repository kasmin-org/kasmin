<?php

return [
    'role' => 'Role', 'roles' => 'Roles', 'assign' => 'Assign role', 'from' => 'From', 'until' => 'Until', 'is_admin' => 'Access to the admin interface', 'admin_short' => 'Admin', 'permissions' => 'Permissions', 'none' => 'No access', 'everything' => 'Everything',
    'levels' => ['view' => 'View', 'edit' => 'Edit'],
    'areas' => ['people' => 'People, families, groups', 'events' => 'Events and participants', 'forms' => 'Forms', 'finance' => 'Finance', 'mailings' => 'Mailings and mail log', 'mail_settings' => 'Mail accounts and senders', 'automations' => 'Automations', 'settings' => 'Settings', 'privacy' => 'Privacy and consents', 'safety' => 'Safety sheets and health data', 'audit' => 'Audit log'],
    'contact_protected' => 'Only administrators can change the e-mail address of a person with an administrative role.', 'only_administrators' => 'Only administrators can assign roles.', 'last_administrator' => 'The last administrator cannot be removed.',
    'one_administrator' => 'There is only one administrator. Please name a second one, so that the organisation does not depend on one person.',
    'scope' => 'Counts for', 'scopes' => ['all' => 'Everything', 'own' => 'Own events only'], 'scope_hint' => 'Own events: events the person takes part in as team member (confirmed by the team) or was assigned to, until 180 days after the end. All other areas are closed.', 'event_hint' => 'Empty = events the person takes part in as team member.',
];
