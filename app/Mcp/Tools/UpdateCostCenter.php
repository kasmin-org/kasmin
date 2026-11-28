<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_cost_center')]
#[Description('Changes name, fund (only of a cost centre that carries its result; cleared = general fund) or order of a cost centre. The kind is changed with change_cost_center_kind.')]
class UpdateCostCenter extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/accounting/cost-centers/{center}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['fund']), ['center' => 'cost_center']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cost_center' => $schema->string()->description('the cost centre: id or exact name')->required(),
            'name' => $schema->string()->description('name'),
            'fund' => $schema->string()->description('fund: id or exact name'),
            'sort' => $schema->integer()->description('order'),
            'clear' => $schema->array()->items($schema->string())->description('optional fields to empty: fund'),
        ];
    }
}
