<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_group')]
#[Description('Changes a group: name, description, parent group, event or the rules of a dynamic group. Changed rules change the members at once, and automations that react to joining or leaving a group may run for them: ask the human first.')]
class UpdateGroup extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/groups/{group}'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['description', 'event', 'parent', 'filter']), ['group' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('group id')->required(),
            'name' => $schema->string()->description('name'),
            'kind' => $schema->string()->description('manual, dynamic or event'),
            'description' => $schema->string()->description('description'),
            'event' => $schema->string()->description('event id'),
            'parent' => $schema->integer()->description('id of the parent group'),
            'filter' => $schema->object()->description('rules of a dynamic group, as returned by get_group: {match: all or any, rules: [{field: age, role, membership, group or events; value, min, max, not}], statuses, roles}'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: description, event, parent, filter'),
        ];
    }
}
