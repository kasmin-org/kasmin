<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('update_settings')]
#[Description('Changes settings of the organisation; only the fields that are sent change. They apply to everybody at once (e.g. the name and legal footer on all invoices and mails). Via MCP, changing mailing_approval_above (from how many recipients a mailing needs an approval) also needs the permission approvals:decide. Show the human the change and ask first.')]
#[IsDestructive]
class UpdateSettings extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/settings'];

    protected string $scope = 'settings:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['organisation_address', 'organisation_email', 'organisation_website', 'organisation_legal_footer', 'digest_time', 'approval_days']));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'organisation_name' => $schema->string()->description('name of the organisation'),
            'organisation_address' => $schema->string()->description('address'),
            'organisation_email' => $schema->string()->description('e-mail address'),
            'organisation_website' => $schema->string()->description('website (URL)'),
            'organisation_legal_footer' => $schema->string()->description('legal footer of invoices and mails'),
            'invoice_due_days' => $schema->integer()->description('days until an invoice is due'),
            'invoice_reminder_days_before' => $schema->integer()->description('days before the due date for the reminder'),
            'invoice_overdue_days' => $schema->string()->description('days after the due date for the overdue reminders, separated by commas, e.g. "7,21"'),
            'invoice_attach_paid' => $schema->boolean()->description('attach the paid invoice to the payment confirmation'),
            'max_guardians' => $schema->integer()->description('legal guardians per family at most'),
            'mailing_approval_above' => $schema->integer()->description('mailings to more recipients need an approval; 0 = never. Needs approvals:decide'),
            'digest_time' => $schema->string()->description('time of the daily digest HH:MM'),
            'approval_days' => $schema->integer()->description('days an approval request stays open'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: organisation_address, organisation_email, organisation_website, organisation_legal_footer, digest_time, approval_days'),
        ];
    }
}
