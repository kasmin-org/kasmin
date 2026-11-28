<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_account')]
#[Description('Creates an account of the chart of accounts. Name and type are required; only asset accounts can be bank or cash accounts, only bank accounts have an IBAN, only income and expense accounts a standard cost centre.')]
class CreateAccount extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/accounts'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('unique name')->required(),
            'type' => $schema->string()->description('asset, liability, equity, income or expense')->required(),
            'number' => $schema->string()->description('account number (optional)'),
            'money' => $schema->string()->description('bank or cash (asset accounts only)'),
            'iban' => $schema->string()->description('IBAN of a bank account'),
            'default_cost_center' => $schema->string()->description('standard cost centre (income and expense accounts): id or exact name'),
            'active' => $schema->boolean()->description('active (default true); inactive accounts cannot be booked on'),
            'sort' => $schema->integer()->description('order in the chart'),
        ];
    }
}
