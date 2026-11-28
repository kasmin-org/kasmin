<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_account')]
#[Description('Changes an account; only the fields that are sent change. System accounts keep their type, and the type of an account with bookings cannot change.')]
class UpdateAccount extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/accounting/accounts/{account}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['number', 'money', 'iban', 'default_cost_center']));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()->description('the account: id or exact name or account number')->required(),
            'name' => $schema->string()->description('name'),
            'type' => $schema->string()->description('asset, liability, equity, income or expense'),
            'number' => $schema->string()->description('account number'),
            'money' => $schema->string()->description('bank or cash (asset accounts only)'),
            'iban' => $schema->string()->description('IBAN of a bank account'),
            'default_cost_center' => $schema->string()->description('standard cost centre: id or exact name'),
            'active' => $schema->boolean()->description('active'),
            'sort' => $schema->integer()->description('order in the chart'),
            'clear' => $schema->array()->items($schema->string())->description('optional fields to empty: number, money, iban, default_cost_center'),
        ];
    }
}
