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

#[Name('list_households')]
#[Description('Families (households) with address, language, invoice recipient and members (legal guardians and children). In pages; meta has total, page and last_page.')]
#[IsReadOnly]
class ListHouseholds extends ApiTool
{
    public const ENDPOINT = ['GET', '/households'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('part of the family name'),
            'page' => $schema->integer()->description('page number'),
            'per_page' => $schema->integer()->description('entries per page, up to 200 (default 50)'),
        ];
    }
}
