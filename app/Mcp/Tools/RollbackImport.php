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

#[Name('rollback_import')]
#[Description('Rolls an import batch back (only if nothing was changed afterwards).')]
#[IsDestructive]
class RollbackImport extends ApiTool
{
    protected string $scope = 'import:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/imports/'.rawurlencode((string) ($args['id'] ?? '')).'/rollback';
        unset($args['id']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('batch id')->required(),
        ];
    }
}
