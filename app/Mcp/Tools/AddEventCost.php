<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_event_cost')]
#[Description('Adds a cost to an event. Costs are only used in the report "costs and income"; they change no invoice.')]
class AddEventCost extends ApiTool
{
    public const ENDPOINT = ['POST', '/events/{event}/costs'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['event' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'label' => $schema->string()->description('what the cost is for')->required(),
            'category' => $schema->string()->description('accommodation, food, transport, material, staff or other'),
            'amount' => $schema->number()->description('amount in the currency of the event'),
        ];
    }
}
