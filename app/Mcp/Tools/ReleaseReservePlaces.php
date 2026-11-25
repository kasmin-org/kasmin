<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('release_reserve_places')]
#[Description('Gives free reserve places of a role to the waitlist: the next people on the waitlist get an offer by mail. Answers with the number of places released and the reserve that is left. Ask the human first.')]
class ReleaseReservePlaces extends ApiTool
{
    public const ENDPOINT = ['POST', '/events/{event}/reserve-places/release'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['event' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'role' => $schema->string()->description('child, junior_counsellor, staff or guest')->required(),
            'count' => $schema->integer()->description('number of places')->required(),
            'reason' => $schema->string()->description('reason, recorded')->required(),
        ];
    }
}
