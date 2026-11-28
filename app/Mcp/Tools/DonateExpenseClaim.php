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

#[Name('donate_expense_claim')]
#[Description('Books an approved expense claim as a donation because the person waives the refund. Only on the explicit wish of the person, passed on by a human.')]
#[IsDestructive]
class DonateExpenseClaim extends ApiTool
{
    public const ENDPOINT = ['POST', '/expense-claims/{claim}/donate'];

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
        ];
    }
}
