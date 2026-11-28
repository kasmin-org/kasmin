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

#[Name('close_period_preview')]
#[Description('What closing a period would do, without saving: per cost centre the result, the allocation and the result after it; the allocations; per fund the value before, the result and after; warnings and errors that block the closing (can_close). Show it to the human before close_period.')]
#[IsReadOnly]
class ClosePeriodPreview extends ApiTool
{
    public const ENDPOINT = ['GET', '/accounting/periods/{period}/closing'];

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
