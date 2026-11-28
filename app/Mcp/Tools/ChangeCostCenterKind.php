<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('change_cost_center_kind')]
#[Description('Changes the kind of a cost centre: carries (its result goes into a fund; its allocation keys in open periods are removed) or allocated (no fund; not while it receives an allocation in an open period, and one cost centre must still carry its result).')]
class ChangeCostCenterKind extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/cost-centers/{center}/kind'];

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
            'kind' => $schema->string()->description('carries or allocated')->required(),
            'fund' => $schema->string()->description('with carries: the fund, id or exact name (default the general fund)'),
        ];
    }
}
