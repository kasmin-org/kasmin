<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP endpoint (X04) for AI assistants, on top of the REST API v1 with the same token and scopes.
 */
#[Name('Kasmin')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Kasmin manages the members, families, events, waitlists, invoices and automations of an organisation (e.g. a holiday camp).
Rules:
- Use the wording "legal guardians", never "parents". Address adults formally, children informally.
- Personal data: request only what the task needs. Health and safety data are not available unless the organisation allows it.
- Changes with effect on many people (imports, automation runs, mails, invoices) follow: dry run or preview → show the result to the human → act only after their approval. Never decide approvals on your own; pass on the human's decision.
- Imports: import_preview first, review errors and family suggestions with the human, then import_people with a stable source name and source_id per row; rollback_import if something went wrong.
TXT)]
class KasminServer extends Server
{
    protected array $tools = [
        Tools\WhoAmI::class,
        Tools\SearchPeople::class, Tools\GetPerson::class, Tools\CreatePerson::class,
        Tools\ListEvents::class, Tools\GetEvent::class, Tools\ListParticipants::class, Tools\RegisterParticipant::class,
        Tools\ListInvoices::class, Tools\GetInvoice::class, Tools\RecordPayment::class,
        Tools\ListAutomations::class, Tools\PreviewAutomation::class, Tools\RequestAutomationRun::class, Tools\ListApprovals::class, Tools\DecideApproval::class,
        Tools\ImportPreview::class, Tools\ImportPeople::class, Tools\ListImports::class, Tools\RollbackImport::class,
        Tools\ListGroups::class, Tools\GetGroup::class, Tools\CreateGroup::class, Tools\AddGroupMember::class, Tools\RemoveGroupMember::class, Tools\ListForms::class, Tools\GetPersonForms::class, Tools\ListMailTemplates::class, Tools\GetMailTemplate::class, Tools\PreviewMailTemplate::class, Tools\ListMailings::class, Tools\GetMailing::class, Tools\CreateMailing::class, Tools\PrepareMailing::class, Tools\RequestMailingRelease::class,
        Tools\ReadAuditLog::class,
    ];
}
