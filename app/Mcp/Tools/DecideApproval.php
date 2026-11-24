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

#[Name('decide_approval')]
#[Description('Approves or denies a pending automation run. Only with the permission approvals:decide; ask the human first and pass on their decision.')]
#[IsDestructive]
class DecideApproval extends ApiTool
{
    protected string $scope = 'approvals:decide';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/automation-runs/'.rawurlencode((string) ($args['id'] ?? '')).'/decision';
        unset($args['id']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('run id')->required(),
            'decision' => $schema->string()->description('approve or deny')->required(),
            'comment' => $schema->string()->description('comment'),
        ];
    }
}
