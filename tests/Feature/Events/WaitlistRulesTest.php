<?php

namespace Tests\Feature\Events;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\WaitlistRelationManager;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\RelationManagers\WaitlistRelationManager as GroupWaitlistManager;
use App\Models\Automation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Group;
use App\Models\GroupWaitlistEntry;
use App\Models\Household;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Engine;
use App\Support\Events\EventCopier;
use App\Support\Events\RegistrationException;
use App\Support\Events\ReservePlaces;
use App\Support\Events\Waitlist;
use App\Support\Install\Defaults;
use App\Support\People\GroupWaitlist;
use App\Support\Privacy\Privacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Waitlists: rules, flags, skipping, reserve places, waitlists of groups (WL01, WL02, WL06, AD31).
 */
class WaitlistRulesTest extends TestCase
{
    use RefreshDatabase;

    private EventSeries $series;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $this->series = EventSeries::query()->create(['name' => 'Summer', 'type' => 'camp']);
    }

    /** @param  list<string>|null  $order */
    private function event(int $capacity = 2, int $reserve = 0, ?array $order = null, int $inDays = 100): Event
    {
        $event = Event::query()->create(['title' => 'Camp', 'event_series_id' => $this->series->id, 'status' => 'open', 'starts_at' => now()->addDays($inDays), 'ends_at' => now()->addDays($inDays + 7)]);
        $event->roles()->create(['role_key' => 'child', 'capacity' => $capacity, 'reserve_places' => $reserve, 'waitlist_order' => $order]);

        return $event;
    }

    private function child(string $name, ?Household $household = null): Person
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Example', 'birth_date' => now()->subYears(10), 'locale' => 'en']);
        $guardian = Person::factory()->create(['locale' => 'en']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => strtolower($name).'@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $child->guardians()->attach($guardian->id);
        $household ??= Household::factory()->create();
        $household->members()->attach($child->id, ['role' => 'child']);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);

        return $child;
    }

    private function join(Event $event, Person $person, string $status = 'waitlist', int $minute = 0): Participation
    {
        return Participation::query()->create(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => $status, 'registered_at' => now()->subDays(10)->addMinutes($minute)]);
    }

    private function login(string $role = 'administrator'): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $role.'@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $user = User::query()->firstOrCreate(['email' => $role.'@example.org'], ['name' => $role, 'password' => 'x', 'person_id' => $person->id]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    /** @return list<string> */
    private function names(Event $event): array
    {
        return array_map(fn (array $p) => (string) $p['entry']->person?->first_name, app(Waitlist::class)->ofEvent($event, 'child'));
    }

    public function test_without_rules_the_registration_date_counts(): void
    {
        $event = $this->event();
        $late = $this->join($event, $this->child('Late'), minute: 5);
        $early = $this->join($event, $this->child('Early'), minute: 1);
        $this->assertSame(['Early', 'Late'], $this->names($event));
        $this->assertSame([2, 1, null], [$late->waitlistPosition(), $early->waitlistPosition(), $this->join($event, $this->child('In'), 'registered')->waitlistPosition()]);
    }

    public function test_order_by_previous_event_number_of_events_and_date(): void
    {
        $older = $this->event(inDays: -800);
        $previous = $this->event(inDays: -400);
        $event = $this->event(order: ['previous', 'camps']);
        $new = $this->child('New');
        $once = $this->child('LastYear');
        $twice = $this->child('LastYearTwice');
        $former = $this->child('Former');
        $this->join($previous, $once, 'attended');
        $this->join($previous, $twice, 'attended');
        $this->join($older, $twice, 'attended');
        $this->join($older, $former, 'attended');
        foreach ([$new, $former, $once, $twice] as $i => $person) {
            $this->join($event, $person, minute: $i);
        }
        $this->assertSame(['LastYearTwice', 'LastYear', 'Former', 'New'], $this->names($event));
    }

    public function test_priority_flags_and_siblings_that_take_part(): void
    {
        $event = $this->event(order: ['flags']);
        $family = Household::factory()->create();
        $this->join($event, $this->child('Brother', $family), 'registered');
        $this->join($event, $this->child('First'), minute: 1);
        $this->join($event, $this->child('Sister', $family), minute: 2);
        $social = $this->join($event, $this->child('Social'), minute: 3);
        $social->update(['waitlist_flags' => ['social', 'other', 'unknown']]);
        $this->assertSame(['Social', 'Sister', 'First'], $this->names($event));
    }

    public function test_skipped_people_keep_their_position_but_get_no_offer(): void
    {
        $event = $this->event(capacity: 1);
        $first = $this->join($event, $this->child('First'), minute: 1);
        $second = $this->join($event, $this->child('Second'), minute: 2);
        $first->update(['skipped_at' => now(), 'skip_reason' => 'Family asked']);
        $this->assertSame(1, $first->waitlistPosition());
        $automation = Automation::query()->where('action', 'waitlist_offer')->firstOrFail();
        $plan = app(Engine::class)->preview($automation, $event);
        $this->assertSame(['participation:'.$second->id], array_column($plan, 'key'));
    }

    public function test_the_waitlist_moves_after_the_registration_is_closed(): void
    {
        $event = $this->event(capacity: 1);
        $event->update(['status' => 'closed']);
        $this->join($event, $this->child('First'));
        $this->assertCount(1, app(Engine::class)->preview(Automation::query()->where('action', 'waitlist_offer')->firstOrFail()));
    }

    public function test_reserve_places_are_released_by_hand_with_a_reason(): void
    {
        $event = $this->event(capacity: 3, reserve: 2);
        $role = $event->roles()->firstOrFail();
        $this->assertSame(2, app(ReservePlaces::class)->release($role, 5, 'Not needed'));
        $this->assertSame([0, 3], [$role->fresh()?->reserve_places, $role->fresh()?->bookableLeft()]);
        $this->expectException(RegistrationException::class);
        app(ReservePlaces::class)->release($role, 1, ' ');
    }

    public function test_reserve_places_reminder_to_administrators_once_and_automatic_release_only_if_set(): void
    {
        $this->login();
        $event = $this->event(capacity: 3, reserve: 1);
        $event->update(['reserve_reminder_on' => now()->toDateString()]);
        app(ReservePlaces::class)->tick();
        app(ReservePlaces::class)->tick();
        $this->assertSame(1, OutgoingMail::query()->where('to_address', 'administrator@example.org')->where('subject', 'like', 'Reserve places still free%')->count());
        $this->assertSame(1, $event->roles()->firstOrFail()->reserve_places, 'nothing is released without the setting');

        $event->refresh()->update(['reserve_reminder_on' => now()->subDay()->toDateString(), 'reserve_auto_release' => true]);
        $this->assertNull($event->fresh()?->reserve_reminded_at);
        app(ReservePlaces::class)->tick();
        $this->assertSame(0, $event->roles()->firstOrFail()->reserve_places);

        $copy = app(EventCopier::class)->copy($event->fresh() ?? $event, 'Next', now()->addYears(2));
        $this->assertNull($copy->fresh()?->reserve_reminded_at);
    }

    public function test_waitlist_page_shows_the_order_and_manual_actions(): void
    {
        $event = $this->event(capacity: 1, order: ['flags']);
        $first = $this->join($event, $this->child('First'), minute: 1);
        $second = $this->join($event, $this->child('Second'), minute: 2);
        $this->login('camp_leader');
        $page = fn () => Livewire::test(WaitlistRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
        $page()->assertOk()->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $page()->assertSee('priority, registration date');
        $page()->callTableAction('flags', $second, ['flags' => ['social'], 'note' => 'Please talk to us'])->assertHasNoTableActionErrors();
        $this->assertSame(1, $second->fresh()?->waitlistPosition());
        $page()->assertCanSeeTableRecords([$second, $first], inOrder: true);
        $page()->callTableAction('skip', $second, ['reason' => 'On holiday'])->assertHasNoTableActionErrors();
        $this->assertNotNull(Participation::query()->findOrFail($second->id)->skipped_at);
        $page()->callTableAction('offer_now', $first, ['until' => now()->addDays(5)->toDateString(), 'reason' => 'Skipped the first'])->assertHasNoTableActionErrors();
        $this->assertSame('offered', $first->fresh()?->status);
        $this->assertTrue(OutgoingMail::query()->where('subject', 'like', 'A place is free%')->exists());
        $page()->assertCanSeeTableRecords([$first, $second], inOrder: true); // the open offer is first

        // Internal notes and flags never reach the portal.
        $child = Person::query()->findOrFail($second->person_id);
        $guardian = $child->guardians()->firstOrFail();
        $user = User::query()->firstOrCreate(['person_id' => $guardian->id], ['name' => 'G', 'email' => 'second@example.org', 'password' => 'x']);
        auth()->forgetUser();
        $this->actingAs($user)->get('/portal/children/'.$child->public_id)->assertOk()->assertSee('Position 1 of 1')->assertSee('priority, registration date')->assertDontSee('Please talk to us')->assertDontSee('Social reasons')->assertDontSee('On holiday');
    }

    public function test_waitlist_of_a_group_with_offer_deadline_and_portal(): void
    {
        $group = Group::query()->create(['name' => 'Junior team', 'kind' => 'manual', 'has_waitlist' => true, 'capacity' => 1]);
        $service = app(GroupWaitlist::class);
        $one = $this->child('One');
        $two = $this->child('Two');
        $first = $service->add($group, $one);
        $second = $service->add($group, $two);
        try {
            $service->add($group, $one);
            $this->fail('listed twice');
        } catch (RegistrationException) {
            $this->assertSame(2, $service->position($second));
        }

        $automation = Automation::query()->where('action', 'group_waitlist_offer')->firstOrFail();
        $plan = app(Engine::class)->preview($automation);
        $this->assertSame(['entry:'.$first->id], array_column($plan, 'key'));
        app(Engine::class)->action($automation)->execute($automation, $plan[0], null);
        $this->assertSame('offered', $first->fresh()?->status);
        $this->assertTrue(OutgoingMail::query()->where('subject', 'A place is free: Junior team')->exists());
        $this->assertSame([], app(Engine::class)->preview($automation), 'the place is taken by the offer');

        $user = User::query()->firstOrCreate(['person_id' => $one->guardians()->firstOrFail()->id], ['name' => 'G', 'email' => 'one@example.org', 'password' => 'x']);
        $this->actingAs($user)->get('/portal/children/'.$one->public_id)->assertOk()->assertSee('Junior team')->assertSee('Accept');
        $this->post('/portal/children/'.$one->public_id.'/waitlists/'.$second->id, ['answer' => 'accept'])->assertNotFound();
        $this->post('/portal/children/'.$one->public_id.'/waitlists/'.$first->id, ['answer' => 'accept'])->assertRedirect();
        $this->assertTrue($group->people()->whereKey($one->id)->exists());
        $this->assertSame('joined', GroupWaitlistEntry::query()->findOrFail($first->id)->status);

        $group->update(['capacity' => 2]);
        $this->assertTrue($service->offer($second->refresh(), now()->addDay()));
        $this->travel(2)->days();
        $this->artisan('kasmin:events:status');
        $this->assertSame('declined', $second->fresh()?->status);
    }

    public function test_group_waitlist_page_and_privacy(): void
    {
        $group = Group::query()->create(['name' => 'Junior team', 'kind' => 'manual', 'has_waitlist' => true, 'capacity' => 1]);
        $person = $this->child('One');
        $this->login();
        Livewire::test(GroupWaitlistManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])->callTableAction('add', null, ['person_id' => $person->id, 'note' => 'internal'])->assertHasNoTableActionErrors();
        $this->assertSame(1, GroupWaitlistEntry::query()->where('person_id', $person->id)->count());
        $this->assertFalse(GroupWaitlistManager::canViewForRecord(Group::query()->create(['name' => 'X', 'kind' => 'manual']), EditGroup::class));
        app(Privacy::class)->anonymise($person, 'request');
        $this->assertSame(0, GroupWaitlistEntry::query()->count());
    }
}
