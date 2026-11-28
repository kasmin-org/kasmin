<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('close_period')]
#[Description('Closes a period as close_period_preview shows: books the allocations, carries every result into its fund, sets income and expenses back to 0 and locks the period. Periods are closed in the order of their dates. Show the preview to the human and act only after their approval.')]
#[IsDestructive]
class ClosePeriod extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/periods/{period}/close'];

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
        ];
    }
}
