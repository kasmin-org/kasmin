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

#[Name('list_groups')]
#[Description('Lists all groups with kind, rules and number of members.')]
#[IsReadOnly]
class ListGroups extends ApiTool
{
    public const ENDPOINT = ['GET', '/groups'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/groups';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
