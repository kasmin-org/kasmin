<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_period')]
#[Description('Changes name and dates of an open period. Bookings belong to a period by their date, so they move along, and the neighbouring period moves with it. Use dry_run true first: the answer says in moved how many bookings would belong to another period; show that to the human.')]
class UpdatePeriod extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/accounting/periods/{period}'];

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
            'name' => $schema->string()->description('name'),
            'starts_on' => $schema->string()->description('first day (YYYY-MM-DD)'),
            'ends_on' => $schema->string()->description('last day (YYYY-MM-DD)'),
            'dry_run' => $schema->boolean()->description('true: only count the bookings that would move, save nothing'),
        ];
    }
}
