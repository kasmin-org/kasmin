<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\ParticipationsRelationManager;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\RegistrationRound;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Events\Rounds;
use App\Support\Finance\Payments;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RoundsTest extends TestCase
{
    use RefreshDatabase;

    private EventSeries $series;

    private Event $event;

    /** @var array<int, Event> */
    private array $past = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-10 12:00'));
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->series = EventSeries::query()->create(['name' => 'Camp', 'type' => 'camp']);
        foreach ([2024, 2025, 2026] as $year) {
            $this->past[$year] = Event::query()->create(['title' => 'Camp '.$year, 'event_series_id' => $this->series->id, 'starts_at' => $year.'-08-01', 'ends_at' => $year.'-08-08', 'status' => 'finished']);
        }
        $this->event = Event::query()->create(['title' => 'Camp 2027', 'event_series_id' => $this->series->id, 'starts_at' => '2027-08-01 09:00', 'ends_at' => '2027-08-08 16:00', 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 3, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 42000]);
        $this->event->roles()->create(['role_key' => 'junior_counsellor', 'capacity' => 2, 'min_age' => 14, 'max_age' => 15]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'sort' => 1]);
    }

    /** @param list<int> $years */
    private function child(string $name, string $born, array $years, ?Household $household = null): Person
    {
        $person = Person::factory()->create(['first_name' => $name, 'last_name' => 'Ash', 'birth_date' => $born, 'locale' => 'en']);
        $household ??= Household::query()->create(['name' => $name]);
        $guardian = $household->guardians()->first();
        if ($guardian === null) {
            $guardian = Person::factory()->create(['first_name' => 'Guardian '.$name, 'birth_date' => '1980-01-01', 'locale' => 'en']);
            $guardian->contactPoints()->create(['type' => 'email', 'value' => strtolower($name).'@example.org', 'is_primary' => true]);
            $household->members()->attach($guardian->id, ['role' => 'guardian']);
        }
        $household->members()->attach($person->id, ['role' => 'child']);
        $person->guardians()->attach($guardian->id);
        foreach ($years as $year) {
            Participation::query()->create(['event_id' => $this->past[$year]->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => 'attended', 'registered_at' => $year.'-01-01']);
        }

        return $person;
    }

    /** @param array<string, mixed> $more */
    private function round(string $kind, string $role = 'child', array $more = []): RegistrationRound
    {
        return $this->event->rounds()->create($more + ['role_key' => $role, 'kind' => $kind, 'opens_at' => '2027-01-01 08:00', 'closes_at' => '2027-02-01 23:59']);
    }

    private function runRounds(): void
    {
        $automation = Automation::query()->where('key', 'registration_rounds')->firstOrFail();
        $automation->update(['mode' => 'automatic']);
        app(Engine::class)->trigger($automation, 'manual');
    }

    private function statusOf(Person $person): ?string
    {
        return Participation::query()->where('event_id', $this->event->id)->where('person_id', $person->id)->value('status');
    }

    public function test_returning_participants_get_offers_and_others_wait(): void
    {
        $family = Household::query()->create(['name' => 'Two']);
        $ann = $this->child('Ann', '2016-01-01', [2026], $family);
        $ben = $this->child('Ben', '2017-01-01', [2026], $family);
        $old = $this->child('Old', '2012-01-01', [2026]);      // too old
        $former = $this->child('Former', '2016-01-01', [2025]); // not last year
        $new = $this->child('New', '2016-01-01', []);
        $this->round('returning');
        $this->assertSame('waitlist', app(Registrations::class)->register($this->event, $new, 'child', $new->guardians()->first())->status, 'no direct places before the open round');
        $this->runRounds();
        $this->assertSame(['offered', 'offered', null, null, 'waitlist'], array_map(fn (Person $p) => $this->statusOf($p), [$ann, $ben, $old, $former, $new]));
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Camp 2027: place for%')->count(), 'one mail per family');
        $this->runRounds();
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Camp 2027: place for%')->count(), 'nobody twice');

        $offer = Participation::query()->where('event_id', $this->event->id)->where('person_id', $ann->id)->sole();
        app(Registrations::class)->answerOffer($offer, true);
        $this->assertSame('registered', $this->statusOf($ann));
        app(Registrations::class)->answerOffer(Participation::query()->where('event_id', $this->event->id)->where('person_id', $ben->id)->sole(), false);
        $this->runRounds();
        $this->assertSame('declined', $this->statusOf($ben), 'no second offer after declining');
    }

    public function test_waitlist_moves_only_from_its_round_on(): void
    {
        $new = $this->child('New', '2016-01-01', []);
        $this->round('returning');
        $waitlist = $this->round('waitlist', 'child', ['opens_at' => '2027-02-02 08:00', 'closes_at' => null]);
        $open = $this->round('open', 'child', ['opens_at' => '2027-03-01 08:00', 'closes_at' => null]);
        app(Registrations::class)->register($this->event, $new, 'child', $new->guardians()->first());
        $automation = Automation::query()->where('action', 'waitlist_offer')->firstOrFail();
        $this->assertSame([], app(Engine::class)->preview($automation, $this->event));
        $this->travelTo(Carbon::parse('2027-02-02 09:00'));
        $this->assertTrue($waitlist->refresh()->active());
        $this->assertCount(1, app(Engine::class)->preview($automation, $this->event));
        $this->travelTo(Carbon::parse('2027-03-01 09:00'));
        $late = $this->child('Late', '2016-01-01', []);
        $this->assertSame('registered', app(Registrations::class)->register($this->event, $late, 'child', $late->guardians()->first())->status);
        $this->assertTrue($open->refresh()->active());
    }

    public function test_paying_accepts_the_place(): void
    {
        $ann = $this->child('Ann', '2016-01-01', [2026]);
        $ben = $this->child('Ben', '2016-01-01', [2026]);
        $this->round('returning', 'child', ['mode' => 'payment']);
        $this->runRounds();
        $this->assertSame(2, Invoice::query()->where('status', 'issued')->count());
        $mail = OutgoingMail::query()->where('to_address', 'ann@example.org')->where('subject', 'like', 'Camp 2027: place%')->sole();
        $this->assertNotNull($mail->attachments);
        $this->assertStringContainsString('By paying', $mail->html);
        $p = Participation::query()->where('event_id', $this->event->id)->where('person_id', $ann->id)->sole();
        try {
            app(Registrations::class)->answerOffer($p, true);
            $this->fail('accepted without paying');
        } catch (RegistrationException) {
            $this->assertSame('offered', $this->statusOf($ann));
        }
        $invoice = Invoice::query()->whereHas('lines', fn ($q) => $q->where('person_id', $ann->id))->sole();
        app(Payments::class)->record($invoice, $invoice->total_cents, now());
        $this->assertSame('confirmed', $this->statusOf($ann));

        $expiry = Automation::query()->where('action', 'offer_expiry')->firstOrFail();
        $this->travelTo(Carbon::parse('2027-02-03 12:00'));
        $this->assertSame([], app(Engine::class)->preview($expiry, $this->event), 'transfers need some days');
        $this->travelTo(Carbon::parse('2027-02-05 12:00'));
        $this->assertCount(1, app(Engine::class)->preview($expiry, $this->event));
        $this->assertSame('offered', $this->statusOf($ben));
    }

    public function test_selection_by_rule_order_and_capacity(): void
    {
        $three = $this->child('Three', '2013-01-01', [2024, 2025, 2026]);
        $two = $this->child('Two', '2013-01-01', [2025, 2026]);
        $earlier = $this->child('Earlier', '2013-01-01', [2024, 2025]);
        $one = $this->child('One', '2013-01-01', [2026]);
        $young = $this->child('Young', '2016-01-01', [2024, 2025, 2026]);
        $round = $this->round('selection', 'junior_counsellor', ['min_events' => 2]);
        $this->assertSame([$three->id, $two->id], app(Rounds::class)->candidates($round)->map(fn (Person $p) => $p->id)->all());
        $this->runRounds();
        $this->assertSame(['offered', 'offered', null, null, null], array_map(fn (Person $p) => $this->statusOf($p), [$three, $two, $earlier, $one, $young]));
        app(Registrations::class)->answerOffer(Participation::query()->where('event_id', $this->event->id)->where('person_id', $three->id)->sole(), false);
        $this->runRounds();
        $this->assertSame('offered', $this->statusOf($earlier), 'the next one in order');
    }

    public function test_administrators_offer_out_of_turn_with_a_reason(): void
    {
        $one = $this->child('One', '2016-01-01', []);
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->actingAs(User::query()->create(['name' => 'a', 'email' => 'a@example.org', 'password' => 'x', 'person_id' => $person->id]));
        $component = fn () => Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class]);
        $data = ['person_id' => $one->id, 'role_key' => 'child', 'until' => '2027-02-01', 'reason' => ''];
        $component()->callTableAction('offer', data: $data)->assertHasTableActionErrors(['reason']);
        $component()->callTableAction('offer', data: ['reason' => 'sibling'] + $data)->assertHasNoTableActionErrors();
        $this->assertSame('offered', $this->statusOf($one));
        $this->assertSame('sibling', Participation::query()->where('person_id', $one->id)->value('offer_reason'));
    }

    public function test_without_rounds_everything_works_as_before(): void
    {
        $new = $this->child('New', '2016-01-01', []);
        $this->assertSame('registered', app(Registrations::class)->register($this->event, $new, 'child', $new->guardians()->first())->status);
    }
}
