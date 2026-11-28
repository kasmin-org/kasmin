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

#[Name('list_accounts')]
#[Description('Lists the chart of accounts with id, name, number, type (asset, liability, equity, income, expense), money account (bank, cash), standard cost centre, whether it is active and the balance today; bank and cash accounts also with the funds on them and the difference.')]
#[IsReadOnly]
class ListAccounts extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/accounts'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('only this type: asset, liability, equity, income or expense'),
            'money' => $schema->string()->description('only money accounts: bank, cash or any'),
            'active' => $schema->boolean()->description('only active (true) or inactive (false) accounts'),
        ];
    }
}
