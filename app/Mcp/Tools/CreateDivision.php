<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_division')]
#[Description('Creates a division of an event (groups, rooms or duties) with a number of units. Nobody is placed yet; use propose_division.')]
class CreateDivision extends ApiTool
{
    public const ENDPOINT = ['POST', '/events/{event}/divisions'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['event' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'kind' => $schema->string()->description('groups, rooms or duty')->required(),
            'name' => $schema->string()->description('name')->required(),
            'units' => $schema->integer()->description('number of units, 1 to 100')->required(),
            'capacity' => $schema->integer()->description('people per unit'),
            'options' => $schema->object()->description('optional: roles (list of roles that are placed), balance_age, balance_gender, separate_gender (true or false), siblings (apart, together or any); the defaults depend on the kind'),
        ];
    }
}
