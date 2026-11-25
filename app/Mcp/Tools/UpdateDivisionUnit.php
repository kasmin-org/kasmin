<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_division_unit')]
#[Description('Changes the name, the note or the size of a unit of a division.')]
class UpdateDivisionUnit extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/units/{unit}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['note', 'capacity']), ['unit' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('unit id from list_divisions')->required(),
            'name' => $schema->string()->description('name'),
            'note' => $schema->string()->description('note'),
            'capacity' => $schema->integer()->description('people in the unit, 1 to 500'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: note, capacity'),
        ];
    }
}
