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

#[Name('save_registration_rounds')]
#[Description('Replaces all registration rounds of an event, in the order sent: a round with id is changed, one without is new, and rounds that are not listed are deleted - send all rounds of list_registration_rounds that should stay. The rounds decide who can register when: ask the human first.')]
#[IsDestructive]
class SaveRegistrationRounds extends ApiTool
{
    public const ENDPOINT = ['PUT', '/events/{event}/rounds'];

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
            'rounds' => $schema->array()->items($schema->object())->description('all rounds: objects with id (only for an existing round), role (child, junior_counsellor, staff or guest), kind (returning, selection, waitlist or open), opens_at, closes_at, and for returning and selection also mode (accept or payment) and group (group id); min_events only for selection')->required(),
        ];
    }
}
