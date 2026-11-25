<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_household_member')]
#[Description('Adds a person to a family as legal guardian or child, or changes its role in the family. The legal guardians of the family become the legal guardians of its children (they then see the children in the portal); a guardianship that was ended stays ended.')]
class AddHouseholdMember extends ApiTool
{
    public const ENDPOINT = ['POST', '/households/{household}/members/{person}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['household' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('family id')->required(),
            'person' => $schema->string()->description('person id')->required(),
            'role' => $schema->string()->description('guardian (legal guardian) or child')->required(),
        ];
    }
}
