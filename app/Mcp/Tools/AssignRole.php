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

#[Name('assign_role')]
#[Description('Gives a person a role, which gives the person rights in the admin interface: a person who gets a role for the admin interface also gets a login for its primary e-mail address, and may become an approver of automations. The role administrator is always refused. Via MCP this needs the permission approvals:decide in addition to roles:write. Never give a role on your own: show the human the person and the role and ask first.')]
#[IsDestructive]
class AssignRole extends ApiTool
{
    public const ENDPOINT = ['POST', '/people/{person}/roles'];

    protected string $scope = 'roles:write';

    protected array $alsoNeeds = ['approvals:decide'];

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('person id')->required(),
            'role' => $schema->string()->description('id or exact name of the role, see list_roles')->required(),
            'event' => $schema->string()->description('event id, only for roles that count for the own events'),
            'starts_on' => $schema->string()->description('first day YYYY-MM-DD, default today'),
            'ends_on' => $schema->string()->description('last day YYYY-MM-DD'),
        ];
    }
}
