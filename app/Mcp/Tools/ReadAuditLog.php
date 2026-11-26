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

#[Name('read_audit_log')]
#[Description('Reads the audit log (who did what when).')]
#[IsReadOnly]
class ReadAuditLog extends ApiTool
{
    public const ENDPOINT = ['GET', '/audit'];

    protected string $scope = 'audit:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/audit';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('action prefix, e.g. login. or import.'),
            'from' => $schema->string()->description('from date'),
            'to' => $schema->string()->description('to date'),
        ];
    }
}
