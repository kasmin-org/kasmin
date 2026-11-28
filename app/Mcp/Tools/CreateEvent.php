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

#[Name('create_event')]
#[Description('Creates an event with the fields of the event form: basic data, bus, roles (capacity, ages, price), sibling discount, cancellation fee, prices by age or date and the payment plan. It starts as a draft unless a status is given. With template (id of an event template) or copy_of (id of an event), title and starts_at, the event is a copy with everything that is configured, all dates moved; change it with update_event. Registration rounds and costs have their own tools; documents and the image are set in the admin interface.')]
class CreateEvent extends ApiTool
{
    public const ENDPOINT = ['POST', '/events'];

    protected string $scope = 'events:write';

    public function handle(Request $request): Response
    {
        $args = $this->args($request);
        $path = '/events';

        return $this->call('POST', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('title')->required(),
            'starts_at' => $schema->string()->description('start, date and time (ISO 8601)')->required(),
            'ends_at' => $schema->string()->description('end, date and time (ISO 8601); required unless template or copy_of is given'),
            'template' => $schema->string()->description('id of an event template to copy (list_event_templates); only title and starts_at are used with it'),
            'copy_of' => $schema->string()->description('id of an event to copy; only title and starts_at are used with it'),
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
            'roles' => $schema->array()->items($schema->object())->description('roles: objects with role (child, junior_counsellor, staff or guest), capacity, reserve_places, min_age, max_age, registered_by (guardian, self or admin), price, role_discount, counts_as_sibling, waitlist_order, registration_form (id or exact name of a form with questions)'),
            ...EventSchema::money($schema),
        ];
    }
}
