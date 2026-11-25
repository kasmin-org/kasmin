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

#[Name('list_approvals')]
#[Description('Approval requests and run history of automations (default: pending), newest first, in pages; meta has total, page and last_page.')]
#[IsReadOnly]
class ListApprovals extends ApiTool
{
    public const ENDPOINT = ['GET', '/automation-runs'];

    protected string $scope = 'automations:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/automation-runs';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('pending_approval (default), done, denied, expired, failed or all'),
            'page' => $schema->integer()->description('page number'),
            'per_page' => $schema->integer()->description('entries per page, up to 200 (default 50)'),
        ];
    }
}
