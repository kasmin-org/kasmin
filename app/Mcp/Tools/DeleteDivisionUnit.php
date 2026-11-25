<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_division_unit')]
#[Description('Deletes a unit of a division. Only a unit with nobody in it can be deleted.')]
#[IsDestructive]
class DeleteDivisionUnit extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/units/{unit}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['unit' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('unit id from list_divisions')->required(),
        ];
    }
}
