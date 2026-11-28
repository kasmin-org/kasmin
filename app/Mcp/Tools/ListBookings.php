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

#[Name('list_bookings')]
#[Description('Lists the bookings, newest first, with number, date, text, kind, total and their lines (account, debit, credit, cost centre, fund). Filter by date, period, account, cost centre, kind and text. In pages.')]
#[IsReadOnly]
class ListBookings extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/bookings'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('from date (YYYY-MM-DD)'),
            'to' => $schema->string()->description('to date (YYYY-MM-DD)'),
            'period' => $schema->string()->description('period: id or exact name'),
            'account' => $schema->string()->description('bookings with a line on this account: id or exact name or account number'),
            'cost_center' => $schema->string()->description('bookings with a line on this cost centre: id or exact name'),
            'kind' => $schema->string()->description('manual, invoice, credit_note, payment, refund, expense, expense_payout, allocation, closing, fund_transfer, opening …'),
            'search' => $schema->string()->description('text contains'),
            'page' => $schema->integer()->description('page number'),
        ];
    }
}
