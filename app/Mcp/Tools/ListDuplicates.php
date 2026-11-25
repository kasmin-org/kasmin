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

#[Name('list_duplicates')]
#[Description('Pairs of people that are probably the same, with the reason (name, name_birth, sound_birth, mail). At most 100. Nothing is ever merged automatically.')]
#[IsReadOnly]
class ListDuplicates extends ApiTool
{
    public const ENDPOINT = ['GET', '/duplicates'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
