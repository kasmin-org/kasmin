<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Person;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attributes */
    private function event(int $capacity = 2, int $reserve = 0, array $attributes = []): Event
    {
        $event = Event::query()->create($attributes + [
            'title' => 'Summer camp', 'starts_at' => now()->addMonths(6), 'ends_at' => now()->addMonths(6)->addWeek(), 'status' => 'open',
        ]);
        $event->roles()->create(['role_key' => 'child', 'capacity' => $capacity, 'reserve_places' => $reserve, 'min_age' => 8, 'max_age' => 13]);

        return $event;
    }

    private function child(int $ageAtStart = 10): Person
    {
        return Person::factory()->create(['birth_date' => now()->addMonths(6)->subYears($ageAtStart)->subDay()]);
    }

    private function registrations(): Registrations
    {
        return app(Registrations::class);
    }

    public function test_places_then_waitlist_with_positions(): void
    {
        $event = $this->event(capacity: 2);
        $a = $this->registrations()->register($event, $this->child());
        $this->travel(1)->minutes();
        $b = $this->registrations()->register($event, $this->child());
        $this->travel(1)->minutes();
        $c = $this->registrations()->register($event, $this->child());
        $this->travel(1)->minutes();
        $d = $this->registrations()->register($event, $this->child());

        $this->assertSame(['registered', 'registered', 'waitlist', 'waitlist'], [$a->status, $b->status, $c->status, $d->status]);
        $this->assertSame([1, 2], [$c->waitlistPosition(), $d->waitlistPosition()]);
        $this->assertSame(2, $event->roles()->firstOrFail()->waitlistLength());

        $this->registrations()->cancel($c);
        $this->assertSame(1, $d->refresh()->waitlistPosition());
    }

    public function test_reserve_places_start_the_waitlist_earlier_and_are_assigned_by_hand(): void
    {
        $event = $this->event(capacity: 3, reserve: 1);
        $this->registrations()->register($event, $this->child());
        $this->registrations()->register($event, $this->child());
        $third = $this->registrations()->register($event, $this->child());
        $this->assertSame('waitlist', $third->status);

        try {
            $this->registrations()->assignReservePlace($third, '  ');
            $this->fail('A reason is required.');
        } catch (RegistrationException) {
        }

        $this->registrations()->assignReservePlace($third, 'Special needs');
        $third->refresh();
        $this->assertSame('registered', $third->status);
        $this->assertTrue($third->reserve_place);

        $fourth = $this->registrations()->register($event, $this->child());
        $this->expectException(RegistrationException::class);
        $this->registrations()->assignReservePlace($fourth, 'Another one');
    }

    public function test_age_is_checked_at_event_start(): void
    {
        $event = $this->event();
        $this->registrations()->register($event, $this->child(ageAtStart: 13));
        $this->expectException(RegistrationException::class);
        $this->registrations()->register($event, $this->child(ageAtStart: 14));
    }

    public function test_closed_events_and_duplicates_are_refused(): void
    {
        $closed = $this->event(attributes: ['status' => 'closed']);
        try {
            $this->registrations()->register($closed, $this->child());
            $this->fail('Closed events must refuse registrations.');
        } catch (RegistrationException) {
        }

        $event = $this->event();
        $child = $this->child();
        $this->registrations()->register($event, $child);
        $this->expectException(RegistrationException::class);
        $this->registrations()->register($event, $child);
    }

    public function test_non_binding_events_create_requests(): void
    {
        $event = $this->event(attributes: ['registration_binding' => false]);
        $this->assertSame('requested', $this->registrations()->register($event, $this->child())->status);
    }
}
