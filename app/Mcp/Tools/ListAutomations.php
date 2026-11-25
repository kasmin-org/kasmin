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

#[Name('list_automations')]
#[Description('Lists the automations with trigger, mode and settings.')]
#[IsReadOnly]
class ListAutomations extends ApiTool
{
    public const ENDPOINT = ['GET', '/automations'];

    protected string $scope = 'automations:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/automations';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [

        ];
    }
}
