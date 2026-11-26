<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('assign_reserve_place')]
#[Description('Gives a free reserve place of the event to somebody on the waitlist, out of turn; the reason is required and recorded. The person gets the place and the family is told as for any place. Ask the human first.')]
class AssignReservePlace extends ApiTool
{
    public const ENDPOINT = ['POST', '/participations/{participation}/reserve-place'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['participation' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('participation id (status waitlist) from list_participants')->required(),
            'reason' => $schema->string()->description('why this person gets the place')->required(),
        ];
    }
}
