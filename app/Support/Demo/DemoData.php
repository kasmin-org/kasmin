<?php

namespace App\Support\Demo;

use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MembershipType;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Finance\Invoices;
use App\Support\Settings;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Random, fictitious demo data in German or English (DM01). Reproducible with a seed. Never real data.
 */
class DemoData
{
    private Generator $faker;

    /**
     * @return array{households: int, people: int}
     */
    public function generate(string $locale = 'de', int $families = 30, int $staff = 15, int $seed = 2026, string $emailDomain = 'example.org'): array
    {
        $this->faker = Faker::create($locale === 'en' ? 'en_GB' : 'de_AT');
        $this->faker->seed($seed);
        $types = MembershipType::query()->pluck('id', 'key');
        $people = 0;

        for ($i = 0; $i < $families; $i++) {
            $lastName = $this->faker->lastName();
            $household = Household::query()->create([
                'name' => ($locale === 'en' ? 'Family ' : 'Familie ').$lastName,
                'street' => $this->faker->streetAddress(),
                'postal_code' => $this->faker->postcode(),
                'city' => $this->faker->city(),
                'country' => $locale === 'en' ? 'GB' : 'AT',
                'locale' => $locale,
            ]);
            $guardians = [];
            foreach (range(1, $this->faker->numberBetween(1, 2)) as $g) {
                $guardian = $this->person($this->faker->firstName(), $g === 1 ? $lastName : $this->faker->randomElement([$lastName, $this->faker->lastName()]), $this->faker->dateTimeBetween('-55 years', '-30 years'), $locale);
                $email = Str::lower(Str::ascii($guardian->first_name.'.'.$guardian->last_name)).$i.'@'.$emailDomain;
                $guardian->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login', 'information', 'invoices'], 'is_primary' => true]);
                $guardian->contactPoints()->create(['type' => 'phone', 'value' => $this->faker->phoneNumber(), 'purposes' => ['emergency']]);
                User::query()->create(['name' => $guardian->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $guardian->id]);
                $household->members()->attach($guardian->id, ['role' => 'guardian']);
                $guardians[] = $guardian;
                $people++;
            }
            $household->update(['invoice_recipient_id' => $guardians[0]->id]);
            foreach (range(1, $this->faker->numberBetween(1, 3)) as $c) {
                $child = $this->person($this->faker->firstName(), $lastName, $this->faker->dateTimeBetween('-15 years', '-7 years'), $locale);
                $household->members()->attach($child->id, ['role' => 'child']);
                foreach ($guardians as $guardian) {
                    $child->guardians()->attach($guardian->id);
                }
                if (isset($types['child'])) {
                    $child->memberships()->create(['membership_type_id' => $types['child'], 'starts_on' => now()->subYears($this->faker->numberBetween(0, 4))->startOfYear()]);
                }
                $people++;
            }
        }

        $counsellor = Role::query()->where('key', 'counsellor')->value('id');
        for ($i = 0; $i < $staff; $i++) {
            $person = $this->person($this->faker->firstName(), $this->faker->lastName(), $this->faker->dateTimeBetween('-35 years', '-16 years'), $locale);
            $email = Str::lower(Str::ascii($person->first_name.'.'.$person->last_name)).'.team'.$i.'@'.$emailDomain;
            $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login', 'information'], 'is_primary' => true]);
            User::query()->create(['name' => $person->fullName(), 'email' => $email, 'password' => Str::password(64), 'person_id' => $person->id]);
            if (isset($types['regular'])) {
                $person->memberships()->create(['membership_type_id' => $types['regular'], 'starts_on' => now()->subYears($this->faker->numberBetween(0, 6))->startOfYear()]);
            }
            if ($counsellor !== null) {
                $person->roleAssignments()->create(['role_id' => $counsellor]);
            }
            $people++;
        }

        $this->events($locale);

        return ['households' => $families, 'people' => $people];
    }

    /** A camp series and an alumni series with an open next event; children are registered until the waitlist starts. */
    private function events(string $locale): void
    {
        $de = $locale !== 'en';
        $year = (int) now()->addYear()->format('Y');
        $camp = EventSeries::query()->create(['name' => $de ? 'Sommerlager' : 'Summer camp', 'type' => 'camp']);
        $alumni = EventSeries::query()->create(['name' => $de ? 'Alumni-Wochenende' : 'Alumni weekend', 'type' => 'alumni']);

        $next = Event::query()->create([
            'event_series_id' => $camp->id, 'title' => ($de ? 'Sommerlager ' : 'Summer camp ').$year,
            'starts_at' => now()->addYear()->setDate($year, 8, 15)->setTime(9, 0), 'ends_at' => now()->addYear()->setDate($year, 8, 22)->setTime(16, 0),
            'location' => $this->faker->city(), 'meeting_point' => $this->faker->streetAddress(), 'status' => 'open',
            'sibling_mode' => 'staggered', 'sibling_amount_cents' => 2500, 'sibling_cap_position' => 3,
        ]);
        $next->roles()->createMany([
            ['role_key' => 'child', 'capacity' => 20, 'reserve_places' => 1, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 42000],
            ['role_key' => 'junior_counsellor', 'capacity' => 4, 'min_age' => 14, 'max_age' => 15, 'price_cents' => 42000, 'role_discount_cents' => 15000],
            ['role_key' => 'staff', 'capacity' => null, 'min_age' => 16, 'registered_by' => 'admin', 'counts_as_sibling' => false],
        ]);
        $next->installments()->createMany([
            ['key' => 'deposit', 'label' => ['de' => 'Anzahlung', 'en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'discounts_apply' => false, 'sort' => 1],
            ['key' => 'balance', 'label' => ['de' => 'Restbetrag', 'en' => 'Balance'], 'kind' => 'rest', 'due_date' => now()->addYear()->setDate($year, 6, 1)->toDateString(), 'discounts_apply' => true, 'sort' => 2],
        ]);
        Event::query()->create([
            'event_series_id' => $alumni->id, 'title' => ($de ? 'Alumni-Wochenende ' : 'Alumni weekend ').$year,
            'starts_at' => now()->addYear()->setDate($year, 7, 4)->setTime(15, 0), 'ends_at' => now()->addYear()->setDate($year, 7, 6)->setTime(14, 0),
            'location' => $this->faker->city(), 'status' => 'announced',
        ])->roles()->create(['role_key' => 'guest', 'capacity' => 40, 'min_age' => 15, 'registered_by' => 'self']);

        $registrations = app(Registrations::class);
        $children = Person::query()->whereHas('guardians')->get()->filter(fn (Person $p) => ($age = $p->ageAt($next->starts_at)) !== null && $age >= 8 && $age <= 13);
        foreach ($children as $child) {
            $registrations->register($next, $child, 'child', $child->guardians()->first());
        }

        $this->invoices($next, $locale);
    }

    /** Organisation data with an example IBAN, deposit invoices for the camp, about two thirds paid (0.6). */
    private function invoices(Event $event, string $locale): void
    {
        $de = $locale !== 'en';
        Settings::set('organisation_name', $de ? 'Demo-Feriencamp' : 'Demo Holiday Camp');
        Settings::set('organisation_address', $de ? "Beispielgasse 1\n1010 Wien" : "1 Example Street\nLondon W1A 1AA");
        Settings::set('organisation_iban', 'AT611904300234573201');
        Settings::set('organisation_legal_footer', $de ? 'Demo-Daten – kein echter Verein. Beispiel-IBAN, bitte nicht überweisen.' : 'Demo data – not a real organisation. Example IBAN, please do not transfer money.');
        $service = app(Invoices::class);
        $event->load('roles', 'installments');
        $deposit = $event->installments->firstWhere('key', 'deposit');
        if ($deposit === null) {
            return;
        }
        $service->draftRun($event, $deposit);
        foreach (Invoice::query()->where('status', 'draft')->get() as $invoice) {
            $issued = $service->issue($invoice);
            if ($this->faker->boolean(65)) {
                // Booked directly, so the demo set-up does not queue payment confirmation mails.
                $issued->payments()->create(['amount_cents' => $issued->total_cents, 'paid_on' => now()->subDays($this->faker->numberBetween(0, 10))->toDateString(),
                    'method' => 'bank', 'reference' => $issued->reference, 'payer_name' => $issued->payer_name, 'match' => 'auto']);
                $issued->forceFill(['paid_cents' => $issued->total_cents, 'status' => 'paid'])->save();
            }
        }
    }

    private function person(string $first, string $last, \DateTimeInterface $birth, string $locale): Person
    {
        return Person::query()->create(['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth->format('Y-m-d'), 'locale' => $locale]);
    }
}
