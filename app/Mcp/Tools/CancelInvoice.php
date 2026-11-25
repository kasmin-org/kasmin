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

#[Name('cancel_invoice')]
#[Description('Cancels an issued invoice with a credit note; this cannot be undone. The reason is printed on the credit note. Payments of the invoice become a credit of the family with a refund proposal. The answer is the credit note. Show the human the invoice and ask first.')]
#[IsDestructive]
class CancelInvoice extends ApiTool
{
    public const ENDPOINT = ['POST', '/invoices/{invoice}/cancel'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['invoice' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('invoice id')->required(),
            'reason' => $schema->string()->description('reason, printed on the credit note (up to 120 characters)')->required(),
        ];
    }
}
