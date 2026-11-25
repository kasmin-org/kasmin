<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\EventDivisions;
use App\Models\Event;
use App\Models\EventDivision;
use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Divisions;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Groups, rooms and duties within an event (EC04): the proposal and changes by hand.
 */
class DivisionsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-05-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
    }

    private function join(string $name, int $age, string $gender, string $role = 'child', ?Household $family = null, string $status = 'registered'): Participation
    {
        $person = Person::factory()->create(['first_name' => $name, 'last_name' => $family->name ?? 'Solo', 'birth_date' => Carbon::parse('2027-08-01')->subYears($age)->subMonth(), 'gender' => $gender]);
        $family?->members()->attach($person->id, ['role' => 'child']);

        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $person->id, 'role_key' => $role, 'status' => $status, 'registered_at' => now()]);
    }

    /** @return array<string, list<string>> unit name => first names */
    private function units(EventDivision $division): array
    {
        $result = [];
        foreach ($division->units()->get() as $unit) {
            $result[$unit->name] = array_values($unit->participations()->with('person')->get()->map(fn (Participation $p) => (string) $p->person?->first_name)->sort()->all());
        }

        return $result;
    }

    public function test_groups_are_equal_mixed_and_keep_siblings_apart(): void
    {
        $ash = Household::query()->create(['name' => 'Ash']);
        $this->join('Ann', 9, 'female', family: $ash);
        $this->join('Ben', 11, 'male', family: $ash);
        foreach ([['Cy', 9, 'male'], ['Dee', 9, 'female'], ['Eli', 11, 'male'], ['Fay', 11, 'female'], ['Gus', 13, 'male'], ['Hal', 13, 'female']] as [$name, $age, $gender]) {
            $this->join($name, $age, $gender);
        }
        $this->join('Jo', 15, 'female', 'junior_counsellor');
        $this->join('Kai', 16, 'male', 'junior_counsellor');
        $this->join('Waiting', 10, 'male', status: 'waitlist');

        $service = app(Divisions::class);
        $division = $service->create($this->event, 'Groups', 'groups', 2);
        $this->assertSame(10, $service->propose($division));
        $units = $this->units($division);
        $this->assertSame([5, 5], array_map('count', array_values($units)));
        foreach ($units as $names) {
            $this->assertCount(1, array_intersect($names, ['Jo', 'Kai']), 'one junior counsellor in each group');
            $this->assertCount(1, array_intersect($names, ['Ann', 'Ben']), 'siblings apart');
            $this->assertContains(count(array_intersect($names, ['Ann', 'Dee', 'Fay', 'Hal', 'Jo'])), [2, 3], 'five female and five male people: 3+2 in each group');
            $this->assertCount(1, array_intersect($names, ['Gus', 'Hal']), 'the oldest spread');
        }
        $this->assertSame([], $service->warnings($division));
        $this->assertSame(0, $service->propose($division), 'nobody is moved by a second proposal');

        $late = $this->join('Late', 10, 'male');
        $this->assertStringContainsString('One person has no place yet', implode(' ', $service->warnings($division)));
        $this->assertSame(1, $service->propose($division));
        $this->assertCount(11, app(EventLists::class)->build($this->event, 'groups')->rows);

        $late->update(['status' => 'cancelled']);
        $this->assertCount(10, app(EventLists::class)->build($this->event, 'groups')->rows, 'cancelled people drop out of the lists');
    }

    public function test_rooms_separate_the_genders_respect_the_beds_and_keep_siblings_together(): void
    {
        $birch = Household::query()->create(['name' => 'Birch']);
        $this->join('Ada', 9, 'female', family: $birch);
        $this->join('Bea', 12, 'female', family: $birch);
        foreach ([['Cleo', 10, 'female'], ['Dan', 9, 'male'], ['Ed', 10, 'male'], ['Finn', 12, 'male'], ['Gil', 12, 'male']] as [$name, $age, $gender]) {
            $this->join($name, $age, $gender);
        }
        $service = app(Divisions::class);
        $division = $service->create($this->event, 'Rooms', 'rooms', 2, 3);
        $this->assertSame(6, $service->propose($division), 'four boys do not fit into the one room that is left');
        $units = $this->units($division);
        foreach ($units as $names) {
            $this->assertLessThanOrEqual(3, count($names));
            $this->assertTrue(array_diff($names, ['Ada', 'Bea', 'Cleo']) === [] || array_intersect($names, ['Ada', 'Bea', 'Cleo']) === [], 'no mixed room');
        }
        $together = collect($units)->first(fn (array $names) => in_array('Ada', $names, true)) ?? [];
        $this->assertContains('Bea', $together);
        $this->assertStringContainsString('has no place yet', implode(' ', $service->warnings($division)));

        // By hand into a room of the other gender: possible, but the page warns.
        $open = $service->people($division)->firstOrFail(fn (Participation $p) => ! isset($service->placed($division)[$p->id]));
        $girls = $division->units()->get()->firstOrFail(fn ($u) => $u->participations()->whereHas('person', fn ($q) => $q->where('gender', 'female'))->exists());
        DB::table('event_unit_members')->where('event_unit_id', $girls->id)->limit(1)->delete();
        $service->move($division, $open, $girls);
        $this->assertStringContainsString('genders are mixed', implode(' ', $service->warnings($division)));
    }

    public function test_the_page_creates_proposes_and_moves_and_only_editors_may_change(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $make = function (string $role): User {
            auth()->forgetUser();
            $person = Person::factory()->create();
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

            return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        };
        $admin = $make('administrator');
        $safety = $make('safety_officer');
        $a = $this->join('Ann', 9, 'female');
        $this->join('Ben', 10, 'male');

        $this->actingAs($admin);
        $page = Livewire::test(EventDivisions::class, ['record' => $this->event->getRouteKey()])
            ->callAction('create', ['kind' => 'groups', 'name' => 'Teams', 'units' => 2, 'roles' => ['child'], 'balance_age' => true, 'balance_gender' => true, 'separate_gender' => false, 'siblings' => 'apart'])
            ->assertHasNoActionErrors();
        $division = EventDivision::query()->where('event_id', $this->event->id)->firstOrFail();
        $this->assertSame(['Group 1', 'Group 2'], $division->units()->pluck('name')->all());
        $page->call('propose', $division->id)->assertSee('Ann')->assertSee('Group 1');
        $this->assertCount(2, app(Divisions::class)->placed($division));

        $second = $division->units()->where('sort', 2)->firstOrFail();
        $page->call('move', $division->id, $a->id, '')->assertOk();
        $this->assertArrayNotHasKey($a->id, app(Divisions::class)->placed($division));
        $page->call('move', $division->id, $a->id, (string) $second->id)->call('unit', $second->id, 'note', 'led by Kim')->call('unit', $second->id, 'capacity', '6');
        $this->assertSame($second->id, app(Divisions::class)->placed($division)[$a->id]);
        $this->assertSame(['led by Kim', 6], [$second->refresh()->note, $second->capacity]);
        $this->get(route('admin.lists.export', ['event' => $this->event->id, 'key' => 'groups', 'format' => 'csv']))->assertOk()->assertSee('led by Kim');

        $this->actingAs($safety);
        Livewire::test(EventDivisions::class, ['record' => $this->event->getRouteKey()])->assertSee('Teams')->assertActionHidden('create')->call('propose', $division->id, true)->assertForbidden();
    }
}
