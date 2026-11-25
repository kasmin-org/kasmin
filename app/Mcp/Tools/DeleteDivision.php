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

#[Name('delete_division')]
#[Description('Deletes a division with its units and who was placed where; this cannot be undone. The people stay registered for the event. Ask the human first.')]
#[IsDestructive]
class DeleteDivision extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/divisions/{division}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['division' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('division id')->required(),
        ];
    }
}
