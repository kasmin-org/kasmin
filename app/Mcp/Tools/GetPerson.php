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

#[Name('get_person')]
#[Description('One person with contact data, legal guardians, children, families, groups and participations. No health data.')]
#[IsReadOnly]
class GetPerson extends ApiTool
{
    public const ENDPOINT = ['GET', '/people/{person}'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people/'.rawurlencode((string) ($args['id'] ?? ''));
        unset($args['id']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('person id from search_people')->required(),
        ];
    }
}
