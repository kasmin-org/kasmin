<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('organisation_name', 'Example Camp');
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $this->event = Event::query()->create(['title' => 'Summer camp 2027', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 1, 'min_age' => 8, 'max_age' => 13, 'price_cents' => 40000]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'discounts_apply' => false]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes): string
    {
        return ApiToken::issue($this->user, 'test', $scopes)['plain'];
    }

    public function test_authentication_and_scopes(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken('kas_wrong')->getJson('/api/v1/me')->assertUnauthorized();
        $token = $this->token(['people:read']);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.scopes', ['people:read']);
        $this->withToken($token)->getJson('/api/v1/events')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/people', ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();

        $revoked = ApiToken::issue($this->user, 'old', ['people:read']);
        $revoked['token']->forceFill(['revoked_at' => now()])->save();
        $this->withToken($revoked['plain'])->getJson('/api/v1/me')->assertUnauthorized();

        $this->user->person?->roleAssignments()->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized(); // owner lost the administrator role
    }

    public function test_people_create_register_and_audit_actor(): void
    {
        $token = $this->token(['people:read', 'people:write', 'events:read', 'events:write']);
        $created = $this->withToken($token)->postJson('/api/v1/people', [
            'first_name' => 'Kim', 'last_name' => 'Zzqbrook', 'birth_date' => now()->subYears(10)->toDateString(),
            'guardian1_first_name' => 'Chris', 'guardian1_email' => 'chris@example.org',
        ])->assertCreated()->assertJsonPath('data.first_name', 'Kim')->assertJsonPath('data.legal_guardians.0.name', 'Chris Zzqbrook');
        $kim = (string) $created->json('data.id');

        $this->withToken($token)->getJson('/api/v1/people?search=Zzqbrook')->assertOk()->assertJsonPath('meta.total', 2);
        $this->withToken($token)->patchJson('/api/v1/people/'.$kim, ['gender' => 'diverse'])->assertOk()->assertJsonPath('data.gender', 'diverse');

        $event = $this->event->public_id;
        $this->withToken($token)->postJson("/api/v1/events/{$event}/registrations", ['person' => $kim, 'role' => 'child'])->assertCreated()->assertJsonPath('data.status', 'registered');
        $this->withToken($token)->getJson("/api/v1/events/{$event}")->assertOk()->assertJsonPath('data.roles.0.occupied', 1)->assertJsonPath('data.roles.0.bookable_left', 0);
        $this->withToken($token)->postJson("/api/v1/events/{$event}/registrations", ['person' => $kim, 'role' => 'child'])->assertUnprocessable();

        $audit = AuditEvent::query()->where('action', 'like', 'participation.%')->orWhere('action', 'like', 'person.%')->latest('id')->firstOrFail();
        $this->assertSame('api', $audit->actor_type);
        $this->assertSame($this->user->id, $audit->meta['on_behalf_of'] ?? null);
    }

    public function test_import_preview_import_and_rollback(): void
    {
        $token = $this->token(['import:write']);
        $rows = [['source_id' => '1', 'first_name' => 'Ann', 'last_name' => 'Ash', 'guardian1_first_name' => 'Pat', 'guardian1_email' => 'pat@example.org'],
            ['source_id' => '2', 'first_name' => 'Bo', 'last_name' => 'Ash', 'guardian1_first_name' => 'Pat', 'guardian1_email' => 'pat@example.org']];
        $this->withToken($token)->postJson('/api/v1/imports/people/preview', ['source' => 'old-system', 'rows' => $rows])
            ->assertOk()->assertJsonPath('data.summary.create', 2)->assertJsonPath('data.families.0.confidence', 'high');
        $this->assertSame(1, Person::query()->count(), 'dry run');

        $batch = (int) $this->withToken($token)->postJson('/api/v1/imports/people', ['source' => 'old-system', 'rows' => $rows])->assertCreated()->json('data.batch');
        $this->assertSame(4, Person::query()->count());
        $this->withToken($token)->postJson('/api/v1/imports/people', ['source' => 'old-system', 'rows' => $rows])->assertJsonPath('data.stats.created', 0);
        $this->travel(1)->minutes();
        $this->withToken($token)->postJson("/api/v1/imports/{$batch}/rollback")->assertOk();
        $this->assertSame(1, Person::query()->count());
        $this->withToken($token)->postJson('/api/v1/imports/people', ['source' => 'Bad Source!', 'rows' => $rows])->assertUnprocessable();
    }

    public function test_automation_runs_via_api_wait_for_approval_unless_allowed(): void
    {
        $child = Person::factory()->create(['birth_date' => now()->subYears(10)->toDateString()]);
        app(Registrations::class)->register($this->event, $child, 'child');
        Automation::query()->where('key', 'deposit_invoices')->update(['active' => true]);

        $runner = $this->token(['automations:read', 'automations:run']);
        $this->withToken($runner)->postJson('/api/v1/automations/deposit_invoices/preview', ['event' => $this->event->public_id])->assertOk()->assertJsonCount(1, 'data');
        $run = $this->withToken($runner)->postJson('/api/v1/automations/deposit_invoices/run', ['event' => $this->event->public_id])
            ->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->assertSame(0, Invoice::query()->count(), 'an approver token without approvals:decide still needs a human decision');
        $this->withToken($runner)->postJson("/api/v1/automation-runs/{$run}/decision", ['decision' => 'approve'])->assertForbidden();

        $decider = $this->token(['automations:read', 'approvals:decide']);
        $this->withToken($decider)->getJson('/api/v1/automation-runs')->assertOk()->assertJsonPath('data.0.id', $run);
        $this->withToken($decider)->postJson("/api/v1/automation-runs/{$run}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(1, Invoice::query()->where('status', 'issued')->count());
        $this->assertSame('done', AutomationRun::query()->whereKey((int) $run)->value('status'));

        $finance = $this->token(['finance:read', 'finance:write']);
        $invoice = Invoice::query()->firstOrFail();
        $this->withToken($finance)->getJson('/api/v1/invoices?status=issued')->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($finance)->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => 100, 'paid_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->withToken($finance)->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_pages_for_tokens_webhooks_and_imports(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
        foreach (['/admin/api-tokens', '/admin/webhooks', '/admin/webhooks/create', '/admin/import/people', '/admin/import-batches', '/admin/settings/organisation'] as $page) {
            $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get($page)->assertOk();
        }
        $boardPerson = Person::factory()->create();
        $boardPerson->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $board = User::query()->create(['name' => 'Board', 'email' => 'board@example.org', 'password' => 'x', 'person_id' => $boardPerson->id]);
        $this->actingAs($board)->withSession(['two_factor_passed' => true])->get('/admin/api-tokens')->assertForbidden();

        $this->actingAs($admin);
        Livewire::test(ListApiTokens::class)
            ->callAction('create', data: ['name' => 'Website', 'scopes' => ['events:read'], 'mcp' => false, 'expires_at' => now()->addMonth()->toDateString()])
            ->assertHasNoActionErrors()->assertNotified();
        $token = ApiToken::query()->where('name', 'Website')->firstOrFail();
        $this->assertSame(['events:read'], $token->scopes);
        $this->assertSame($admin->id, $token->user_id);
    }

    public function test_audit_needs_its_own_scope(): void
    {
        $this->withToken($this->token(['people:read']))->getJson('/api/v1/audit')->assertForbidden();
        $this->withToken($this->token(['audit:read']))->getJson('/api/v1/audit?action=api.')->assertOk();
    }
}
