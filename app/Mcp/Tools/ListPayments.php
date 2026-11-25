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

#[Name('list_payments')]
#[Description('Payments, newest first, with payer, reference, the invoice they are assigned to and the suggested invoice. In pages; meta has total, page and last_page.')]
#[IsReadOnly]
class ListPayments extends ApiTool
{
    public const ENDPOINT = ['GET', '/payments'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'match' => $schema->string()->description('auto, manual, suggested, unmatched, credit or refunded'),
            'page' => $schema->integer()->description('page number'),
            'per_page' => $schema->integer()->description('entries per page, up to 200 (default 50)'),
        ];
    }
}
