<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_group_member')]
#[Description('Adds a person to a manual group.')]
class AddGroupMember extends ApiTool
{
    public const ENDPOINT = ['POST', '/groups/{group}/members'];

    protected string $scope = 'people:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/groups/'.rawurlencode((string) ($args['id'] ?? '')).'/members';
        unset($args['id']);

        return $this->call('POST', $path, $args);
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
