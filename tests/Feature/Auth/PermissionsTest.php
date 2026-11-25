<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\OrganisationSettingsPage;
use App\Filament\Resources\Automations\Pages\ListAutomations;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\ParticipationsRelationManager;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\OutgoingMails\Pages\ListOutgoingMails;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\RelationManagers\RolesRelationManager;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Automation;
use App\Models\Event;
use App\Models\ImportBatch;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Install\Defaults;
use App\Support\People\Duplicates;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    private function user(string $role, ?string $mail = null): User
    {
        auth()->forgetUser(); // roles are assigned by the system here
        $person = Person::factory()->create(['locale' => 'en', 'first_name' => 'Role', 'last_name' => ucfirst($role)]);
        $person->contactPoints()->create(['type' => 'email', 'value' => $mail ?? $role.'@example.org', 'purposes' => ['login'], 'is_primary' => true]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->where('person_id', $person->id)->first() ?? User::query()->create(['name' => $role, 'email' => $mail ?? $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function pages(): array
    {
        $all = ['people', 'people/create', 'households', 'groups', 'membership-types', 'duplicates', 'import/people', 'events', 'events/create', 'event-series', 'event-templates', 'lists', 'forms', 'forms/create', 'public-forms', 'public-submissions',
            'invoices', 'payments', 'refunds', 'mailings', 'mail-templates', 'outgoing-mails', 'mail-accounts', 'mail-accounts/create', 'sender-profiles', 'automations', 'automation-runs', 'settings/organisation', 'privacy', 'consents', 'audit-events', 'api-tokens', 'webhooks', 'roles', 'reports', 'system'];

        return [
            'administrator' => ['administrator', $all],
            'board' => ['board', array_values(array_diff($all, ['mail-accounts', 'mail-accounts/create', 'sender-profiles', 'audit-events', 'api-tokens', 'webhooks', 'roles', 'system']))],
            'treasurer' => ['treasurer', ['people', 'households', 'groups', 'membership-types', 'events', 'invoices', 'payments', 'refunds', 'settings/organisation', 'reports']],
            'safety_officer' => ['safety_officer', ['people', 'households', 'groups', 'events', 'event-series', 'event-templates', 'lists', 'reports']],
            'camp_leader' => ['camp_leader', []], // without own events; with own events: OwnEventsTest
            'auditor' => ['auditor', ['events', 'invoices', 'payments', 'refunds', 'consents', 'audit-events', 'reports']],
        ];
    }

    /** @param list<string> $allowed */
    #[DataProvider('pages')]
    public function test_every_page_by_role(string $role, array $allowed): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actingAs($this->user($role));
        $this->get('/admin')->assertOk();
        foreach (self::pages()['administrator'][1] as $page) {
            $response = $this->get('/admin/'.$page);
            $this->assertSame(in_array($page, $allowed, true) ? 200 : 403, $response->getStatusCode(), $role.' '.$page);
        }
    }

    public function test_nobody_but_administrators_changes_the_login_of_an_administrator(): void
    {
        $admin = $this->user('administrator')->person ?? $this->fail();
        $board = $this->user('board');
        $this->actingAs($board);
        foreach ([fn () => $admin->contactPoints()->create(['type' => 'email', 'value' => 'attacker@example.org', 'purposes' => ['login']]),
            fn () => $admin->contactPoints()->firstOrFail()->update(['value' => 'attacker@example.org']),
            fn () => $admin->contactPoints()->firstOrFail()->delete(),
            fn () => $admin->contactPoints()->create(['type' => 'phone', 'value' => '1'])->update(['type' => 'email', 'value' => 'attacker@example.org']),
            fn () => $board->person?->contactPoints()->firstOrFail()->forceFill(['person_id' => $admin->id])->save(),
            fn () => $admin->delete()] as $i => $attempt) {
            try {
                $attempt();
                $this->fail('attempt '.$i);
            } catch (AuthorizationException) {
                $this->assertSame(['administrator@example.org'], $admin->contactPoints()->where('type', 'email')->pluck('value')->all());
            }
        }
        $double = Person::factory()->create(['first_name' => 'Role', 'last_name' => 'Administrator']);
        $double->contactPoints()->create(['type' => 'email', 'value' => 'attacker@example.org', 'purposes' => ['login']]);
        try {
            app(Duplicates::class)->merge($admin, $double);
            $this->fail('merged');
        } catch (RuntimeException) {
            $this->assertNotNull($double->fresh());
        }
        // Unchanged contacts do not get in the way of other changes, and the own address may be changed.
        Livewire::test(EditPerson::class, ['record' => $admin->id])->fillForm(['first_name' => 'New'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('New', $admin->refresh()->first_name);
        $board->person?->contactPoints()->firstOrFail()->update(['value' => 'mine@example.org']);
        $this->actingAs($this->user('administrator', 'second@example.org'));
        $admin->contactPoints()->firstOrFail()->update(['value' => 'new@example.org']);
        $this->assertSame('new@example.org', $admin->contactPoints()->where('type', 'email')->value('value'));
    }

    public function test_only_administrators_assign_roles_and_one_always_remains(): void
    {
        $admin = $this->user('administrator');
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'Treasurer.New@example.org', 'is_primary' => true]);
        $this->actingAs($this->user('board'));
        $this->assertFalse(RolesRelationManager::canViewForRecord($person, EditPerson::class));
        try {
            $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
            $this->fail('assigned');
        } catch (AuthorizationException) {
            $this->assertSame([], $person->activeRoleKeys());
        }

        $this->actingAs($admin);
        Livewire::test(RolesRelationManager::class, ['ownerRecord' => $person, 'pageClass' => EditPerson::class])
            ->callTableAction('create', data: ['role_id' => Role::query()->where('key', 'treasurer')->value('id'), 'starts_on' => now()->toDateString()])->assertHasNoTableActionErrors();
        $this->assertSame(['treasurer'], $person->activeRoleKeys());
        $this->assertSame('treasurer.new@example.org', $person->user()->value('email'), 'gets a login');

        $own = RoleAssignment::query()->where('person_id', $admin->person_id)->sole();
        Livewire::test(RolesRelationManager::class, ['ownerRecord' => $admin->person, 'pageClass' => EditPerson::class])->callTableAction('delete', $own);
        $this->assertNotNull($own->fresh(), 'the last administrator stays');
        foreach ([['ends_on' => now()->subDay()], ['starts_on' => now()->addDay()], ['role_id' => Role::query()->where('key', 'board')->value('id')]] as $change) {
            try {
                $own->refresh()->update($change);
                $this->fail('changed');
            } catch (AuthorizationException) {
                $this->assertContains('administrator', $admin->person?->activeRoleKeys() ?? []);
            }
        }
        $this->user('administrator', 'second@example.org');
        $own->refresh()->delete();
        $this->assertNull($own->fresh());
    }

    public function test_permissions_of_a_role_can_be_changed(): void
    {
        $leader = $this->user('camp_leader');
        $this->assertFalse(Permissions::allows($leader, 'mailings', 'edit'));
        $this->actingAs($this->user('administrator'));
        $role = Role::query()->where('key', 'camp_leader')->firstOrFail();
        Livewire::test(ManageRoles::class)->callTableAction('edit', $role, data: ['name' => ['de' => 'Lagerleitung', 'en' => 'Camp leader'], 'is_admin' => true, 'scope' => 'all', 'permissions' => ['mailings' => 'edit', 'events' => 'view']])->assertHasNoTableActionErrors();
        $this->assertTrue(Permissions::allows($leader, 'mailings', 'edit'));
        $this->assertTrue(Permissions::allows($leader, 'events'));
        $this->assertFalse(Permissions::allows($leader, 'events', 'edit'));
        $admin = Role::query()->where('key', 'administrator')->firstOrFail();
        $admin->update(['permissions' => ['people' => 'view']]);
        $this->assertSame('edit', Permissions::ofRole($admin)['settings'], 'administrators always have everything');
        $this->assertSame([], Permissions::ofRole(Role::query()->where('key', 'counsellor')->firstOrFail()));
    }

    public function test_treasurer_changes_prices_but_not_the_event_and_invoice_data_but_not_the_system(): void
    {
        $event = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 5]);
        $p = Participation::query()->create(['event_id' => $event->id, 'person_id' => Person::factory()->create()->id, 'role_key' => 'child', 'status' => 'registered', 'registered_at' => now()]);
        Settings::set('public_url', 'https://real.example.org');
        $this->actingAs($this->user('treasurer'));
        Livewire::test(EditEvent::class, ['record' => $event->id])->fillForm(['title' => 'Hacked', 'cancellation_fee_cents' => '15'])->call('save')->assertHasNoFormErrors();
        $event->refresh();
        $this->assertSame(['Camp', 1500], [$event->title, $event->cancellation_fee_cents]);
        $this->assertSame(1, $event->roles()->count());
        Livewire::test(ParticipationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->assertTableActionHidden('cancel', $p)->assertTableActionHidden('edit', $p)->assertTableActionVisible('price', $p);
        Livewire::test(OrganisationSettingsPage::class)->set('data.public_url', 'https://attacker.example.org')->set('data.ai_processing', 'all')->fillForm(['organisation_name' => 'Our camp'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['https://real.example.org', 'Our camp', 'none'], [Settings::get('public_url'), Settings::get('organisation_name'), Settings::get('ai_processing')]);
    }

    public function test_view_permission_allows_no_actions(): void
    {
        Role::query()->where('key', 'auditor')->firstOrFail()->update(['permissions' => ['mailings' => 'view', 'automations' => 'view', 'people' => 'view']]);
        $mail = OutgoingMail::query()->create(['to_address' => 'a@example.org', 'subject' => 'S', 'html' => 'x', 'text' => 'x', 'status' => 'sent']);
        $batch = ImportBatch::query()->create(['source' => 's', 'kind' => 'people', 'status' => 'imported']);
        $automation = Automation::query()->where('key', 'payment_reminders')->firstOrFail();
        $this->actingAs($this->user('auditor'));
        Livewire::test(ListOutgoingMails::class)->assertTableActionHidden('resend', $mail);
        Livewire::test(ListAutomations::class)->assertTableActionHidden('run_now', $automation);
        Livewire::test(ListImportBatches::class)->assertTableActionHidden('rollback', $batch);
        $this->actingAs($this->user('administrator'));
        Livewire::test(ListOutgoingMails::class)->assertTableActionVisible('resend', $mail);
        Livewire::test(ListAutomations::class)->assertTableActionVisible('run_now', $automation);
        Livewire::test(ListImportBatches::class)->assertTableActionVisible('rollback', $batch);
    }
}
