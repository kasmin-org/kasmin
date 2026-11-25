<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_roles')]
#[Description('All roles with their key and name; admin = the role may use the admin interface, own_events_only = it counts for the own events only, assignable = it can be given with assign_role (never the role administrator).')]
#[IsReadOnly]
class ListRoles extends ApiTool
{
    public const ENDPOINT = ['GET', '/roles'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
