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

#[Name('update_event')]
#[Description('Changes an event with the fields of the event form (basic data, bus, roles, discounts, prices, payment plan); only the fields that are sent change. With roles, the listed roles are created or changed and roles that are not listed are removed (a role with registrations cannot be removed) – send all roles of get_event that should stay. prices replaces all prices by age or date; installments works like roles: an instalment is found by its id (or by its exact label), the others are removed. Changed prices and instalments change draft invoices and future invoices. Changing status, dates or the registration window has effect on the registration of families: ask the human first.')]
class UpdateEvent extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/events/{event}'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/events/'.rawurlencode((string) ($args['id'] ?? ''));
        unset($args['id'], $args['clear']);
        // Empty values are left out of the arguments, so fields are emptied by name.
        foreach ((array) ($request->get('clear') ?? []) as $field) {
            if (is_string($field) && ! array_key_exists($field, $args) && ! in_array($field, ['id', 'clear', 'roles'], true)) {
                $args[$field] = null;
            }
        }

        return $this->call('PATCH', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('event id')->required(),
            'title' => $schema->string()->description('title'),
            'starts_at' => $schema->string()->description('start, date and time (ISO 8601)'),
            'ends_at' => $schema->string()->description('end, date and time (ISO 8601)'),
            'status' => $schema->string()->description('draft, announced, open, closed, running, finished or archived'),
            'series_id' => $schema->integer()->description('id of the event series'),
            'location' => $schema->string()->description('place'),
            'address' => $schema->string()->description('address of the place'),
            'map_url' => $schema->string()->description('link to a map (https)'),
            'meeting_point' => $schema->string()->description('meeting point for the departure'),
            'return_point' => $schema->string()->description('place of the return'),
            'contact_name' => $schema->string()->description('contact person'),
            'contact_email' => $schema->string()->description('e-mail address of the contact'),
            'contact_phone' => $schema->string()->description('phone number of the contact'),
            'description' => $schema->string()->description('description'),
            'announce_at' => $schema->string()->description('date and time from which the event is announced'),
            'registration_opens_at' => $schema->string()->description('date and time the registration opens'),
            'registration_closes_at' => $schema->string()->description('date and time the registration closes'),
            'registration_binding' => $schema->boolean()->description('registrations are binding'),
            'public_page' => $schema->boolean()->description('the event has a public page'),
            'max_guests' => $schema->integer()->description('guests per registration, 0 to 10'),
            'age_reference_date' => $schema->string()->description('date the age limits refer to'),
            'answers_until' => $schema->string()->description('date until which forms have to be answered'),
            'safety_confirm_days' => $schema->integer()->description('days before the start in which the safety sheet has to be confirmed'),
            'reserve_auto_release' => $schema->boolean()->description('reserved places are released automatically'),
            'reserve_reminder_on' => $schema->string()->description('date of the reminder about reserved places'),
            'roles' => $schema->array()->items($schema->object())->description('all roles of the event: objects with role (child, junior_counsellor, staff or guest), capacity, reserve_places, min_age, max_age, registered_by, price, role_discount, counts_as_sibling, waitlist_order; inside a role, null empties a field'),
            ...EventSchema::money($schema),
            'clear' => $schema->array()->items($schema->string())->description('names of optional fields to empty, e.g. ["location", "registration_closes_at"]'),
        ];
    }
}
