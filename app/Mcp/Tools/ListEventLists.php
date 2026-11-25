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

#[Name('list_event_lists')]
#[Description('The lists of an event this token may read (participants, figures, contacts, bus, cars, groups, birthdays, answers, missing), with their titles. The confidential lists made from the safety sheet (emergency, kitchen, medical, medication, insurance, swimmers, consents, changes) are only listed with the permission health:read, and via MCP only when the organisation allows AI processing of all data.')]
#[IsReadOnly]
class ListEventLists extends ApiTool
{
    public const ENDPOINT = ['GET', '/events/{event}/lists'];

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
