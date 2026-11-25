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
Areas of the tools: people with contact data, legal guardians, families, memberships, roles, duplicates and groups; events with series, templates, registration rounds, costs, participants, offers and reserve places, divisions (groups, rooms, duties), lists, statistics and the dashboard; invoices, payments, refunds and the invoice archive; automations and approvals; imports; forms and public forms; mail templates, mailings and the mail log; privacy (consents, data export, anonymising); settings and webhooks; the audit log. You only see the tools the token allows.
Rules:
- Use the wording "legal guardians", never "parents". Address adults formally, children informally.
- Personal data: request only what the task needs. Health and safety data are not available unless the organisation allows it.
- Changes with effect on many people (imports, automation runs, mails, invoices) follow: dry run or preview → show the result to the human → act only after their approval. Never decide approvals on your own; pass on the human's decision.
- Automations: show the human every change before update_automation. Never weaken an approval (mode, max_items, approvals_needed, approver_roles) on your own; without the permission approvals:decide these changes are refused.
- Deleting and things that cannot be undone (merge_duplicates, anonymise_person, cancel_invoice, issue_invoice, delete_… tools) and everything that sends mails to families (offer_place, release_reserve_places, send_invoice, update_participation_status with cancelled, accept_public_submission): show the human exactly what will happen and act only after their approval. anonymise_person: always with preview first.
- Roles give rights: assign_role and remove_role only on the explicit wish of the human; they need the permission approvals:decide, and the role administrator is never available.
- Files (documents of an event, PDFs) cannot be read or uploaded here; webhooks are created in the admin interface.
- Imports: import_preview first, review errors and family suggestions with the human, then import_people with a stable source name and source_id per row; rollback_import if something went wrong.
TXT)]
class KasminServer extends Server
{
    protected array $tools = [
        Tools\WhoAmI::class,
        // People, families, memberships, roles
        Tools\SearchPeople::class, Tools\GetPerson::class, Tools\CreatePerson::class, Tools\UpdatePerson::class,
        Tools\ListContactPoints::class, Tools\AddContactPoint::class, Tools\RemoveContactPoint::class,
        Tools\ListGuardians::class, Tools\AddGuardian::class, Tools\EndGuardianship::class,
        Tools\ListHouseholds::class, Tools\GetHousehold::class, Tools\CreateHousehold::class, Tools\UpdateHousehold::class, Tools\AddHouseholdMember::class, Tools\RemoveHouseholdMember::class,
        Tools\ListMembershipTypes::class, Tools\CreateMembershipType::class, Tools\UpdateMembershipType::class,
        Tools\ListMemberships::class, Tools\AddMembership::class, Tools\UpdateMembership::class,
        Tools\ListRoles::class, Tools\ListPersonRoles::class, Tools\AssignRole::class, Tools\RemoveRole::class,
        Tools\ListDuplicates::class, Tools\MergeDuplicates::class, Tools\DismissDuplicate::class,
        Tools\ListGroups::class, Tools\GetGroup::class, Tools\CreateGroup::class, Tools\UpdateGroup::class, Tools\AddGroupMember::class, Tools\RemoveGroupMember::class,
        // Events
        Tools\ListEvents::class, Tools\GetEvent::class, Tools\CreateEvent::class, Tools\UpdateEvent::class, Tools\GetEventSchedule::class,
        Tools\ListEventSeries::class, Tools\CreateEventSeries::class, Tools\UpdateEventSeries::class,
        Tools\ListEventTemplates::class, Tools\CreateEventTemplate::class, Tools\UpdateEventTemplate::class, Tools\DeleteEventTemplate::class,
        Tools\ListRegistrationRounds::class, Tools\SaveRegistrationRounds::class,
        Tools\ListEventCosts::class, Tools\AddEventCost::class, Tools\UpdateEventCost::class, Tools\DeleteEventCost::class,
        Tools\ListEventDocuments::class, Tools\DeleteEventDocument::class,
        Tools\ListParticipants::class, Tools\RegisterParticipant::class, Tools\UpdateParticipationStatus::class, Tools\SetParticipationTravel::class,
        Tools\OfferPlace::class, Tools\AssignReservePlace::class, Tools\ReleaseReservePlaces::class,
        Tools\ListDivisions::class, Tools\CreateDivision::class, Tools\DeleteDivision::class, Tools\ProposeDivision::class, Tools\MoveDivisionMember::class,
        Tools\AddDivisionUnit::class, Tools\UpdateDivisionUnit::class, Tools\DeleteDivisionUnit::class,
        Tools\ListEventLists::class, Tools\GetEventList::class, Tools\GetStatistics::class, Tools\GetDashboard::class,
        // Finance
        Tools\ListInvoices::class, Tools\GetInvoice::class, Tools\RecordPayment::class, Tools\IssueInvoice::class, Tools\SendInvoice::class, Tools\CancelInvoice::class,
        Tools\ListPayments::class, Tools\AssignPayment::class, Tools\ListRefunds::class, Tools\MarkRefundDone::class, Tools\KeepRefundAsCredit::class, Tools\ListArchivedInvoices::class,
        // Automations and approvals
        Tools\ListAutomations::class, Tools\GetAutomation::class, Tools\CreateAutomation::class, Tools\UpdateAutomation::class, Tools\DeleteAutomation::class,
        Tools\PreviewAutomation::class, Tools\RequestAutomationRun::class, Tools\ListApprovals::class, Tools\GetAutomationRun::class, Tools\DecideApproval::class,
        // Imports
        Tools\ImportPreview::class, Tools\SuggestImportFamilies::class, Tools\ImportPeople::class, Tools\ListImports::class, Tools\RollbackImport::class,
        // Forms and public forms
        Tools\ListForms::class, Tools\GetFormDefinition::class, Tools\SaveFormDefinition::class, Tools\GetPersonForms::class, Tools\GetPersonForm::class, Tools\UpdatePersonForm::class,
        Tools\ListPublicForms::class, Tools\ListPublicSubmissions::class, Tools\AcceptPublicSubmission::class, Tools\RejectPublicSubmission::class,
        // Mail
        Tools\ListMailTemplates::class, Tools\GetMailTemplate::class, Tools\UpdateMailTemplate::class, Tools\PreviewMailTemplate::class,
        Tools\ListMailTemplateVersions::class, Tools\RestoreMailTemplateVersion::class,
        Tools\ListMailings::class, Tools\GetMailing::class, Tools\CreateMailing::class, Tools\PrepareMailing::class, Tools\RequestMailingRelease::class,
        Tools\ListOutgoingMails::class, Tools\ListBadAddresses::class, Tools\ClearBadAddress::class,
        Tools\ListSenderProfiles::class, Tools\CreateSenderProfile::class, Tools\UpdateSenderProfile::class,
        // Privacy, settings, webhooks, audit log
        Tools\ListConsents::class, Tools\ListDeletionRequests::class, Tools\ExportPersonData::class, Tools\AnonymisePerson::class,
        Tools\GetSettings::class, Tools\UpdateSettings::class, Tools\ListWebhooks::class, Tools\UpdateWebhook::class, Tools\DeleteWebhook::class,
        Tools\ReadAuditLog::class,
    ];
}
