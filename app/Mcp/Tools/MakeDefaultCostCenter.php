<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('make_default_cost_center')]
#[Description('Makes a cost centre the standard one: bookings on income and expenses without a cost centre (and without a standard one of the account) go to it.')]
class MakeDefaultCostCenter extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/cost-centers/{center}/default'];

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
        ];
    }
}
