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

#[Name('get_allocation_keys')]
#[Description('The allocation keys of a period: for every cost centre that is allocated, who bears how much of its costs at the closing (kind fixed with amount, percent with percent, rest, share of income).')]
#[IsReadOnly]
class GetAllocationKeys extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/periods/{period}/allocation-keys'];

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
