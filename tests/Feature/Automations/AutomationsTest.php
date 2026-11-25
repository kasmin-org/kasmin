<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\Approvals;
use App\Support\Automations\Engine;
use App\Support\Events\Registrations;
use App\Support\Install\AutomationDefaults;
use App\Support\Install\Defaults;
use App\Support\Install\FormDefaults;
use App\Support\Install\MailTemplateDefaults;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private User $board;

    protected function setUp(): void
    {
        parent::setUp();
        // Daily automations start after their configured time: tests run at a fixed time of day.
        $this->travelTo(now()->setTime(12, 0));
        app(Defaults::class)->installRoles();
        app(MailTemplateDefaults::class)->install();
        app(FormDefaults::class)->install();
        app(AutomationDefaults::class)->install();
        app()->setLocale('en');
        Settings::set('organisation_name', 'Example Camp');

        $this->event = Event::query()->create(['title' => 'Summer camp 2027', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 2, 'price_cents' => 40000, 'counts_as_sibling' => true]);
        $this->event->installments()->create(['key' => 'deposit', 'label' => ['en' => 'Deposit', 'de' => 'Anzahlung'], 'kind' => 'fixed', 'amount_cents' => 10000, 'due_days' => 14, 'discounts_apply' => false]);

        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $this->board = User::query()->create(['name' => 'Board Member', 'email' => 'board@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function family(string $name): Participation
    {
        $household = Household::query()->create(['name' => $name]);
        $guardian = Person::factory()->create(['first_name' => 'G', 'last_name' => $name, 'birth_date' => '1980-01-01', 'locale' => 'en']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => Str::lower($name).'@example.org', 'is_primary' => true]);
        $household->members()->attach($guardian->id, ['role' => 'guardian']);
        $child = Person::factory()->create(['first_name' => 'Kid', 'last_name' => $name, 'birth_date' => now()->subYears(10)->toDateString()]);
        $child->guardians()->attach($guardian->id);
        $household->members()->attach($child->id, ['role' => 'child']);

        return app(Registrations::class)->register($this->event, $child, 'child', $guardian);
    }

    private function automation(string $key): Automation
    {
        $a = Automation::query()->where('key', $key)->firstOrFail();
        $a->update(['active' => true]);

        return $a;
    }

    public function test_cancellation_offers_the_place_after_approval_by_mail_link(): void
    {
        $this->automation('waitlist_offers');
        $a = $this->family('Alpha');
        $this->family('Beta');
        $waiting = $this->family('Gamma');
        $this->assertSame('waitlist', $waiting->status);

        app(Registrations::class)->cancel($a->load('event'));
        $run = AutomationRun::query()->firstOrFail();
        $this->assertSame('pending_approval', $run->status);
        $this->assertCount(1, $run->plan);
        $this->assertSame('waitlist', $waiting->refresh()->status, 'nothing happens before the approval');
        $request = OutgoingMail::query()->where('to_address', 'board@example.org')->firstOrFail();
        $this->assertStringContainsString('Approval needed', $request->subject);

        // A link opens a page; GET does not decide.
        $this->assertSame(1, preg_match('#/approvals/([A-Za-z0-9]+)#', $request->html, $m));
        $token = $m[1] ?? '';
        $this->get('/approvals/'.$token)->assertOk()->assertSee('Yes, run it')->assertSee('Kid Gamma');
        $this->assertSame('pending_approval', $run->refresh()->status);

        $this->post('/approvals/'.$token, ['decision' => 'approve'])->assertRedirect('/approvals/done');
        $offered = Participation::query()->findOrFail($waiting->id);
        $this->assertSame('offered', $offered->status);
        $this->assertNotNull($offered->offer_expires_at);
        $this->assertSame('done', $run->refresh()->status);
        $this->assertSame($this->board->id, $run->refresh()->decided_by_id);
        $this->assertTrue(OutgoingMail::query()->where('to_address', 'gamma@example.org')->where('subject', 'like', 'A place is free%')->exists());

        // The link works only once.
        $this->get('/approvals/'.$token)->assertSee('Link no longer valid');
    }

    public function test_guardian_accepts_or_offer_expires_and_moves_on(): void
    {
        $this->automation('waitlist_offers')->update(['mode' => 'automatic']);
        $this->automation('offer_expiry');
        $a = $this->family('Alpha');
        $this->family('Beta');
        $c = $this->family('Gamma');
        $d = $this->family('Delta');

        app(Registrations::class)->cancel($a->load('event'));
        $this->assertSame('offered', $c->refresh()->status, 'automatic mode runs without approval');

        $this->travel(8)->days();
        app(Engine::class)->tick();
        $this->assertSame('declined', $c->refresh()->status);
        $this->assertSame('offered', $d->refresh()->status, 'the freed place goes to the next one');

        app(Registrations::class)->answerOffer(Participation::query()->findOrFail($d->id), true);
        $this->assertSame('registered', $d->refresh()->status);
    }

    public function test_portal_offer_buttons(): void
    {
        $this->automation('waitlist_offers')->update(['mode' => 'automatic']);
        $a = $this->family('Alpha');
        $this->family('Beta');
        $c = $this->family('Gamma');
        app(Registrations::class)->cancel($a->load('event'));
        $child = Person::query()->findOrFail($c->person_id);
        $guardian = $child->guardians()->firstOrFail();
        $user = User::query()->create(['name' => 'G', 'email' => 'gamma@example.org', 'password' => 'x', 'person_id' => $guardian->id]);

        $this->actingAs($user)->get('/portal/children/'.$child->public_id)->assertSee('A place has become free!');
        $this->post('/portal/children/'.$child->public_id.'/participations/'.$c->id.'/offer', ['answer' => 'decline'])->assertRedirect();
        $this->assertSame('declined', $c->refresh()->status);
    }

    public function test_dry_run_changes_nothing_and_manual_run_by_approver_executes(): void
    {
        $this->family('Alpha');
        $this->family('Beta');
        $automation = $this->automation('deposit_invoices');
        $engine = app(Engine::class);

        $this->assertSame([], $engine->preview($automation), 'needs an event');
        $plan = $engine->preview($automation, $this->event);
        $this->assertCount(2, $plan);
        $this->assertSame(0, Invoice::query()->count());

        $pending = $engine->trigger($automation, 'scheduled', $this->event);
        $this->assertSame('pending_approval', $pending?->status, 'confirm mode never runs without a decision');
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull($engine->trigger($automation, 'scheduled', $this->event), 'no second request while one is open');

        $run = $engine->trigger($automation, 'manual', $this->event, null, $this->board);
        $this->assertSame('done', $run?->status, 'an approver pressing the button decides directly');
        $this->assertSame('expired', $pending->refresh()->status, 'the open request was replaced');
        $this->assertSame(2, Invoice::query()->where('status', 'issued')->count());
    }

    public function test_approve_with_changes_and_deny(): void
    {
        $this->family('Alpha');
        $this->family('Beta');
        $automation = $this->automation('deposit_invoices');
        $engine = app(Engine::class);

        $run = $engine->trigger($automation, 'scheduled', $this->event, 'x');
        $this->assertNotNull($run);
        $first = $run->plan[0]['key'];
        $engine->decide($run, $this->board, true, [$first]);
        $this->assertSame(1, Invoice::query()->where('status', 'issued')->count());
        $this->assertSame([$first], $run->refresh()->excluded);

        $again = $engine->trigger($automation, 'scheduled', $this->event, 'y');
        $this->assertNotNull($again);
        $engine->decide($again, $this->board, false, [], 'not now');
        $this->assertSame(['denied', 'not now'], [$again->refresh()->status, $again->refresh()->comment]);
        $this->assertSame(1, Invoice::query()->count());

        $outsider = User::query()->create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $third = $engine->trigger($automation, 'scheduled', $this->event, 'z');
        $this->assertNotNull($third);
        $this->expectException(\RuntimeException::class);
        $engine->decide($third, $outsider, true);
    }

    public function test_mail_link_is_invalid_once_someone_decided_elsewhere(): void
    {
        $this->family('Alpha');
        $run = app(Engine::class)->trigger($this->automation('deposit_invoices'), 'scheduled', $this->event, 'x');
        $this->assertNotNull($run);
        $html = (string) OutgoingMail::query()->where('to_address', 'board@example.org')->latest('id')->value('html');
        $this->assertSame(1, preg_match('#/approvals/([A-Za-z0-9]+)#', $html, $m));
        $token = $m[1] ?? '';

        app(Engine::class)->decide($run, $this->board, false, [], 'decided in the inbox');
        $this->get('/approvals/'.$token)->assertOk()->assertSee('Link no longer valid')->assertDontSee('Yes, run it');
        $this->post('/approvals/'.$token, ['decision' => 'approve'])->assertRedirect('/approvals/'.$token);
        $this->assertSame('denied', $run->refresh()->status);
    }

    public function test_pending_requests_expire_and_are_reminded(): void
    {
        $this->family('Alpha');
        $automation = $this->automation('deposit_invoices');
        $run = app(Engine::class)->trigger($automation, 'scheduled', $this->event, 'x');
        $this->assertNotNull($run);
        $mails = OutgoingMail::query()->count();

        $this->travel(4)->days();
        app(Approvals::class)->housekeeping();
        $this->assertNotNull($run->refresh()->reminded_at);
        $this->assertGreaterThan($mails, OutgoingMail::query()->count());

        $this->travel(4)->days();
        app(Approvals::class)->housekeeping();
        $this->assertSame('expired', $run->refresh()->status);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_scheduled_reminders_respect_interval_and_maximum(): void
    {
        $this->family('Alpha');
        $automation = $this->automation('safety_sheet_reminders');
        $automation->update(['mode' => 'automatic', 'config' => ['form' => 'safety_sheet', 'interval_days' => 7, 'max' => 2, 'window_days' => 120]]);
        $this->assertNotNull(Form::query()->where('key', 'safety_sheet')->first());
        $engine = app(Engine::class);
        $count = fn () => OutgoingMail::query()->where('subject', 'like', '%safety sheet%')->count();

        $this->travelTo(now()->setTime(19, 0));
        $engine->tick();
        $this->assertSame(1, $count());
        $engine->tick();
        $this->assertSame(1, $count(), 'once per day');
        $this->travel(1)->days();
        $engine->tick();
        $this->assertSame(1, $count(), 'interval of 7 days');
        $this->travel(7)->days();
        $engine->tick();
        $this->assertSame(2, $count());
        $this->travel(8)->days();
        $engine->tick();
        $this->assertSame(2, $count(), 'maximum reached');
    }

    public function test_before_event_trigger_runs_once_per_event(): void
    {
        $this->family('Alpha');
        $automation = $this->automation('event_information');
        $automation->update(['mode' => 'automatic', 'trigger_config' => ['days' => 14]]);
        $engine = app(Engine::class);

        $engine->tick();
        $this->assertSame(0, AutomationRun::query()->count(), 'too early');
        $this->travelTo($this->event->starts_at->copy()->subDays(10));
        $engine->tick();
        $engine->tick();
        $this->assertSame(1, AutomationRun::query()->count());
        $this->assertSame(1, OutgoingMail::query()->where('subject', 'like', 'Information about%')->count());
    }

    public function test_admin_pages(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $this->family('Alpha');
        $run = app(Engine::class)->trigger($this->automation('deposit_invoices'), 'scheduled', $this->event, 'x');
        $this->assertNotNull($run);
        $automation = Automation::query()->firstOrFail();
        foreach (['/admin/automations', '/admin/automations/'.$automation->id.'/edit', '/admin/automation-runs', '/admin/automation-runs/'.$run->id] as $page) {
            $this->actingAs($this->board)->withSession(['two_factor_passed' => true])->get($page)->assertOk();
        }
    }
}
