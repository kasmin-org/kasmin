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

#[Name('preview_automation')]
#[Description('Dry run: what an automation would do now. Changes nothing.')]
#[IsReadOnly]
class PreviewAutomation extends ApiTool
{
    protected string $scope = 'automations:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/automations/'.rawurlencode((string) ($args['key'] ?? '')).'/preview';
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
