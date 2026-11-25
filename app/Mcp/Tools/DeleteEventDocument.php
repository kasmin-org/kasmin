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

#[Name('delete_event_document')]
#[Description('Deletes a document of an event for good; its file is deleted unless a copy of the event still uses it. Families no longer see it in the portal. Ask the human first.')]
#[IsDestructive]
class DeleteEventDocument extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/event-documents/{document}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['document' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('document id from list_event_documents')->required(),
        ];
    }
}
