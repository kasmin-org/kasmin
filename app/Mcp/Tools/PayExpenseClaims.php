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

#[Name('pay_expense_claims')]
#[Description('Records that approved expense claims are paid out from a bank or cash account on a day and mails the people. No money is moved and no SEPA file is returned here; only record what a human pays or uploads in online banking.')]
#[IsDestructive]
class PayExpenseClaims extends ApiTool
{
    public const ENDPOINT = ['POST', '/expense-claims/pay'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'claims' => $schema->array()->items($schema->integer())->description('ids of approved expense claims')->required(),
            'account' => $schema->string()->description('id of a bank or cash account, or "bank" or "cash"')->required(),
            'date' => $schema->string()->description('day of the payout YYYY-MM-DD')->required(),
            'per_person' => $schema->boolean()->description('one transfer per person in the SEPA file'),
        ];
    }
}
