<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('keep_refund_as_credit')]
#[Description('Declines a proposed refund: the family keeps the amount as a credit for later invoices. Ask the human first.')]
class KeepRefundAsCredit extends ApiTool
{
    public const ENDPOINT = ['POST', '/refunds/{refund}/keep'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['refund' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('refund id from list_refunds')->required(),
        ];
    }
}
