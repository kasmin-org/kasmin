<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Event;
use App\Models\MembershipType;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Events\Registrations;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AP01: what can be set in the admin interface is also available via REST – contact data, legal guardians,
 * membership types, automations and events.
 */
class ApiGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $person = Person::factory()->create(['locale' => 'en', 'birth_date' => '1980-01-01']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes): string
    {
        return ApiToken::issue($this->user, 'test', $scopes)['plain'];
    }

    private function child(): Person
    {
        return Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => now()->subYears(10)->toDateString()]);
    }

    public function test_every_new_endpoint_needs_its_scope(): void
    {
        $none = $this->token(['audit:read']);
        $p = $this->child()->public_id;
        $e = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addWeek()])->public_id;
        foreach ([['get', "/people/$p/contact-points"], ['post', "/people/$p/contact-points"], ['delete', "/people/$p/contact-points/1"],
            ['get', "/people/$p/guardians"], ['post', "/people/$p/guardians"], ['delete', "/people/$p/guardians/$p"],
            ['get', '/membership-types'], ['post', '/membership-types'], ['patch', '/membership-types/member'],
            ['get', '/automations/deposit_invoices'], ['patch', '/automations/deposit_invoices'], ['post', '/events'], ['patch', "/events/$e"]] as [$method, $path]) {
            $this->withToken($none)->json($method, '/api/v1'.$path)->assertForbidden();
        }
        // Reading is not enough to write, and running automations is not enough to change them.
        $read = $this->token(['people:read', 'events:read', 'automations:read', 'automations:run', 'approvals:decide']);
        foreach ([['post', "/people/$p/contact-points"], ['post', "/people/$p/guardians"], ['post', '/membership-types'], ['patch', '/automations/deposit_invoices'], ['post', '/events'], ['patch', "/events/$e"]] as [$method, $path]) {
            $this->withToken($read)->json($method, '/api/v1'.$path)->assertForbidden();
        }
    }

    public function test_contact_points(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $ann = $this->child();
        $url = "/api/v1/people/{$ann->public_id}/contact-points";
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        $first = $this->withToken($token)->postJson($url, ['type' => 'email', 'value' => ' Ann@Example.org ', 'purposes' => ['information']])
            ->assertCreated()->assertJsonPath('data.0.value', 'ann@example.org')->assertJsonPath('data.0.primary', true)->assertJsonPath('data.0.purposes', ['information'])->json('data.0.id');
        $this->withToken($token)->postJson($url, ['type' => 'email', 'value' => 'ash@example.org', 'is_primary' => true])
            ->assertCreated()->assertJsonPath('data.0.value', 'ash@example.org')->assertJsonPath('data.1.primary', false);
        $this->withToken($token)->postJson($url, ['type' => 'phone', 'value' => '+43 1 234567'])->assertCreated()->assertJsonCount(3, 'data');
        $this->withToken($token)->getJson("/api/v1/people/{$ann->public_id}")->assertOk()->assertJsonCount(3, 'data.contacts');

        $this->withToken($token)->postJson($url, ['type' => 'email', 'value' => 'no address'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->withToken($token)->postJson($url, ['type' => 'phone', 'value' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->withToken($token)->postJson($url, ['type' => 'fax', 'value' => '1'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->withToken($token)->postJson($url, ['type' => 'email', 'value' => 'ANN@example.org'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->withToken($token)->postJson($url, ['type' => 'email', 'value' => 'birch@example.org', 'purposes' => ['fun']])->assertUnprocessable();

        $this->withToken($token)->deleteJson("$url/$first")->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->deleteJson("$url/$first")->assertNotFound();
        $other = $this->child();
        $foreign = $other->contactPoints()->create(['type' => 'phone', 'value' => '123'])->getKey();
        $this->withToken($token)->deleteJson("$url/$foreign")->assertNotFound();

        $audit = AuditEvent::query()->where('action', 'ContactPoint.deleted')->latest('id')->firstOrFail();
        $this->assertSame('api', $audit->actor_type);
    }

    public function test_the_address_of_a_login_cannot_be_removed(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $birch = Person::factory()->create(['first_name' => 'Bo', 'last_name' => 'Birch', 'birth_date' => '1985-05-05']);
        $login = $birch->contactPoints()->create(['type' => 'email', 'value' => 'bo@example.org', 'purposes' => ['login'], 'is_primary' => true]);
        $second = $birch->contactPoints()->create(['type' => 'email', 'value' => 'birch@example.org']);
        User::query()->create(['name' => 'Bo Birch', 'email' => 'Bo@example.org', 'password' => 'x', 'person_id' => $birch->id]);
        $url = "/api/v1/people/{$birch->public_id}/contact-points";

        $this->withToken($token)->deleteJson("$url/{$login->getKey()}")->assertUnprocessable();
        $this->assertTrue($birch->contactPoints()->whereKey($login->getKey())->exists());
        $this->withToken($token)->deleteJson("$url/{$second->getKey()}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guardians_and_the_highest_number(): void
    {
        Settings::set('max_guardians', '2');
        $token = $this->token(['people:read', 'people:write']);
        $ann = $this->child();
        [$one, $two, $three] = [Person::factory()->create(['birth_date' => '1980-02-02']), Person::factory()->create(['birth_date' => '1981-02-02']), Person::factory()->create(['birth_date' => '1982-02-02'])];
        $url = "/api/v1/people/{$ann->public_id}/guardians";

        $this->withToken($token)->postJson($url, ['guardian' => $one->public_id])->assertCreated()->assertJsonPath('data.0.id', $one->public_id);
        $this->withToken($token)->postJson($url, ['guardian' => $one->public_id])->assertOk()->assertJsonCount(1, 'data'); // already a guardian
        $this->withToken($token)->postJson($url, ['guardian' => $two->public_id])->assertCreated()->assertJsonCount(2, 'data');
        $this->withToken($token)->postJson($url, ['guardian' => $three->public_id])->assertUnprocessable();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("/api/v1/people/{$one->public_id}")->assertJsonPath('data.children.0.id', $ann->public_id);
        $this->assertSame('api', AuditEvent::query()->where('action', 'guardian.added')->latest('id')->firstOrFail()->actor_type);

        $this->withToken($token)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('guardian');
        $this->withToken($token)->postJson($url, ['guardian' => 'unknown'])->assertNotFound();
        $this->withToken($token)->postJson($url, ['guardian' => $ann->public_id])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/people/{$one->public_id}/guardians", ['guardian' => $ann->public_id])->assertUnprocessable(); // its own child
        $adult = Person::factory()->create(['birth_date' => now()->subYears(30)->toDateString()]);
        $this->withToken($token)->postJson("/api/v1/people/{$adult->public_id}/guardians", ['guardian' => $three->public_id])->assertUnprocessable();

        // Ending keeps the row, so the family page does not bring the guardianship back; a place is free again.
        $this->withToken($token)->deleteJson("$url/{$one->public_id}")->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(DB::table('guardianships')->where(['guardian_id' => $one->id, 'child_id' => $ann->id])->value('ends_on'));
        $this->withToken($token)->deleteJson("$url/{$one->public_id}")->assertNotFound();
        $this->withToken($token)->postJson($url, ['guardian' => $three->public_id])->assertCreated();
        $this->withToken($token)->postJson($url, ['guardian' => $one->public_id])->assertUnprocessable(); // full again
        $this->withToken($token)->deleteJson("$url/{$three->public_id}")->assertOk();
        $this->withToken($token)->postJson($url, ['guardian' => $one->public_id])->assertCreated()->assertJsonCount(2, 'data'); // starts again
        $this->assertSame(1, DB::table('guardianships')->where(['guardian_id' => $one->id, 'child_id' => $ann->id])->count());
    }

    public function test_membership_types(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $before = MembershipType::query()->count();
        $this->withToken($token)->postJson('/api/v1/membership-types', ['key' => 'supporter', 'name' => ['de' => 'Fördermitglied', 'en' => 'Supporter'], 'pays_fee' => true, 'fee' => 25.5])
            ->assertCreated()->assertJsonPath('data.key', 'supporter')->assertJsonPath('data.fee', 25.5)->assertJsonPath('data.name.en', 'Supporter');
        $this->assertSame(2550, MembershipType::query()->where('key', 'supporter')->firstOrFail()->fee_cents);
        $this->withToken($token)->getJson('/api/v1/membership-types')->assertOk()->assertJsonCount($before + 1, 'data');

        $this->withToken($token)->patchJson('/api/v1/membership-types/supporter', ['fee' => 30, 'name' => ['de' => 'Förderer', 'en' => 'Supporter']])
            ->assertOk()->assertJsonPath('data.fee', 30)->assertJsonPath('data.name.de', 'Förderer')->assertJsonPath('data.pays_fee', true);

        $this->withToken($token)->postJson('/api/v1/membership-types', ['key' => 'supporter', 'name' => ['de' => 'A', 'en' => 'B']])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->withToken($token)->postJson('/api/v1/membership-types', ['key' => 'not a key', 'name' => ['de' => 'A', 'en' => 'B']])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->withToken($token)->postJson('/api/v1/membership-types', ['key' => 'friend', 'name' => ['de' => 'A']])->assertUnprocessable()->assertJsonValidationErrors('name.en');
        $this->withToken($token)->patchJson('/api/v1/membership-types/supporter', ['key' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->withToken($token)->patchJson('/api/v1/membership-types/supporter', ['fee' => -1])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/v1/membership-types/unknown', ['fee' => 1])->assertNotFound();
    }

    public function test_standard_automation_can_be_set_but_stays_standard(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $automation = Automation::query()->where('action', 'invoice_run')->where('trigger', 'before_event')->firstOrFail();
        $url = '/api/v1/automations/'.$automation->key;
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.action', 'invoice_run')->assertJsonPath('data.standard', true)->assertJsonPath('data.trigger_config.days', 90)
            ->assertJsonPath('data.approver_roles', ['board', 'administrator'])->assertJsonPath('data.approver_roles_default', true);
        // The default roles sent on purpose are stored, an empty list goes back to the default.
        $this->withToken($token)->patchJson($url, ['approver_roles' => ['board', 'administrator']])->assertOk()->assertJsonPath('data.approver_roles', ['board', 'administrator'])->assertJsonPath('data.approver_roles_default', false);
        $this->withToken($token)->patchJson($url, ['approver_roles' => []])->assertOk()->assertJsonPath('data.approver_roles_default', true);

        $this->withToken($token)->patchJson($url, ['active' => true, 'mode' => 'manual', 'trigger_config' => ['days' => 60], 'config' => ['installment' => 'balance', 'send' => false],
            'approver_roles' => ['treasurer'], 'approvals_needed' => 2, 'max_items' => 50])
            ->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.mode', 'manual')->assertJsonPath('data.trigger_config.days', 60)
            ->assertJsonPath('data.config.send', false)->assertJsonPath('data.approver_roles', ['treasurer'])->assertJsonPath('data.approvals_needed', 2)->assertJsonPath('data.max_items', 50);
        $this->assertSame('api', AuditEvent::query()->where('action', 'Automation.updated')->latest('id')->firstOrFail()->actor_type);

        foreach ([['action' => 'custom'], ['key' => 'mine'], ['is_standard' => false], ['standard' => false]] as $fixed) {
            $this->withToken($token)->patchJson($url, $fixed)->assertUnprocessable();
        }
        $automation->refresh();
        $this->assertSame('invoice_run', $automation->action);
        $this->assertTrue($automation->is_standard);

        $this->withToken($token)->patchJson($url, ['mode' => 'sometimes'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->withToken($token)->patchJson($url, ['approvals_needed' => 0])->assertUnprocessable();
        $this->withToken($token)->patchJson($url, ['approver_roles' => ['counsellor_of_nothing']])->assertUnprocessable();
        $this->withToken($token)->patchJson($url, ['trigger_config' => ['days' => -1]])->assertUnprocessable()->assertJsonValidationErrors('trigger_config.days');
        $this->withToken($token)->patchJson($url, ['trigger_config' => ['time' => '08:00']])->assertUnprocessable()->assertJsonValidationErrors('trigger_config'); // not for this trigger
        $this->withToken($token)->patchJson($url, ['config' => ['steps' => []]])->assertUnprocessable()->assertJsonValidationErrors('config'); // not for this action
        $this->withToken($token)->patchJson($url, ['config' => ['send' => 'perhaps']])->assertUnprocessable()->assertJsonValidationErrors('config.send');
        $this->withToken($token)->patchJson($url, ['trigger' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('trigger_config.weekday');
        $this->withToken($token)->patchJson($url, ['trigger' => 'weekly', 'trigger_config' => ['weekday' => 2, 'time' => '07:15']])
            ->assertOk()->assertJsonPath('data.trigger', 'weekly')->assertJsonPath('data.trigger_config.weekday', 2)->assertJsonPath('data.trigger_config.time', '07:15')->assertJsonMissingPath('data.trigger_config.days');
        $this->withToken($token)->patchJson('/api/v1/automations/unknown', ['active' => true])->assertNotFound();
    }

    public function test_own_automation_is_checked_like_in_the_form(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $own = Automation::query()->create(['key' => 'own_welcome', 'name' => ['de' => 'Willkommen', 'en' => 'Welcome'], 'action' => 'custom', 'trigger' => 'on_person_created',
            'config' => ['target' => ['source' => 'subject'], 'steps' => [['type' => 'notify_team', 'text' => 'Say hello']]]]);
        $url = '/api/v1/automations/own_welcome';
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.standard', false);

        $steps = [['type' => 'notify_team', 'text' => 'Welcome the family', 'to' => 'person']];
        $this->withToken($token)->patchJson($url, ['config' => ['target' => ['source' => 'subject'], 'once' => true, 'steps' => $steps]])
            ->assertOk()->assertJsonPath('data.config.once', true)->assertJsonPath('data.config.steps', [['type' => 'notify_team', 'text' => 'Welcome the family']]);

        $this->withToken($token)->patchJson($url, ['config' => ['target' => ['source' => 'people'], 'steps' => $steps]])->assertUnprocessable()->assertJsonValidationErrors('config.target.source');
        $this->withToken($token)->patchJson($url, ['config' => ['target' => ['source' => 'subject'], 'steps' => []]])->assertUnprocessable()->assertJsonValidationErrors('config.steps');
        $this->withToken($token)->patchJson($url, ['config' => ['target' => ['source' => 'subject'], 'steps' => [['type' => 'send_mail']]]])->assertUnprocessable()->assertJsonValidationErrors('config.steps.0.template');
        $this->withToken($token)->patchJson($url, ['config' => ['target' => ['source' => 'subject'], 'steps' => [['type' => 'invoice', 'description' => 'Fee', 'amount' => 10]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('config.steps.0.type'); // needs finance:write
        $this->withToken($token)->patchJson($url, ['trigger' => 'daily'])->assertUnprocessable()->assertJsonValidationErrors('config.target.source'); // the stored target no longer fits
        $this->withToken($token)->patchJson($url, ['trigger' => 'daily', 'trigger_config' => ['time' => '08:00'],
            'config' => ['target' => ['source' => 'people'], 'conditions' => ['rules' => [['field' => 'age', 'min' => 14]]], 'steps' => $steps]])
            ->assertOk()->assertJsonPath('data.config.conditions.match', 'all')->assertJsonPath('data.config.conditions.rules.0.min', 14);
        $this->assertSame('custom', $own->refresh()->action);
    }

    public function test_events_create_and_change(): void
    {
        $token = $this->token(['events:read', 'events:write']);
        $start = now()->addMonths(4)->startOfDay();
        $created = $this->withToken($token)->postJson('/api/v1/events', [
            'title' => 'Summer camp 2028', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addWeek()->toIso8601String(), 'status' => 'announced', 'location' => 'Lake',
            'registration_opens_at' => now()->addDay()->toIso8601String(), 'registration_closes_at' => now()->addMonth()->toIso8601String(),
            'roles' => [['role' => 'child', 'capacity' => 30, 'min_age' => 8, 'max_age' => 13, 'price' => 420.5], ['role' => 'staff', 'registered_by' => 'self']],
        ])->assertCreated()->assertJsonPath('data.title', 'Summer camp 2028')->assertJsonPath('data.status', 'announced')->assertJsonPath('data.location', 'Lake')
            ->assertJsonPath('data.roles.0.capacity', 30)->assertJsonPath('data.roles.0.price', 420.5)->assertJsonPath('data.roles.1.registered_by', 'self');
        $id = (string) $created->json('data.id');
        $event = Event::query()->where('public_id', $id)->firstOrFail();
        $this->assertSame(42050, $event->roles()->where('role_key', 'child')->firstOrFail()->price_cents);
        $this->assertSame('api', AuditEvent::query()->where('action', 'Event.created')->latest('id')->firstOrFail()->actor_type);

        $this->withToken($token)->patchJson("/api/v1/events/$id", ['status' => 'open', 'meeting_point' => 'Station', 'roles' => [['role' => 'child', 'capacity' => 32, 'min_age' => null, 'max_age' => 14]]])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.meeting_point', 'Station')->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.capacity', 32)->assertJsonPath('data.roles.0.min_age', null)->assertJsonPath('data.roles.0.max_age', 14)->assertJsonPath('data.roles.0.price', 420.5);
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['title' => 'Summer camp'])->assertOk()->assertJsonCount(1, 'data.roles'); // roles stay without "roles"

        app(Registrations::class)->register($event->refresh(), $this->child(), 'child');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['roles' => [['role' => 'staff']]])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertSame(1, $event->roles()->where('role_key', 'child')->count());

        $this->withToken($token)->postJson('/api/v1/events', ['title' => 'Autumn camp', 'starts_at' => $start->copy()->addMonths(3)->toIso8601String(), 'ends_at' => $start->copy()->addMonths(3)->addDay()->toIso8601String()])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/events')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta', ['total' => 2, 'page' => 1, 'last_page' => 1]);
        $this->withToken($token)->getJson('/api/v1/events?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('meta', ['total' => 2, 'page' => 2, 'last_page' => 2]);

        $this->withToken($token)->postJson('/api/v1/events', ['title' => 'No dates'])->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['ends_at' => $start->copy()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['registration_closes_at' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_at');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['status' => 'soon'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['series_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('series_id');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['map_url' => 'http://example.org/map'])->assertUnprocessable()->assertJsonValidationErrors('map_url');
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['roles' => [['role' => 'child'], ['role' => 'child']]])->assertUnprocessable();
        $this->withToken($token)->patchJson("/api/v1/events/$id", ['roles' => [['role' => 'child', 'min_age' => 12, 'max_age' => 9]]])->assertUnprocessable()->assertJsonValidationErrors('roles.0.max_age');
        $this->withToken($token)->patchJson('/api/v1/events/unknown', ['title' => 'X'])->assertNotFound();
    }
}
