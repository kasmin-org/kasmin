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

#[Name('list_event_documents')]
#[Description('The documents of an event (e.g. the packing list) with title, file name and who may see them (participants or public). Files cannot be read or added via MCP; that is done in the admin interface or the REST API.')]
#[IsReadOnly]
class ListEventDocuments extends ApiTool
{
    public const ENDPOINT = ['GET', '/events/{event}/documents'];

    protected string $scope = 'events:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['event' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
        ];
    }
}
