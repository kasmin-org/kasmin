<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('record_payment')]
#[Description('Records a payment for an open invoice; a fully paid invoice sends the payment confirmation to the family.')]
class RecordPayment extends ApiTool
{
    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/invoices/'.rawurlencode((string) ($args['id'] ?? '')).'/payments';
        unset($args['id']);

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('invoice id')->required(),
            'amount' => $schema->number()->description('amount in the invoice currency')->required(),
            'paid_on' => $schema->string()->description('date YYYY-MM-DD')->required(),
            'method' => $schema->string()->description('bank, cash or other'),
            'note' => $schema->string()->description('note'),
        ];
    }
}
