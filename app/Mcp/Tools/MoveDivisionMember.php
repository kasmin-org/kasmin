<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('move_division_member')]
#[Description('Puts one participant into a unit of a division, or takes the participant out of its unit (without unit).')]
class MoveDivisionMember extends ApiTool
{
    public const ENDPOINT = ['PUT', '/divisions/{division}/members/{participation}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request) + ['unit' => null], ['division' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('division id')->required(),
            'participation' => $schema->integer()->description('participation id as in list_divisions')->required(),
            'unit' => $schema->integer()->description('unit id; leave out to take the participant out'),
        ];
    }
}
