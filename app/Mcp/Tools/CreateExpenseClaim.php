<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_expense_claim')]
#[Description('Enters an expense claim for a person, as the treasurer does in the admin interface (no receipt file): amount and purpose, or a flat rate of the settings with a quantity. It still needs an approval. The finance roles are notified.')]
class CreateExpenseClaim extends ApiTool
{
    public const ENDPOINT = ['POST', '/expense-claims'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('public id of the person who paid')->required(),
            'date' => $schema->string()->description('date of the receipt YYYY-MM-DD, not in the future')->required(),
            'amount' => $schema->number()->description('amount in euro (not with a flat rate)'),
            'purpose' => $schema->string()->description('what the money was spent on (not needed with a flat rate)'),
            'flat_rate' => $schema->string()->description('name of a flat rate from the settings instead of amount'),
            'quantity' => $schema->number()->description('quantity for the flat rate, e.g. kilometres (default 1)'),
            'account' => $schema->integer()->description('id of an expense account (default: other expenses)'),
            'cost_center' => $schema->integer()->description('id of a cost centre (the event decides if given)'),
            'event' => $schema->string()->description('public id of the event the expense was for'),
            'advance' => $schema->integer()->description('id of an open advance of the person this receipt settles'),
            'waived' => $schema->boolean()->description('the person waives the refund (a donation)'),
        ];
    }
}
