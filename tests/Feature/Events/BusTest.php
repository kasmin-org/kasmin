<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\ParticipationsRelationManager;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Bus;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use App\Support\Reports\DashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Travel by bus (ST06): seats, overbooking, adults by car; children always go by bus.
 */
class BusTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-05-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open', 'bus_booked' => true, 'bus_seats' => 3]);
    }

    private function join(string $name, string $born, string $role = 'child', string $status = 'registered'): Participation
    {
        $person = Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => $born]);

        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $person->id, 'role_key' => $role, 'status' => $status, 'registered_at' => now()])->refresh();
    }

    public function test_the_bus_fills_up_and_adults_move_to_cars(): void
    {
        $child = $this->join('Child', '2016-01-01');
        $this->join('Second', '2015-01-01');
        $this->join('Waiting', '2015-01-01', status: 'waitlist');
        $adult = $this->join('Adult', '1990-01-01', 'staff');
        $other = $this->join('Other', '1985-01-01', 'staff');

        $status = Bus::status($this->event);
        $this->assertEquals(['riders' => 4, 'over' => 1, 'adults_on_bus' => 2, 'cars' => 0], array_intersect_key($status, array_flip(['riders', 'over', 'adults_on_bus', 'cars'])));
        $this->assertStringContainsString('Bus overbooked: 4 people for 3 seats – 1 too many', (string) Bus::summary($this->event));
        $this->assertStringContainsString('overbooked', (string) app(DashboardData::class)->eventBlock($this->event, null, false)['bus_summary']);

        $this->assertTrue(Bus::set($adult, 'car'));
        $status = Bus::status($this->event);
        $this->assertSame([3, 0, 0, 1], [$status['riders'], $status['over'], $status['free'], $status['cars']]);

        // The legal guardians bring a child themselves: the seat is free, and the list says who brings it.
        $guardian = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ash']);
        $guardian->contactPoints()->create(['type' => 'phone', 'value' => '+43 1 234', 'is_primary' => true]);
        $child->person?->guardians()->attach($guardian->id);
        $this->assertTrue(Bus::set($child, 'car'));
        $status = Bus::status($this->event);
        $this->assertSame([2, 1, 2, 1], [$status['riders'], $status['free'], $status['cars'], $status['children_by_car']]);
        $brought = collect(app(EventLists::class)->build($this->event, 'cars')->rows)->first(fn (array $row) => $row[1] === 'Child Ash') ?? [];
        $this->assertSame(['Kim Ash', '+43 1 234'], array_slice($brought, 3, 2));
        Bus::set($child, 'bus');
        $this->assertStringContainsString('3 of 3 seats taken', (string) Bus::summary($this->event));

        $lists = app(EventLists::class);
        $this->assertCount(3, $lists->build($this->event, 'bus')->rows);
        $this->assertSame('Adult Ash', $lists->build($this->event, 'cars')->rows[0][1]);

        $this->event->update(['bus_booked' => false]);
        $this->assertNull(Bus::summary($this->event->refresh()));
        $this->assertTrue(Bus::set($other, 'car'));
    }

    public function test_the_team_sets_it_in_the_interface(): void
    {
        $admin = Person::factory()->create();
        $admin->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $admin->id]));
        $child = $this->join('Child', '2016-01-01');
        $adult = $this->join('Adult', '1990-01-01', 'staff');

        $table = Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);
        $table->assertTableActionVisible('travel', $adult)->assertTableActionVisible('travel', $child)->callTableAction('travel', $adult);
        $this->assertSame('car', Bus::travel($adult->refresh()));
        $table->callTableBulkAction('travel_bus', [$adult, $child]);
        $this->assertSame('bus', Bus::travel($adult->refresh()));
        $table->callTableBulkAction('travel_car', [$adult, $child]);
        $this->assertSame(['car', 'car'], [Bus::travel($adult->refresh()), Bus::travel($child->refresh())]);

        Livewire::test(EditEvent::class, ['record' => $this->event->getRouteKey()])->fillForm(['bus_booked' => true, 'bus_seats' => null])->call('save')->assertHasFormErrors(['bus_seats']);
    }
}
