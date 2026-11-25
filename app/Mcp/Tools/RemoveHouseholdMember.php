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

#[Name('remove_household_member')]
#[Description('Takes a person out of a family. The person and its guardianships stay; if it was the invoice recipient, the recipient is chosen automatically again.')]
#[IsDestructive]
class RemoveHouseholdMember extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/households/{household}/members/{person}'];

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
        ];
    }
}
