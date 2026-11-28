<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_allocation_key')]
#[Description('Replaces the allocation key of one allocated cost centre in an open period. First the fixed amounts, then the percentages of what is left; what is left then goes to the one rest cost centre or in proportion to the income (share). Targets must carry their result. An empty list removes the key.')]
class SaveAllocationKey extends ApiTool
{
    public const ENDPOINT = ['PUT', '/accounting/periods/{period}/allocation-keys/{center}'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['center' => 'cost_center']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('the open period: id or exact name')->required(),
            'cost_center' => $schema->string()->description('the allocated cost centre: id or exact name')->required(),
            'rules' => $schema->array()->items($schema->object())->description('all rules of the key: objects with target (cost centre that carries its result: id or exact name), kind (fixed, percent, rest or share), amount (for fixed) or percent (for percent, 0-100)')->required(),
        ];
    }
}
