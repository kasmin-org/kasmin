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

#[Name('update_event_template')]
#[Description('Changes an event template with the same fields as update_event; only the fields that are sent change. A template is always a draft without a public page. With roles, the roles that are not listed are removed; prices replaces all prices. Events already made from the template are not changed.')]
class UpdateEventTemplate extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/event-templates/{template}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, EventSchema::CLEARABLE), ['template' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('template id from list_event_templates')->required(),
            'title' => $schema->string()->description('title of the template'),
            'starts_at' => $schema->string()->description('start, date and time (ISO 8601)'),
            'ends_at' => $schema->string()->description('end, date and time (ISO 8601)'),
            ...EventSchema::basic($schema),
            ...EventSchema::money($schema),
            'clear' => $schema->array()->items($schema->string())->description('names of optional fields to empty, e.g. ["location", "registration_closes_at"]'),
        ];
    }
}
