<?php

namespace App\Support\Api;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\ContactPoint;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\EventPrice;
use App\Models\EventRole;
use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Events\Bus;

/**
 * Plain arrays for the REST API and MCP. Confidential data (safety sheets, health) is never included here; it has
 * its own scope and endpoints (FB08, X03).
 */
class Presenter
{
    /** @return array<string, mixed> */
    public static function person(Person $p, bool $details = false): array
    {
        $data = [
            'id' => $p->public_id, 'first_name' => $p->first_name, 'last_name' => $p->last_name,
            'birth_date' => $p->birth_date?->toDateString(), 'age' => $p->ageAt(), 'gender' => $p->gender, 'locale' => $p->locale,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
        if ($details) {
            $data['contacts'] = $p->contactPoints->map(fn (ContactPoint $c) => ['id' => (int) $c->getKey(), 'type' => $c->type, 'value' => $c->value, 'purposes' => $c->purposes, 'primary' => (bool) $c->is_primary])->values()->all();
            $data['legal_guardians'] = $p->guardians->map(fn (Person $g) => ['id' => $g->public_id, 'name' => $g->fullName()])->values()->all();
            $data['children'] = $p->wards->map(fn (Person $c) => ['id' => $c->public_id, 'name' => $c->fullName()])->values()->all();
            $data['families'] = $p->households->map(fn (Household $h) => ['id' => $h->public_id, 'name' => $h->name, 'role' => $h->getRelationValue('pivot')?->getAttribute('role')])->values()->all();
            $data['groups'] = $p->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values()->all();
            $data['participations'] = $p->participations->map(fn (Participation $x) => self::participation($x))->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function household(Household $h): array
    {
        return [
            'id' => $h->public_id, 'name' => $h->name, 'street' => $h->getAttribute('street'), 'postal_code' => $h->getAttribute('postal_code'),
            'city' => $h->getAttribute('city'), 'country' => $h->getAttribute('country'), 'locale' => $h->getAttribute('locale'),
            'invoice_recipient' => $h->invoiceRecipient?->public_id,
            'members' => $h->members->map(fn (Person $p) => ['id' => $p->public_id, 'name' => $p->fullName(), 'role' => $p->getRelationValue('pivot')?->getAttribute('role')])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function event(Event $e, bool $details = false): array
    {
        $data = [
            'id' => $e->public_id, 'title' => $e->title, 'status' => $e->status, 'series' => $e->series?->name, 'series_id' => $e->getAttribute('event_series_id'),
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'location' => $e->getAttribute('location'),
        ];
        if ($details) {
            foreach (['meeting_point', 'return_point', 'address', 'map_url', 'contact_name', 'contact_email', 'contact_phone', 'description', 'safety_confirm_days'] as $key) {
                $data[$key] = $e->getAttribute($key);
            }
            $data += [
                'registration_binding' => $e->registration_binding, 'max_guests' => $e->max_guests, 'public_page' => $e->public_page, 'reserve_auto_release' => $e->reserve_auto_release,
                'announce_at' => $e->announce_at?->toIso8601String(), 'registration_opens_at' => $e->registration_opens_at?->toIso8601String(),
                'registration_closes_at' => $e->registration_closes_at?->toIso8601String(), 'age_reference_date' => $e->age_reference_date?->toDateString(),
                'answers_until' => $e->answers_until?->toDateString(), 'reserve_reminder_on' => $e->reserve_reminder_on?->toDateString(),
            ];
            $data += ['bus_booked' => (bool) $e->getAttribute('bus_booked'), 'bus_seats' => $e->getAttribute('bus_seats'), 'bus' => Bus::status($e)];
            $forms = Form::query()->where('kind', 'registration')->pluck('key', 'id');
            $data['roles'] = $e->roles->map(fn (EventRole $r) => [
                'role' => $r->role_key, 'capacity' => $r->capacity, 'reserve_places' => $r->reserve_places, 'occupied' => $r->occupied(),
                'bookable_left' => $r->bookableLeft(), 'waitlist' => $r->waitlistLength(), 'min_age' => $r->min_age, 'max_age' => $r->max_age,
                'price' => $r->price_cents / 100, 'role_discount' => $r->role_discount_cents / 100, 'registered_by' => $r->registered_by,
                'counts_as_sibling' => $r->counts_as_sibling, 'waitlist_order' => $r->waitlist_order ?? [],
                'registration_form' => $r->registration_form_id !== null ? $forms->get($r->registration_form_id) : null,
            ])->values()->all();
            $types = MembershipType::query()->pluck('key', 'id');
            $data['installments'] = $e->installments->map(fn (EventInstallment $i) => [
                'key' => $i->key, 'label' => $i->label, 'kind' => $i->kind, 'amount' => $i->amount_cents !== null ? $i->amount_cents / 100 : null, 'due_date' => $i->due_date?->toDateString(),
                'due_days' => $i->due_days, 'membership_type' => $i->membership_type_id !== null ? $types->get($i->membership_type_id) : null, 'refundable' => $i->refundable,
                'refund_until_days' => $i->refund_until_days, 'confirms_place' => $i->confirms_place, 'paid_add_group' => $i->paid_add_group_id, 'paid_remove_group' => $i->paid_remove_group_id,
                'discounts_apply' => $i->discounts_apply,
            ])->values()->all();
            $data['prices'] = $e->prices->map(fn (EventPrice $p) => [
                'role' => $p->role_key, 'min_age' => $p->min_age, 'max_age' => $p->max_age, 'from_date' => $p->from_date?->toDateString(), 'until_date' => $p->until_date?->toDateString(), 'price' => $p->price_cents / 100,
            ])->values()->all();
            $data += [
                'currency' => $e->currency, 'sibling_mode' => $e->sibling_mode, 'sibling_order' => $e->sibling_order, 'sibling_amount' => $e->sibling_amount_cents / 100,
                'sibling_cap_position' => $e->sibling_cap_position, 'sibling_min_children' => $e->sibling_min_children, 'reminder_days_before' => $e->getAttribute('reminder_days_before'),
                'cancellation_fee' => ((int) $e->getAttribute('cancellation_fee_cents')) / 100, 'cancellation_text' => $e->getAttribute('cancellation_text'),
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function participation(Participation $p): array
    {
        return [
            'id' => $p->id, 'event' => $p->event?->public_id, 'event_title' => $p->event?->title, 'person' => $p->person?->public_id,
            'name' => $p->person?->fullName(), 'role' => $p->role_key, 'status' => $p->status, 'waitlist_position' => $p->waitlistPosition(),
            'travel' => Bus::travel($p), 'registered_at' => $p->registered_at->toIso8601String(), 'offer_expires_at' => $p->offer_expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(Invoice $i, bool $details = false): array
    {
        $data = [
            'id' => $i->id, 'number' => $i->number, 'type' => $i->type, 'status' => $i->isOverdue() ? 'overdue' : $i->status, 'currency' => $i->currency,
            'total' => $i->total_cents / 100, 'paid' => $i->paid_cents / 100, 'open' => $i->openCents() / 100,
            'reference' => $i->reference, 'issued_on' => $i->issued_on?->toDateString(), 'due_on' => $i->due_on?->toDateString(),
            'payer' => $i->payer_name, 'event' => $i->event?->public_id, 'installment' => $i->installment_key,
        ];
        if ($details) {
            $data['lines'] = $i->lines->map(fn ($l) => ['description' => $l->description, 'amount' => $l->amount_cents / 100, 'discount' => $l->discount_cents / 100])->values()->all();
            $data['payments'] = $i->payments->map(fn ($p) => ['amount' => $p->amount_cents / 100, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function automation(Automation $a): array
    {
        return [
            'key' => $a->key, 'name' => $a->label(), 'action' => $a->action, 'trigger' => $a->trigger, 'trigger_config' => $a->trigger_config,
            'config' => $a->config, 'mode' => $a->mode, 'active' => $a->active, 'standard' => $a->is_standard, 'max_items' => $a->max_items,
            'approvals_needed' => max(1, (int) $a->approvals_needed), 'approver_roles' => $a->approverRoles(), 'approver_roles_default' => ! $a->approver_roles, 'last_run_at' => $a->last_run_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function run(AutomationRun $r): array
    {
        return [
            'id' => $r->id, 'automation' => $r->automation?->key, 'status' => $r->status, 'trigger' => $r->trigger, 'event' => $r->event?->public_id,
            'plan' => $r->plan, 'excluded' => $r->excluded ?? [], 'result' => $r->result, 'expires_at' => $r->expires_at?->toIso8601String(),
            'decided_by' => $r->decidedBy?->name, 'decided_at' => $r->decided_at?->toIso8601String(), 'comment' => $r->comment,
        ];
    }
}
