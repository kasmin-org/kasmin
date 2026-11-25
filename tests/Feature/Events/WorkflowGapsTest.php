<?php

namespace Tests\Feature\Events;

use App\Models\Automation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Automations\Actions\OfferExpiry;
use App\Support\Automations\Actions\StartSeason;
use App\Support\Events\Registrations;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gaps found when the yearly work was compared with the product (CA01, WL08, ST05, EC03a).
 */
class WorkflowGapsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-03-01 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $series = EventSeries::query()->create(['name' => 'Summer camp']);
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'event_series_id' => $series->id, 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 1, 'min_age' => 8, 'max_age' => 13]);
    }

    private function child(string $name, string $gender = 'female', string $born = '2016-05-01'): Person
    {
        $guardian = Person::factory()->create(['first_name' => 'Kim', 'last_name' => $name, 'locale' => 'en']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => strtolower($name).'@example.org', 'is_primary' => true]);
        $child = Person::factory()->create(['first_name' => 'Child', 'last_name' => $name, 'birth_date' => $born, 'gender' => $gender]);
        $child->guardians()->attach($guardian->id);

        return $child;
    }

    public function test_the_family_gets_a_confirmation_of_the_cancellation(): void
    {
        $p = app(Registrations::class)->register($this->event, $this->child('Ash'));
        app(Registrations::class)->cancel($p);

        $mail = OutgoingMail::query()->where('to_address', 'ash@example.org')->where('subject', 'Cancellation of Camp 2027: Child Ash')->first();
        $this->assertNotNull($mail);
    }

    public function test_an_offer_that_runs_out_is_told_to_the_family(): void
    {
        app(Registrations::class)->register($this->event, $this->child('Ash'));
        $waiting = app(Registrations::class)->register($this->event, $this->child('Birch'));
        $waiting->fill(['status' => 'offered', 'offer_expires_at' => now()->subHour()])->save();

        $action = app(OfferExpiry::class);
        $automation = Automation::query()->where('action', 'offer_expiry')->firstOrFail();
        foreach ($action->plan($automation, null) as $item) {
            $action->execute($automation, $item, null);
        }

        $this->assertNotSame('offered', $waiting->refresh()->status);
        $this->assertTrue(OutgoingMail::query()->where('to_address', 'birch@example.org')->where('subject', 'Offer expired: Camp 2027')->exists());
    }

    public function test_start_season_keeps_the_waitlist_with_its_dates(): void
    {
        $registered = app(Registrations::class)->register($this->event, $this->child('Ash'));
        $this->travel(1)->days();
        $waiting = app(Registrations::class)->register($this->event, $this->child('Birch'));
        $this->assertSame('waitlist', $waiting->status);

        $this->travelTo(Carbon::parse('2028-01-10 12:00'));
        $next = Event::query()->create(['title' => 'Camp 2028', 'event_series_id' => $this->event->event_series_id, 'starts_at' => '2028-08-01 09:00', 'ends_at' => '2028-08-08 16:00', 'status' => 'open']);
        $next->roles()->create(['role_key' => 'child', 'capacity' => 1, 'min_age' => 8, 'max_age' => 13]);
        $automation = Automation::query()->where('action', 'start_season')->firstOrFail();
        $action = app(StartSeason::class);
        $items = $action->plan($automation, $next);
        $this->assertSame(['waitlist:'.$waiting->person_id, 'register:'.$registered->person_id], array_column($items, 'key'));
        foreach ($items as $item) {
            $action->execute($automation, $item, $next);
        }

        $carried = Participation::query()->where('event_id', $next->id)->where('person_id', $waiting->person_id)->firstOrFail();
        $this->assertSame('waitlist', $carried->status);
        $this->assertTrue($waiting->registered_at->equalTo($carried->registered_at));
        $this->assertSame(1, $carried->waitlistPosition());
        $this->assertSame('registered', Participation::query()->where('event_id', $next->id)->where('person_id', $registered->person_id)->value('status'));
        $this->assertSame([], $action->plan($automation, $next));
    }

    public function test_figures_and_gender_in_the_lists(): void
    {
        $this->event->roles()->where('role_key', 'child')->update(['capacity' => 2]);
        $before = Event::query()->create(['title' => 'Camp 2026', 'event_series_id' => $this->event->event_series_id, 'starts_at' => '2026-08-01 09:00', 'ends_at' => '2026-08-08 16:00', 'status' => 'closed']);
        $ash = $this->child('Ash', 'female');
        Participation::query()->create(['event_id' => $before->id, 'person_id' => $ash->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => now()->subYear()]);
        app(Registrations::class)->register($this->event, $ash);
        app(Registrations::class)->register($this->event, $this->child('Birch', 'male'));
        app(Registrations::class)->register($this->event, $this->child('Cedar', 'male'));

        $figures = app(EventLists::class)->build($this->event, 'figures');
        // role, female, male, diverse, not specified, total, were there before, new, waitlist
        $this->assertSame(['Child', '1', '1', '0', '0', '2', '1', '1', '1'], $figures->rows[0]);
        $this->assertSame(['Total', '1', '1', '0', '0', '2', '1', '1', '1'], $figures->rows[1]);

        $participants = app(EventLists::class)->build($this->event, 'participants');
        $this->assertContains('Gender', $participants->headers);
        $this->assertContains('female', $participants->rows[0]);
    }

    public function test_confirmation_of_participation_after_the_event_only_for_the_family(): void
    {
        $child = $this->child('Ash');
        $p = app(Registrations::class)->register($this->event, $child);
        $guardian = $child->guardians()->firstOrFail();
        $user = User::query()->create(['name' => 'Kim', 'email' => 'ash@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $url = route('portal.child.confirmation', [$child->public_id, $p->id]);
        $this->withoutMiddleware(AuthenticateSession::class);

        $this->actingAs($user)->get($url)->assertNotFound(); // the event is not over
        $this->travelTo(Carbon::parse('2027-09-01 12:00'));
        $this->actingAs($user)->get(route('portal.child', $child->public_id))->assertOk()->assertSee('Confirmation of participation');
        $response = $this->actingAs($user)->get($url)->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $other = $this->child('Birch')->guardians()->firstOrFail();
        $stranger = User::query()->create(['name' => 'Other', 'email' => 'birch@example.org', 'password' => 'x', 'person_id' => $other->id]);
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get(route('admin.participation.confirmation', $p))->assertForbidden();
    }
}
