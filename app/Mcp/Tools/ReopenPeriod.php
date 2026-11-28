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

#[Name('reopen_period')]
#[Description('Reopens a closed period with a reason; every later closed period is reopened with it and their closing bookings are removed. Needs confirm true: without it the answer is refused and lists the periods that would be reopened - show them to the human and ask first.')]
#[IsDestructive]
class ReopenPeriod extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/periods/{period}/reopen'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('the closed period: id or exact name')->required(),
            'reason' => $schema->string()->description('reason (up to 500 characters)')->required(),
            'confirm' => $schema->boolean()->description('true after the human has seen the periods that will be reopened'),
        ];
    }
}
