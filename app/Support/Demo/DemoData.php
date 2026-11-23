<?php

namespace App\Support\Demo;

use App\Models\Household;
use App\Models\MembershipType;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
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
    public function generate(string $locale = 'de', int $families = 30, int $staff = 15, int $seed = 2026): array
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
                $email = Str::lower(Str::ascii($guardian->first_name.'.'.$guardian->last_name)).$i.'@example.org';
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
            $email = Str::lower(Str::ascii($person->first_name.'.'.$person->last_name)).'.team'.$i.'@example.org';
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

        return ['households' => $families, 'people' => $people];
    }

    private function person(string $first, string $last, \DateTimeInterface $birth, string $locale): Person
    {
        return Person::query()->create(['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth->format('Y-m-d'), 'locale' => $locale]);
    }
}
