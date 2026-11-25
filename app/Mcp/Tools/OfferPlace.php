<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('offer_place')]
#[Description('Offers a person a place of an event out of turn, until the end of a day, with a reason that is recorded. The family gets the offer by mail and accepts or declines it in the portal. Refused when there is no free place or the person already has one. Ask the human first.')]
class OfferPlace extends ApiTool
{
    public const ENDPOINT = ['POST', '/events/{event}/offers'];

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
            'person' => $schema->string()->description('person id')->required(),
            'role' => $schema->string()->description('child, junior_counsellor, staff or guest')->required(),
            'until' => $schema->string()->description('last day of the offer YYYY-MM-DD')->required(),
            'reason' => $schema->string()->description('why the place is offered out of turn')->required(),
        ];
    }
}
