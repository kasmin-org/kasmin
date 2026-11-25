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

#[Name('get_event_list')]
#[Description('One list of an event as a table (headers, rows). Confidential lists (health and safety data) need the permission health:read, are refused via MCP unless the organisation allows AI processing of all data, and every read of one is recorded in the audit log. Request a confidential list only when the task needs it.')]
#[IsReadOnly]
class GetEventList extends ApiTool
{
    public const ENDPOINT = ['GET', '/events/{event}/lists/{key}'];

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
            'key' => $schema->string()->description('key of the list from list_event_lists, e.g. participants or bus')->required(),
            'role' => $schema->string()->description('only this role: child, junior_counsellor, staff or guest'),
        ];
    }
}
