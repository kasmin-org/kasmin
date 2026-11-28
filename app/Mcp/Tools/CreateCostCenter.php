<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_cost_center')]
#[Description('Creates a cost centre, active in all open periods. kind carries (default; its result goes into fund, default the general fund) or allocated (no fund; needs an allocation key in every period). Needs "Use cost centres" in the global settings.')]
class CreateCostCenter extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/cost-centers'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('unique name')->required(),
            'kind' => $schema->string()->description('carries or allocated'),
            'fund' => $schema->string()->description('fund of a cost centre that carries its result: id or exact name'),
            'sort' => $schema->integer()->description('order'),
        ];
    }
}
