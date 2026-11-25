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

#[Name('remove_role')]
#[Description('Takes a role from a person; the assignment is deleted and the person loses the rights of the role at once. The role administrator is always refused. Via MCP this needs the permission approvals:decide in addition to roles:write. Ask the human first.')]
#[IsDestructive]
class RemoveRole extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/people/{person}/roles/{assignment}'];

    protected string $scope = 'roles:write';

    protected array $alsoNeeds = ['approvals:decide'];

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['assignment' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'id' => $schema->integer()->description('id of the assignment from list_person_roles')->required(),
        ];
    }
}
