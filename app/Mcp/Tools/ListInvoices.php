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

#[Name('list_invoices')]
#[Description('Invoices with status, open amount, due date and reference.')]
#[IsReadOnly]
class ListInvoices extends ApiTool
{
    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/invoices';

        return $this->call('GET', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('issued, partially_paid, paid, overdue, cancelled'),
            'event' => $schema->string()->description('event id'),
            'page' => $schema->integer()->description('page number'),
        ];
    }
}
