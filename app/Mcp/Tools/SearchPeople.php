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

#[Name('search_people')]
#[Description('Searches people by name; returns id, names, date of birth, age. Use get_person for details.')]
#[IsReadOnly]
class SearchPeople extends ApiTool
{
    public const ENDPOINT = ['GET', '/people'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/people';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('part of the first or last name'),
            'group' => $schema->integer()->description('only members of this group id'),
            'page' => $schema->integer()->description('page number'),
        ];
    }
}
