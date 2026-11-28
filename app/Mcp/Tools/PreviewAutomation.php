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
    public const ENDPOINT = ['POST', '/automations/{automation}/preview'];

    protected string $scope = 'automations:read';

    public function handle(Request $request): Response
    {
        $args = $this->withRecord($this->args($request), 'automation');
        $path = '/automations/'.rawurlencode((string) ($args['automation'] ?? '')).'/preview';
        unset($args['automation']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation' => $schema->string()->description('id or exact name of the automation, see list_automations')->required(),
            'event' => $schema->string()->description('event id, for automations that work on one event'),
        ];
    }
}
