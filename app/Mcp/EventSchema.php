<?php

namespace App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The fields of the event form that the tools for events and event templates share.
 */
class EventSchema
{
    /** Optional fields that can be emptied by name ("clear"). */
    public const CLEARABLE = [
        'series_id', 'location', 'address', 'map_url', 'meeting_point', 'return_point', 'contact_name', 'contact_email', 'contact_phone', 'description',
        'announce_at', 'registration_opens_at', 'registration_closes_at', 'age_reference_date', 'answers_until', 'safety_confirm_days', 'reserve_reminder_on',
        'bus_seats', 'sibling_cap_position', 'reminder_days_before', 'cancellation_text',
    ];

    /**
     * Basic data without title and dates.
     *
     * @return array<string, Type>
     */
    public static function basic(JsonSchema $schema): array
    {
        return [
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
            'max_guests' => $schema->integer()->description('guests per registration, 0 to 10'),
            'age_reference_date' => $schema->string()->description('date the age limits refer to'),
            'answers_until' => $schema->string()->description('date until which forms have to be answered'),
            'safety_confirm_days' => $schema->integer()->description('days before the start in which the safety sheet has to be confirmed'),
            'reserve_auto_release' => $schema->boolean()->description('reserved places are released automatically'),
            'reserve_reminder_on' => $schema->string()->description('date of the reminder about reserved places'),
            'roles' => $schema->array()->items($schema->object())->description('all roles: objects with role (child, junior_counsellor, staff or guest), capacity, reserve_places, min_age, max_age, registered_by (guardian, self or admin), price, role_discount, counts_as_sibling, waitlist_order, registration_form (id or exact name of a form with questions)'),
        ];
    }

    /**
     * Bus, discounts, cancellation, prices by age or date and the payment plan.
     *
     * @return array<string, Type>
     */
    public static function money(JsonSchema $schema): array
    {
        return [
            'bus_booked' => $schema->boolean()->description('a bus is booked for the event'),
            'bus_seats' => $schema->integer()->description('seats of the bus; required with a booked bus'),
            'sibling_mode' => $schema->string()->description('sibling discount: none, staggered or all'),
            'sibling_order' => $schema->string()->description('who counts as the first child: oldest, highest_price or registration'),
            'sibling_amount' => $schema->number()->description('amount of the sibling discount'),
            'sibling_cap_position' => $schema->integer()->description('from this child on the discount does not grow any more'),
            'sibling_min_children' => $schema->integer()->description('number of children from which the discount applies'),
            'reminder_days_before' => $schema->array()->items($schema->integer())->description('days before the start on which reminders are sent'),
            'cancellation_fee' => $schema->number()->description('fee for a cancellation'),
            'cancellation_text' => $schema->object()->description('cancellation terms: {"de": "…", "en": "…"}'),
            'prices' => $schema->array()->items($schema->object())->description('prices by age or date; the list replaces all stored ones: objects with role, price and optionally min_age, max_age, from_date, until_date'),
            'installments' => $schema->array()->items($schema->object())->description('the payment plan: listed instalments are created or changed, the others are removed (not once they have invoices). An instalment is found by its id from get_event (without id: by its exact label; no match = new). Objects with id, label (name, unique in the plan), kind (fixed, rest or member), amount, due_date or due_days, membership_type (id or exact name), refundable, refund_until_days, confirms_place, discounts_apply, paid_add_group, paid_remove_group'),
        ];
    }
}
