<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_membership')]
#[Description('Ends a membership of a person on a day (ends_on), or lets an ended membership run again (without ends_on). Nothing is deleted.')]
class UpdateMembership extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/people/{person}/memberships/{membership}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request) + ['ends_on' => null], ['membership' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'id' => $schema->integer()->description('membership id from list_memberships')->required(),
            'ends_on' => $schema->string()->description('last day YYYY-MM-DD; leave out to let the membership run again'),
        ];
    }
}
