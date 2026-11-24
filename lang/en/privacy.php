<?php

return [
    'title' => 'Deletion and retention', 'requests' => 'Deletion requests', 'requests_hint' => 'Requests from the links in the mails. Check what can be deleted and what must be kept, then anonymise. The person is informed by you.',
    'no_requests' => 'No open deletion requests.', 'review' => 'Review', 'plan_for' => 'Anonymise :name',
    'will_delete' => 'Will be deleted', 'will_keep' => 'Will be kept', 'reason' => 'Reason (for the audit log)', 'default_reason' => 'Deletion request (GDPR Art. 17)',
    'anonymise' => 'Anonymise now', 'confirm' => 'Anonymise this person? This cannot be undone.', 'done' => 'The person was anonymised.',
    'has_roles' => 'This person still has an active role. Remove the role first.',
    'anonymous' => 'Anonymised',
    'items' => ['contact_points' => 'Contact data', 'login' => 'Login', 'form_responses' => 'Form answers (incl. safety sheets)', 'mails' => 'Mails sent to the person',
        'family_links' => 'Links to family and legal guardians', 'groups' => 'Group memberships', 'invoices' => 'Invoices', 'participations' => 'Participations', 'audit' => 'Audit log'],
    'reason_invoices' => 'legal retention period (:years years); the name stays on the invoice',
    'reason_statistics' => 'kept anonymised for statistics (year of birth, role, year)',
    'reason_audit' => 'the audit log cannot be changed; it records that the person was anonymised',
    'retention' => 'Retention of confidential data', 'retention_hint' => 'Answers of forms with health or identity data are deleted :years years after the person\'s last event (setting in "Organisation and invoices"). This runs automatically every night.',
    'retention_none' => 'Nothing to delete at the moment.', 'retention_run' => 'Delete now', 'retention_confirm' => 'Delete these answers now?', 'retention_done' => ':count answer(s) deleted.',
    'answers' => '{1} 1 answer|[2,*] :count answers', 'since' => 'last event or answer',
    'audit' => 'Audit log', 'audit_entry' => 'Audit entry', 'time' => 'Time', 'action' => 'Action', 'actor' => 'Actor', 'object' => 'Object',
    'action_prefix' => 'Action starts with', 'from' => 'From', 'to' => 'To', 'changes' => 'Changes', 'details' => 'Details',
    'consent' => 'Consent', 'consents' => 'Consents and opt-outs', 'kind' => 'Kind', 'granted' => 'Granted', 'source' => 'Source',
];
