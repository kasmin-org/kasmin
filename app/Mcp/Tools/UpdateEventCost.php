<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_event_cost')]
#[Description('Changes a cost of an event; only the fields that are sent change.')]
class UpdateEventCost extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/costs/{cost}'];

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
            'label' => $schema->string()->description('what the cost is for'),
            'category' => $schema->string()->description('accommodation, food, transport, material, staff or other'),
            'amount' => $schema->number()->description('amount in the currency of the event'),
        ];
    }
}
