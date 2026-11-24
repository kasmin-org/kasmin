<?php

namespace App\Support\Install;

use App\Models\EventTemplate;

/**
 * Event types installed with the defaults (ET02).
 */
class EventTemplateDefaults
{
    public function install(string $locale = 'de'): void
    {
        if (EventTemplate::query()->exists()) {
            return;
        }
        $de = $locale !== 'en';
        $make = fn (string $title, array $more = []) => EventTemplate::query()->create($more + [
            'title' => $title, 'status' => 'draft', 'is_template' => true, 'starts_at' => '2027-07-03 09:00', 'ends_at' => '2027-07-10 16:00',
        ]);
        $camp = $make($de ? 'Lager (Kinder, mit Übernachtung)' : 'Camp (children, overnight)', ['sibling_mode' => 'staggered', 'sibling_amount_cents' => 0, 'sibling_cap_position' => 3]);
        $camp->roles()->createMany([
            ['role_key' => 'child', 'capacity' => 30, 'min_age' => 8, 'max_age' => 13],
            ['role_key' => 'junior_counsellor', 'capacity' => 5, 'min_age' => 14, 'max_age' => 15],
            ['role_key' => 'staff', 'capacity' => null, 'min_age' => 16, 'registered_by' => 'admin', 'counts_as_sibling' => false],
        ]);
        $camp->installments()->createMany([
            ['key' => 'deposit', 'label' => ['de' => 'Anzahlung', 'en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 0, 'due_days' => 14, 'discounts_apply' => false, 'refundable' => false, 'sort' => 1],
            ['key' => 'balance', 'label' => ['de' => 'Restbetrag', 'en' => 'Balance'], 'kind' => 'rest', 'due_date' => '2027-05-01', 'discounts_apply' => true, 'refundable' => true, 'refund_until_days' => 30, 'sort' => 2],
        ]);
        $alumni = $make($de ? 'Ehemaligen-/Team-Veranstaltung' : 'Alumni / team event', ['ends_at' => '2027-07-05 14:00', 'max_guests' => 1]);
        $alumni->roles()->createMany([
            ['role_key' => 'staff', 'capacity' => null, 'min_age' => 15, 'registered_by' => 'self', 'counts_as_sibling' => false],
            ['role_key' => 'guest', 'capacity' => null, 'min_age' => 15, 'registered_by' => 'self', 'counts_as_sibling' => false],
        ]);
        $alumni->installments()->create(['key' => 'price', 'label' => ['de' => 'Teilnahmebeitrag', 'en' => 'Price'], 'kind' => 'rest', 'due_days' => 14, 'sort' => 1]);
        $make($de ? 'Treffen / Infoabend (gratis)' : 'Meeting / information evening (free)', ['starts_at' => '2027-07-03 18:00', 'ends_at' => '2027-07-03 20:00'])
            ->roles()->create(['role_key' => 'guest', 'capacity' => null, 'registered_by' => 'self', 'counts_as_sibling' => false]);
    }
}
