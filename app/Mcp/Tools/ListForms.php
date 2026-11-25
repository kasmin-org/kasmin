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

#[Name('list_forms')]
#[Description('Lists the forms (safety sheet, general information, questions of events) with their fields.')]
#[IsReadOnly]
class ListForms extends ApiTool
{
    public const ENDPOINT = ['GET', '/forms'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/forms';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
