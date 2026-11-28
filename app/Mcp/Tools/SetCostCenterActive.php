<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_cost_center_active')]
#[Description('Activates or deactivates a cost centre in an open period; inactive cost centres cannot be booked on in it. The answer has warnings (money left in its fund, an allocation it receives). The standard cost centre is always active.')]
class SetCostCenterActive extends ApiTool
{
    public const ENDPOINT = ['PUT', '/accounting/cost-centers/{center}/periods/{period}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['center' => 'cost_center']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cost_center' => $schema->string()->description('the cost centre: id or exact name')->required(),
            'period' => $schema->string()->description('the open period: id or exact name')->required(),
            'active' => $schema->boolean()->description('true to activate, false to deactivate')->required(),
        ];
    }
}
