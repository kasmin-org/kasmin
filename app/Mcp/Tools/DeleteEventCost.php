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

#[Name('delete_event_cost')]
#[Description('Deletes a cost of an event for good.')]
#[IsDestructive]
class DeleteEventCost extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/costs/{cost}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['cost' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('cost id from list_event_costs')->required(),
        ];
    }
}
