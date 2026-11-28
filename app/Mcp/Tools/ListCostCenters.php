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

#[Name('list_cost_centers')]
#[Description('Lists the cost centres with id, name, kind (carries: its result goes into its fund; allocated: spread over others at the closing), fund, the periods it is active in, the number of its bookings and why it cannot be deleted.')]
#[IsReadOnly]
class ListCostCenters extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/cost-centers'];

    protected string $scope = 'finance:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
