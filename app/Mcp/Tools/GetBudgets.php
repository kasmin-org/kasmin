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

#[Name('get_budgets')]
#[Description('The budget of a period: the planned result per cost centre (income minus costs, so planned costs are negative). The cost centre report compares it with the result.')]
#[IsReadOnly]
class GetBudgets extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/periods/{period}/budgets'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('the period: id or exact name')->required(),
        ];
    }
}
