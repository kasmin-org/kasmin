<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('import_people')]
#[Description('Imports rows as one batch (same fields as import_preview). Rows with the same source and source_id update instead of duplicating. Only after a human approved the dry run.')]
class ImportPeople extends ApiTool
{
    public const ENDPOINT = ['POST', '/imports/people'];

    protected string $scope = 'import:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/imports/people';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->description('name of the source system')->required(),
            'rows' => $schema->array()->items($schema->object())->description('rows, see import_preview')->required(),
        ];
    }
}
