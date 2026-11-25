<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_group')]
#[Description('Creates a group: manual, dynamic (filter with match and rules: age, membership, role, group, events) or event.')]
class CreateGroup extends ApiTool
{
    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/groups';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('name')->required(),
            'kind' => $schema->string()->description('manual, dynamic or event')->required(),
            'event' => $schema->string()->description('event id'),
            'filter' => $schema->object()->description('rules'),
        ];
    }
}
