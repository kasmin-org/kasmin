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

#[Name('get_invoice')]
#[Description('One invoice with lines and payments.')]
#[IsReadOnly]
class GetInvoice extends ApiTool
{
    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/invoices/'.rawurlencode((string) ($args['id'] ?? ''));
        unset($args['id']);

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('invoice id')->required(),
        ];
    }
}
