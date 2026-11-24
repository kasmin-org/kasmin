<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('request_automation_run')]
#[Description('Starts an automation. Without the permission approvals:decide this only creates an approval request: the approvers get an e-mail and decide.')]
class RequestAutomationRun extends ApiTool
{
    protected string $scope = 'automations:run';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/automations/'.rawurlencode((string) ($args['key'] ?? '')).'/run';
        unset($args['key']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('automation key')->required(),
            'event' => $schema->string()->description('event id, for automations that work on one event'),
        ];
    }
}
