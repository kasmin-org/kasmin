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

#[Name('suggest_import_families')]
#[Description('Suggests families for rows of an import (same rows as import_preview), without the checks of the rows. Changes nothing.')]
#[IsReadOnly]
class SuggestImportFamilies extends ApiTool
{
    public const ENDPOINT = ['POST', '/imports/people/families'];

    protected string $scope = 'import:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rows' => $schema->array()->items($schema->object())->description('rows as for import_preview')->required(),
            'source' => $schema->string()->description('name of the source system, lowercase, e.g. old-list')->required(),
        ];
    }
}
