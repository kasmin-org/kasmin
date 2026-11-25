<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_mailing')]
#[Description('Creates the draft of a mailing. Nothing is sent.')]
class CreateMailing extends ApiTool
{
    protected string $scope = 'mail:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/mailings';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('name')->required(),
            'template' => $schema->string()->description('template key')->required(),
            'event' => $schema->string()->description('event id'),
            'groups' => $schema->array()->description('group ids'),
            'people' => $schema->array()->description('person ids'),
            'statuses' => $schema->array()->description('participation statuses'),
            'routing' => $schema->string()->description('guardians, person or both'),
        ];
    }
}
