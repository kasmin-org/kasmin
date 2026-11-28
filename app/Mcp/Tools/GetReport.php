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

#[Name('get_report')]
#[Description('A report of the accounting as a table (title, headers, rows; amounts as text): balance (balance sheet on date), income (income statement of period, optionally of cost_center), cost_centers (period), funds (statement of funds, period), ledger (account from to) or open_items (what families owe and what is owed to people, on date). Date default today, period default the current one.')]
#[IsReadOnly]
class GetReport extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/reports/{report}'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        unset($args['format']); // files (CSV, XLSX, PDF) are not for a tool answer

        return $this->callEndpoint($args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->description('balance, income, cost_centers, funds, ledger or open_items')->required(),
            'date' => $schema->string()->description('day (YYYY-MM-DD) for balance and open_items'),
            'period' => $schema->string()->description('period: id or exact name'),
            'cost_center' => $schema->string()->description('income: only this cost centre, id or exact name'),
            'account' => $schema->string()->description('ledger: the account, id or exact name or account number'),
            'from' => $schema->string()->description('ledger: from date'),
            'to' => $schema->string()->description('ledger: to date'),
        ];
    }
}
