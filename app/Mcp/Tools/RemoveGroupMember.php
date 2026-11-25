<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('remove_group_member')]
#[Description('Ends the membership of a person in a manual group.')]
class RemoveGroupMember extends ApiTool
{
    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/groups/'.rawurlencode((string) ($args['id'] ?? '')).'/members/'.rawurlencode((string) ($args['person'] ?? ''));
        unset($args['id']);
        unset($args['person']);

        return $this->call('DELETE', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('group id')->required(),
            'person' => $schema->string()->description('person id')->required(),
        ];
    }
}
