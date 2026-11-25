<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('export_person_data')]
#[Description('The copy of all data of a person and of the children it is a legal guardian of (contact data, families, registrations with answers, forms, invoices, mails, consents). Needs audit:read and people:read; when the export holds health or safety data (answers to confidential questions, emergency contacts) it also needs health:read and is refused via MCP unless the organisation allows AI processing of all data. Every export is recorded in the audit log. Only when a human asked for the export of this person.')]
#[IsReadOnly]
class ExportPersonData extends ApiTool
{
    public const ENDPOINT = ['GET', '/people/{person}/export'];

    protected string $scope = 'audit:read';

    protected array $alsoNeeds = ['people:read'];

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['person' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id')->required(),
        ];
    }
}
