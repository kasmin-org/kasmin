<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\EventListsPage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\ParticipationsRelationManager;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\PersonResource;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Install\Defaults;
use App\Support\Lists\EventLists;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Camp leaders and counsellors only see their own events and their people (RO01).
 */
class OwnEventsTest extends TestCase
{
    use RefreshDatabase;

    private Event $mine;

    private Event $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->mine = $this->event('My camp');
        $this->other = $this->event('Other camp');
    }

    private function event(string $title, int $days = 30): Event
    {
        $event = Event::query()->create(['title' => $title, 'status' => 'open', 'starts_at' => now()->addDays($days), 'ends_at' => now()->addDays($days + 7)]);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 10]);
        $event->roles()->create(['role_key' => 'staff', 'capacity' => 10, 'registered_by' => 'self']);

        return $event;
    }

    /** @return array{0: Person, 1: Person} child, guardian */
    private function child(Event $event, string $name, bool $own = false): array
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Child', 'birth_date' => now()->subYears(12)]);
        $guardian = Person::factory()->create(['first_name' => $name, 'last_name' => 'Guardian', 'birth_date' => '1980-01-01']);
        $child->guardians()->attach($guardian->id);
        $this->take($event, $child, 'child', $own ? $child : null);

        return [$child, $guardian];
    }

    private function take(Event $event, Person $person, string $role, ?Person $by = null, string $status = 'registered'): Participation
    {
        return Participation::query()->create(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => $role, 'status' => $status, 'registered_at' => now(), 'registered_by_id' => $by?->id]);
    }

    private function user(string $role, ?Event $event = null): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['first_name' => 'Team', 'last_name' => ucfirst($role), 'birth_date' => '1990-01-01', 'locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id'), 'event_id' => $event?->id]);
        $user = User::query()->create(['name' => $role, 'email' => uniqid().'@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    public function test_camp_leader_sees_and_edits_only_the_assigned_event(): void
    {
        [$child] = $this->child($this->mine, 'Mine');
        [$stranger] = $this->child($this->other, 'Stranger');
        $this->user('camp_leader', $this->mine);

        $this->get('/admin/events')->assertOk()->assertSee('My camp')->assertDontSee('Other camp');
        $this->get('/admin/events/'.$this->mine->id.'/edit')->assertOk();
        $this->get('/admin/events/'.$this->other->id.'/edit')->assertNotFound();
        $this->get('/admin/events/'.$this->mine->id.'/schedule')->assertOk();
        $this->get('/admin/events/'.$this->other->id.'/schedule')->assertNotFound();
        $this->get('/admin')->assertOk()->assertSee('My camp')->assertDontSee('Other camp')->assertDontSee('To do');
        foreach (['events/create', 'event-series', 'event-templates', 'season', 'reports', 'households', 'groups', 'duplicates', 'import/people', 'invoices', 'mailings', 'outgoing-mails', 'bad-addresses', 'automations', 'forms', 'audit-events', 'roles', 'settings/organisation'] as $page) {
            $this->assertSame(403, $this->get('/admin/'.$page)->getStatusCode(), $page);
        }
        $this->get(route('admin.lists.export', [$this->mine, 'contacts', 'csv']))->assertOk();
        $this->get(route('admin.lists.export', [$this->other, 'contacts', 'csv']))->assertForbidden();
        $this->get(route('admin.lists.export', [$this->mine, 'medical', 'csv']))->assertForbidden();
        $this->get(route('admin.lists.folder', [$this->mine]))->assertForbidden();
        $this->get('/admin/people/'.$child->id)->assertOk();
        $this->get('/admin/people/'.$stranger->id)->assertNotFound();
        $this->get('/admin/people/'.$child->id.'/edit')->assertForbidden();
    }

    public function test_lists_page_and_tables_cannot_be_pointed_at_another_event(): void
    {
        [$mine] = $this->child($this->mine, 'Mine');
        [$stranger] = $this->child($this->other, 'Stranger');
        $this->user('camp_leader', $this->mine);
        Livewire::test(EventListsPage::class)->set('event', $this->mine->id)->set('list', 'participants')->assertSee('Mine')
            ->set('event', $this->other->id)->assertDontSee('Stranger')->set('selected', ['x'])->call('remind')->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->where('action', 'list.viewed')->where('subject_id', $this->other->id)->count());
        Livewire::test(ListPeople::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$stranger]);
        // Searching for anybody in the organisation is not possible from the own event.
        Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->mine, 'pageClass' => EditEvent::class])->assertOk()->assertTableActionHidden('offer')->assertTableActionHidden('create');
        Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $this->other, 'pageClass' => EditEvent::class])->assertTableActionHidden('cancel', Participation::query()->where('event_id', $this->other->id)->firstOrFail());
    }

    public function test_people_are_the_participants_and_their_guardians_without_private_registrations(): void
    {
        [$child, $guardian] = $this->child($this->mine, 'Mine');
        [$private, $unaware] = $this->child($this->mine, 'Private', own: true);
        [$stranger, $strangerGuardian] = $this->child($this->other, 'Stranger');
        $cancelled = Person::factory()->create();
        $this->take($this->mine, $cancelled, 'child', status: 'cancelled');
        $this->user('camp_leader', $this->mine);
        $this->assertEqualsCanonicalizing([$child->id, $guardian->id, $private->id], PersonResource::getEloquentQuery()->pluck('id')->all());
        foreach ([$unaware, $stranger, $strangerGuardian, $cancelled] as $hidden) {
            $this->assertFalse(PersonResource::canView($hidden));
        }
        Livewire::test(ListPeople::class)->assertCanSeeTableRecords([$child, $guardian, $private])->assertCountTableRecords(3);
    }

    public function test_own_events_by_taking_part_in_the_team_but_not_by_registering_oneself(): void
    {
        $user = $this->user('camp_leader');
        $person = $user->person ?? $this->fail();
        $seen = [];
        $look = function () use (&$seen, $user): void {
            $seen[] = Permissions::ownEvents($user);
        };
        $look();
        $p = $this->take($this->other, $person, 'staff', $person);
        $look(); // registered by itself
        $p->update(['status' => 'confirmed']);
        $look(); // confirmed by the team
        $this->take($this->mine, $person, 'child');
        $look(); // taking part as a child does not count
        Settings::set('own_event_days', '30');
        $this->travel(37 + 30 - 1)->days();
        $this->assertNotSame([], Permissions::ownEvents($user), 'still inside the days set by the administrators');
        $this->travel(2)->days();
        $look(); // too long ago
        $this->assertSame('[[],[],['.$this->other->id.'],['.$this->other->id.'],[]]', json_encode($seen));
    }

    public function test_ended_assignments_and_deleted_events_give_nothing(): void
    {
        $user = $this->user('camp_leader', $this->mine);
        $own = fn (): string => (string) json_encode(Permissions::ownEvents($user));
        $this->assertStringContainsString((string) $this->mine->id, $own());
        $user->person?->roleAssignments()->toBase()->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->assertEmpty(json_decode($own(), true));
        $user->person?->roleAssignments()->toBase()->update(['ends_on' => null]);
        auth()->forgetUser();
        $this->mine->delete();
        $this->assertEmpty(json_decode($own(), true));
        $this->assertSame(0, $user->person?->roleAssignments()->count(), 'the assignment does not become one for all events');
    }

    public function test_safety_lists_of_the_own_event_only_if_allowed(): void
    {
        Role::query()->where('key', 'camp_leader')->firstOrFail()->update(['permissions' => ['events' => 'edit', 'people' => 'view', 'safety' => 'view']]);
        $user = $this->user('camp_leader', $this->mine);
        $this->assertTrue(EventLists::mayView($user, 'medical', $this->mine->id));
        $this->assertFalse(EventLists::mayView($user, 'medical', $this->other->id));
        $this->assertFalse(Permissions::allows($user, 'safety'));
        $this->get(route('admin.lists.folder', [$this->mine]))->assertOk();
        $this->get(route('admin.lists.folder', [$this->other]))->assertForbidden();
    }

    public function test_counsellors_have_lists_of_their_events_in_the_portal_but_no_admin_interface(): void
    {
        $this->child($this->mine, 'Mine');
        $user = $this->user('counsellor');
        $this->take($this->mine, $user->person ?? $this->fail(), 'staff');
        $this->get('/admin')->assertForbidden();
        $this->get('/portal')->assertOk()->assertSee('My events (team)')->assertSee('My camp')->assertDontSee('Other camp');
        $this->get('/portal/team/'.$this->mine->public_id)->assertOk()->assertSee('Mine');
        foreach (['contacts', 'bus', 'birthdays'] as $list) {
            $this->get('/portal/team/'.$this->mine->public_id.'/'.$list)->assertOk();
        }
        foreach (['medical', 'emergency', 'kitchen', 'answers', 'missing', 'unknown'] as $list) {
            $this->get('/portal/team/'.$this->mine->public_id.'/'.$list)->assertNotFound();
        }
        $this->get('/portal/team/'.$this->other->public_id)->assertNotFound();
        $this->assertTrue(AuditEvent::query()->where('action', 'list.viewed')->exists());

        // A link in a mail only gives a limited session.
        $this->withSession(['login_scope' => 'limited'])->get('/portal/team/'.$this->mine->public_id)->assertRedirect(route('step-up'));
    }

    public function test_nobody_else_has_the_team_page_and_documents_follow_the_own_events(): void
    {
        $guardian = $this->child($this->mine, 'Mine')[1];
        $user = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => $guardian->id]);
        $this->actingAs($user)->get('/portal')->assertOk()->assertDontSee('My events (team)');
        $this->get('/portal/team/'.$this->mine->public_id)->assertNotFound();

        $leader = $this->user('camp_leader', $this->mine);
        $this->assertTrue(Permissions::allowsEvent($leader, $this->mine->id));
        $this->assertFalse(Permissions::allowsEvent($leader, $this->other->id));
        $document = EventDocument::query()->create(['event_id' => $this->other->id, 'title' => 'Plan', 'visibility' => 'participants', 'path' => 'event-documents/x.pdf']);
        $this->get(route('event.document', $document))->assertNotFound();
    }

    public function test_roles_for_everything_stay_as_they_were(): void
    {
        $this->user('board');
        $this->get('/admin/events')->assertOk()->assertSee('My camp')->assertSee('Other camp');
        $this->assertNull(Permissions::restriction());
        $this->assertNull(Permissions::restriction('people'));
    }
}
