<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_period')]
#[Description('Creates a period. The first one starts the accounting; every further one follows the last without a gap (starts_on may be left out) and takes over its active cost centres and allocation keys.')]
class CreatePeriod extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/periods'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('name, e.g. 2027')->required(),
            'ends_on' => $schema->string()->description('last day (YYYY-MM-DD)')->required(),
            'starts_on' => $schema->string()->description('first day (YYYY-MM-DD); default the day after the last period'),
        ];
    }
}
