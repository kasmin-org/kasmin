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

#[Name('get_automation')]
#[Description('One automation with trigger, settings, mode, approvers and the last run.')]
#[IsReadOnly]
class GetAutomation extends ApiTool
{
    public const ENDPOINT = ['GET', '/automations/{automation}'];

    protected string $scope = 'automations:read';

    public function handle(Request $request): Response
    {
        $args = $this->withRecord($this->args($request), 'automation');
        $path = '/automations/'.rawurlencode((string) ($args['automation'] ?? ''));
        unset($args['automation']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation' => $schema->string()->description('id or exact name of the automation, see list_automations')->required(),
        ];
    }
}
