<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_budgets')]
#[Description('Sets the planned result of cost centres in a period (income minus costs, negative for planned costs); amount 0 removes it. Cost centres that are not listed keep their budget. A budget books nothing, so closed periods can be planned too.')]
class SaveBudgets extends ApiTool
{
    public const ENDPOINT = ['PUT', '/accounting/periods/{period}/budgets'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('the period: id or exact name')->required(),
            'budgets' => $schema->array()->items($schema->object())->description('objects with cost_center (id or exact name) and amount (planned result, negative for costs; 0 removes)')->required(),
        ];
    }
}
