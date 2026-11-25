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

#[Name('list_consents')]
#[Description('Consents and opt-outs of people with their proof (kind, granted, source, time), newest first. In pages; meta has total, page and last_page.')]
#[IsReadOnly]
class ListConsents extends ApiTool
{
    public const ENDPOINT = ['GET', '/consents'];

    protected string $scope = 'audit:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->description('kind of consent'),
            'person' => $schema->string()->description('person id'),
            'page' => $schema->integer()->description('page number'),
            'per_page' => $schema->integer()->description('entries per page, up to 200 (default 50)'),
        ];
    }
}
