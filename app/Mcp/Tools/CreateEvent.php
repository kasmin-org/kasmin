<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_event')]
#[Description('Creates an event with its basic data and roles (capacity, ages, price). It starts as a draft unless a status is given. Prices by age or date, instalments, discounts, registration rounds, costs, documents and the image are set in the admin interface.')]
class CreateEvent extends ApiTool
{
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
            'ends_at' => $schema->string()->description('end, date and time (ISO 8601)')->required(),
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
            'roles' => $schema->array()->items($schema->object())->description('roles: objects with role (child, junior_counsellor, staff or guest), capacity, reserve_places, min_age, max_age, registered_by (guardian, self or admin), price, role_discount, counts_as_sibling, waitlist_order'),
        ];
    }
}
