<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use App\Mcp\EventSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_event_template')]
#[Description('Creates an event template: an event that is never shown or registered for, only the source of new events. With event (id of an event) and title, the template is a copy of that event with everything that is configured (roles, prices, payment plan, rounds, documents). Without event it is created from the given fields, like create_event; title, starts_at and ends_at are then required.')]
class CreateEventTemplate extends ApiTool
{
    public const ENDPOINT = ['POST', '/event-templates'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('title of the template')->required(),
            'event' => $schema->string()->description('id of the event to copy; the other fields are then not used'),
            'starts_at' => $schema->string()->description('start, date and time (ISO 8601); the dates of a template only give the distances between its dates'),
            'ends_at' => $schema->string()->description('end, date and time (ISO 8601)'),
            ...EventSchema::basic($schema),
            ...EventSchema::money($schema),
        ];
    }
}
