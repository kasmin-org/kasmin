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

#[Name('reject_expense_claim')]
#[Description('Rejects a submitted expense claim; the reason is mailed to the person. Act only on the decision of a human.')]
#[IsDestructive]
class RejectExpenseClaim extends ApiTool
{
    public const ENDPOINT = ['POST', '/expense-claims/{claim}/reject'];

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
            'reason' => $schema->string()->description('reason, written to the person')->required(),
        ];
    }
}
