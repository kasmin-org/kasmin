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

#[Name('approve_expense_claim')]
#[Description('Approves a submitted expense claim: books the expense and what is owed to the person, and mails the person. Cannot be undone; act only on the decision of a human. Account and cost centre can be corrected.')]
#[IsDestructive]
class ApproveExpenseClaim extends ApiTool
{
    public const ENDPOINT = ['POST', '/expense-claims/{claim}/approve'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['claim' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('expense claim id from list_expense_claims')->required(),
            'account' => $schema->integer()->description('id of the expense account, if it should change'),
            'cost_center' => $schema->integer()->description('id of the cost centre, if it should change'),
        ];
    }
}
