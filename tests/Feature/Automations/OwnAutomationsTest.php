<?php

namespace Tests\Feature\Automations;

use App\Filament\Pages\SeasonWizardPage;
use App\Filament\Resources\Automations\Pages\CreateAutomation;
use App\Filament\Resources\Automations\Pages\ListAutomations;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\NotificationEvent;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Auth\Permissions;
use App\Support\Automations\Engine;
use App\Support\Automations\Schedule;
use App\Support\Automations\Triggers;
use App\Support\Events\SeasonWizard;
use App\Support\Install\Defaults;
use App\Support\People\GroupMembers;
use App\Support\Reports\DashboardData;
use App\Support\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Own automations, change triggers, several approvals, schedule and season wizard (AT01, X09, RO03, EC02, AD05, AD32).
 */
class OwnAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        Automation::query()->update(['active' => false]);
        $series = EventSeries::query()->create(['name' => 'Summer', 'type' => 'camp']);
        $this->event = Event::query()->create(['title' => 'Camp', 'event_series_id' => $series->id, 'status' => 'open', 'starts_at' => now()->addDays(60), 'ends_at' => now()->addDays(67)]);
        $this->event->roles()->create(['role_key' => 'child', 'capacity' => 10]);
    }

    private function child(string $name, int $age = 10): Person
    {
        $child = Person::factory()->create(['first_name' => $name, 'last_name' => 'Example', 'birth_date' => now()->subYears($age)->subDays(5), 'locale' => 'en']);
        $guardian = Person::factory()->create(['locale' => 'en', 'birth_date' => '1980-01-01']);
        $guardian->contactPoints()->create(['type' => 'email', 'value' => strtolower($name).'@example.org', 'is_primary' => true]);
        $child->guardians()->attach($guardian->id);

        return $child;
    }

    private function join(Person $person, string $status = 'registered'): Participation
    {
        return Participation::query()->create(['event_id' => $this->event->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => $status, 'registered_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $trigger
     */
    private function own(array $config, string $name = 'manual', array $trigger = [], string $mode = 'automatic'): Automation
    {
        return Automation::query()->create(['key' => 'own_'.uniqid(), 'name' => ['de' => 'Eigene', 'en' => 'Own'], 'action' => 'custom', 'config' => $config, 'trigger' => $name, 'trigger_config' => $trigger, 'mode' => $mode, 'active' => true]);
    }

    private function user(string $role, string $name): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $name.'@example.org', 'is_primary' => true, 'verified_at' => now()]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->firstOrCreate(['email' => $name.'@example.org'], ['name' => $name, 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_status_change_runs_the_steps_for_the_person(): void
    {
        $group = Group::query()->create(['name' => 'Confirmed', 'kind' => 'manual']);
        Webhook::query()->create(['name' => 'x', 'url' => 'https://example.org/hook', 'secret' => 's', 'events' => ['automation.custom'], 'active' => true]);
        Webhooks::flush();
        $this->own(['target' => ['source' => 'subject'], 'steps' => [
            ['type' => 'add_group', 'group_id' => $group->id], ['type' => 'send_mail', 'template' => 'event_information', 'to' => 'guardians'],
            ['type' => 'notify_team', 'text' => 'Call the family'], ['type' => 'webhook', 'name' => 'confirmed'],
        ]], 'on_status', ['statuses' => ['confirmed']]);
        $kim = $this->child('Kim');
        $other = $this->child('Other');
        $p = $this->join($kim);
        $this->join($other);
        $this->assertSame(0, AutomationRun::query()->count());
        $p->update(['status' => 'confirmed']);

        $run = AutomationRun::query()->sole();
        $this->assertSame(['done', $kim->id], [$run->status, $run->context['person_id'] ?? null]);
        $this->assertSame([$kim->id], $group->people()->pluck('people.id')->all());
        $this->assertSame('kim@example.org', OutgoingMail::query()->where('subject', 'like', 'Information about Camp%')->sole()->to_address);
        $this->assertStringContainsString('Call the family', (string) NotificationEvent::query()->where('topic', 'tasks')->sole()->summary);
        $this->assertSame('automation.custom', WebhookDelivery::query()->sole()->event);
        Webhooks::flush();
    }

    public function test_group_and_field_triggers_conditions_and_once(): void
    {
        $from = Group::query()->create(['name' => 'From', 'kind' => 'manual']);
        $to = Group::query()->create(['name' => 'To', 'kind' => 'manual']);
        $this->own(['target' => ['source' => 'subject'], 'conditions' => ['match' => 'all', 'rules' => [['field' => 'age', 'min' => 12]]], 'steps' => [['type' => 'add_group', 'group_id' => $to->id]]], 'on_group_joined', ['group' => $from->id]);
        $young = $this->child('Young', 9);
        $old = $this->child('Old', 14);
        GroupMembers::join($from, $young->id, 'manual');
        GroupMembers::join($from, $old->id, 'manual');
        $this->assertSame([$old->id], $to->people()->pluck('people.id')->all());

        $own = $this->own(['target' => ['source' => 'subject'], 'once' => true, 'steps' => [['type' => 'notify_team', 'text' => 'Changed']]], 'on_person_changed', ['fields' => ['locale']]);
        $old->update(['locale' => 'de']);
        $old->update(['locale' => 'en']);
        $old->update(['last_name' => 'Other']);
        $this->assertSame(1, $own->runs()->count(), 'once per person, and only for the chosen field');
    }

    public function test_automations_never_run_in_circles_and_never_stop_a_change(): void
    {
        $a = Group::query()->create(['name' => 'A', 'kind' => 'manual']);
        $b = Group::query()->create(['name' => 'B', 'kind' => 'manual']);
        $move = fn (Group $from, Group $to) => $this->own(['target' => ['source' => 'subject'], 'steps' => [['type' => 'remove_group', 'group_id' => $from->id], ['type' => 'add_group', 'group_id' => $to->id]]], 'on_group_joined', ['group' => $from->id]);
        $move($a, $b);
        $move($b, $a);
        $person = $this->child('Kim');
        GroupMembers::join($a, $person->id, 'manual');
        $this->assertLessThanOrEqual(4, AutomationRun::query()->count());

        Automation::query()->create(['key' => 'broken', 'name' => ['en' => 'Broken'], 'action' => 'unknown', 'config' => [], 'trigger' => 'on_status', 'trigger_config' => ['statuses' => ['confirmed']], 'mode' => 'automatic', 'active' => true]);
        $p = $this->join($person);
        $p->update(['status' => 'confirmed']);
        $this->assertSame('confirmed', $p->fresh()?->status);
    }

    public function test_nothing_is_triggered_by_imports(): void
    {
        $own = $this->own(['target' => ['source' => 'subject'], 'steps' => [['type' => 'notify_team', 'text' => 'New']]], 'on_person_created');
        Triggers::muted(fn () => Person::factory()->create());
        $this->assertSame(0, $own->runs()->count());
        Person::factory()->create();
        $this->assertSame(1, $own->runs()->count());
    }

    public function test_targets_of_event_group_and_rules(): void
    {
        $child = $this->child('Kim');
        $this->join($child, 'confirmed');
        $this->join($this->child('Waiting'), 'waitlist');
        $group = Group::query()->create(['name' => 'G', 'kind' => 'manual']);
        GroupMembers::join($group, $child->id, 'manual');
        $steps = [['type' => 'notify_team', 'text' => 'x']];
        $names = fn (array $config, ?Event $event = null) => array_column(app(Engine::class)->preview($this->own($config + ['steps' => $steps]), $event), 'label');
        $this->assertSame(['Kim Example'], $names(['target' => ['source' => 'event', 'statuses' => ['confirmed']]], $this->event));
        $this->assertSame(['Kim Example'], $names(['target' => ['source' => 'group', 'group_id' => $group->id]]));
        $this->assertSame(['Kim Example'], $names(['target' => ['source' => 'people'], 'conditions' => ['match' => 'all', 'rules' => [['field' => 'group', 'value' => $group->id]]]]));
        $this->assertSame([], $names(['target' => ['source' => 'people']]), 'no rules does not mean everybody');
        $this->assertSame([], $names(['target' => ['source' => 'subject']]), 'no person');
        $this->assertTrue(Engine::needsEvent($this->own(['target' => ['source' => 'event']])));
    }

    public function test_invoice_step_makes_a_draft_and_status_step_respects_the_rules(): void
    {
        $child = $this->child('Kim');
        $waiting = $this->join($this->child('Waiting'), 'waitlist');
        $in = $this->join($child);
        $own = $this->own(['target' => ['source' => 'event', 'statuses' => ['registered', 'waitlist']], 'steps' => [['type' => 'set_status', 'status' => 'confirmed'], ['type' => 'invoice', 'description' => 'Excursion', 'amount' => '12.50']]]);
        app(Engine::class)->trigger($own, 'manual', $this->event, null, $this->user('administrator', 'admin'));
        $this->assertSame(['confirmed', 'waitlist'], [$in->fresh()?->status, $waiting->fresh()?->status]);
        $invoice = Invoice::query()->whereHas('lines', fn ($q) => $q->where('person_id', $child->id))->sole();
        $this->assertSame(['draft', 1250], [$invoice->status, $invoice->total_cents]);
    }

    public function test_weekly_monthly_and_once(): void
    {
        $schedule = app(Schedule::class);
        $this->travelTo(now()->parse('2027-03-01 07:00')); // a Monday
        $weekly = $this->own([], 'weekly', ['weekday' => 3, 'time' => '09:00']);
        $monthly = $this->own([], 'monthly', ['day' => 31, 'time' => '09:00']);
        $once = $this->own([], 'once', ['date' => '2027-03-05', 'time' => '09:00']);
        $this->assertSame(['2027-03-03 09:00', '2027-03-31 09:00', '2027-03-05 09:00'], array_map(fn (Automation $a) => $schedule->next($a)?->format('Y-m-d H:i'), [$weekly, $monthly, $once]));
        $engine = app(Engine::class);
        $this->assertSame([], $engine->due($weekly));
        $this->travelTo(now()->parse('2027-03-03 10:00'));
        $this->assertCount(1, $engine->due($weekly));
        $this->travelTo(now()->parse('2027-04-30 10:00'));
        $this->assertCount(1, $engine->due($monthly), 'the last day of a shorter month');
        $this->assertNull($schedule->next($once));
    }

    public function test_several_approvals_are_needed_and_the_others_are_informed(): void
    {
        $one = $this->user('board', 'one');
        $two = $this->user('board', 'two');
        $three = $this->user('board', 'three');
        $this->join($this->child('Kim'));
        $this->join($this->child('Lou'));
        $own = $this->own(['target' => ['source' => 'event'], 'steps' => [['type' => 'notify_team', 'text' => 'x']]], 'before_event', ['days' => 90], 'confirm');
        $own->update(['approvals_needed' => 2, 'approver_roles' => ['board']]);
        $engine = app(Engine::class);
        $run = $engine->trigger($own, 'before_event', $this->event, 'event:1');
        $this->assertNotNull($run);
        $keys = array_column($run->plan, 'key');

        $engine->decide($run, $one, true, [$keys[0]]);
        $this->assertSame('pending_approval', $run->refresh()->status);
        try {
            $engine->decide($run, $one, true);
            $this->fail('approved twice by the same person');
        } catch (RuntimeException) {
            $this->assertSame(1, $run->approvals()->count());
        }
        $engine->decide($run, $two, true);
        $this->assertSame('done', $run->refresh()->status);
        $this->assertSame([$keys[0]], $run->excluded, 'what one approver left out stays out');
        $this->assertSame(1, OutgoingMail::query()->where('to_address', $three->email)->where('subject', 'like', 'Decided:%')->count());
        $this->assertSame(0, OutgoingMail::query()->where('to_address', $two->email)->where('subject', 'like', 'Decided:%')->count());

        $second = $engine->trigger($own, 'before_event', $this->event, 'event:2');
        $this->assertNotNull($second);
        $engine->decide($second, $three, false);
        $this->assertSame('denied', $second->refresh()->status, 'one denial is enough');

        $third = $engine->trigger($own, 'manual', $this->event, null, $one);
        $this->assertNotNull($third);
        $this->assertSame(['pending_approval', 1], [$third->status, $third->approvals()->count()], 'the button is the first approval');
    }

    public function test_schedule_of_an_event_and_next_runs(): void
    {
        $this->event->update(['registration_closes_at' => now()->addDays(20)]);
        $this->own(['target' => ['source' => 'event'], 'steps' => [['type' => 'notify_team', 'text' => 'x']]], 'before_event', ['days' => 50], 'confirm');
        $items = app(Schedule::class)->ofEvent($this->event->refresh());
        $own = array_values(array_filter($items, fn (array $i) => $i['what'] === 'Own'));
        $this->assertSame('planned', $own[0]['state'] ?? null);
        $this->assertTrue($this->event->starts_at->copy()->subDays(50)->isSameDay($own[0]['at']));
        $this->assertSame(['Own'], array_column(array_filter(app(DashboardData::class)->automations()['upcoming'], fn (array $u) => $u['event'] === 'Camp'), 'name'));
        $leader = $this->user('camp_leader', 'leader');
        $leader->person?->roleAssignments()->toBase()->update(['event_id' => $this->event->id]);
        $this->actingAs($leader)->withSession(['two_factor_passed' => true])->get('/admin/events/'.$this->event->id.'/schedule')->assertOk()->assertSee('Registration closes')->assertSee('Own');
    }

    public function test_season_wizard_shows_everything_and_changes_nothing(): void
    {
        $this->event->update(['starts_at' => now()->subDays(300), 'ends_at' => now()->subDays(293), 'status' => 'finished']);
        $this->join($this->child('Kim', 9), 'attended');
        $wizard = app(SeasonWizard::class);
        $before = [Event::query()->count(), Participation::query()->count(), OutgoingMail::query()->count()];
        $preview = $wizard->preview($this->event, 'Camp next', now()->addDays(64));
        $this->assertSame(['Camp next', 1], [$preview['title'], count($preview['people'])]);
        $this->assertSame($before, [Event::query()->count(), Participation::query()->count(), OutgoingMail::query()->count()]);

        $this->actingAs($this->user('administrator', 'admin'))->withSession(['two_factor_passed' => true]);
        Livewire::test(SeasonWizardPage::class, ['source' => $this->event->id])->set('source', $this->event->id)->set('newTitle', 'Camp next')->set('startsOn', now()->addDays(64)->toDateString())
            ->call('check')->assertSee('Nothing has been created yet')->assertSee('Kim Example')->call('create');
        $copy = Event::query()->where('title', 'Camp next')->sole();
        $this->assertSame(['draft', 0], [$copy->status, $copy->participations()->count()]);
        $this->actingAs($this->user('camp_leader', 'leader2'));
        auth()->user()?->refresh();
        $this->assertSame(SeasonWizardPage::canAccess(), Permissions::current('events', 'edit'));
    }

    public function test_own_automations_are_created_switched_off_and_only_they_can_be_deleted(): void
    {
        $this->actingAs($this->user('administrator', 'admin'))->withSession(['two_factor_passed' => true]);
        $group = Group::query()->create(['name' => 'G', 'kind' => 'manual']);
        $form = ['name' => ['de' => 'Neu', 'en' => 'New'], 'mode' => 'confirm', 'max_items' => 50, 'approvals_needed' => 1, 'trigger' => 'on_status', 'trigger_config' => ['statuses' => ['confirmed']], 'config' => ['target' => ['source' => 'event'], 'steps' => [['type' => 'add_group', 'group_id' => $group->id]]], 'active' => true];
        Livewire::test(CreateAutomation::class)->fillForm($form)->call('create')->assertHasFormErrors(['config.target.source']);
        $form['config']['target']['source'] = 'subject';
        Livewire::test(CreateAutomation::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
        $own = Automation::query()->where('key', 'like', 'own_%')->sole();
        $this->assertSame(['custom', false, 'add_group', ['confirmed']], [$own->action, $own->active, $own->config['steps'][0]['type'] ?? null, array_values((array) $own->triggerSetting('statuses'))]);
        $standard = Automation::query()->where('is_standard', true)->firstOrFail();
        Livewire::test(ListAutomations::class)->assertTableActionVisible('delete', $own)->assertTableActionHidden('delete', $standard);
    }

    public function test_invoice_steps_need_the_right_to_make_invoices(): void
    {
        $this->actingAs($this->user('board', 'board'))->withSession(['two_factor_passed' => true]);
        Role::query()->where('key', 'board')->firstOrFail()->update(['permissions' => ['automations' => 'edit']]);
        Livewire::test(CreateAutomation::class)->fillForm(['name' => ['de' => 'Neu', 'en' => 'New'], 'mode' => 'confirm', 'max_items' => 50, 'approvals_needed' => 1, 'trigger' => 'manual', 'config' => ['target' => ['source' => 'event'], 'steps' => [['type' => 'invoice', 'description' => 'x', 'amount' => '1']]]])
            ->call('create')->assertHasFormErrors();
        $this->assertSame(0, Automation::query()->where('key', 'like', 'own_%')->count());
    }
}
