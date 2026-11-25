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

#[Name('delete_automation')]
#[Description('Deletes an own automation for good, with its runs and open approval requests. Standard automations cannot be deleted; switch them off with update_automation instead. Ask the human first.')]
#[IsDestructive]
class DeleteAutomation extends ApiTool
{
    public const ENDPOINT = ['DELETE', '/automations/{automation}'];

    protected string $scope = 'automations:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['automation' => 'key']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('automation key')->required(),
        ];
    }
}
