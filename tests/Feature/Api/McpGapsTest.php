<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Event;
use App\Models\MembershipType;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AP01: what the REST API offers is also available as MCP tools – contact data, legal guardians, membership types,
 * automations, events and the travel of a participation. An AI assistant cannot switch an approval off.
 */
class McpGapsTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = [
        'list_contact_points' => 'people:read', 'add_contact_point' => 'people:write', 'remove_contact_point' => 'people:write',
        'list_guardians' => 'people:read', 'add_guardian' => 'people:write', 'end_guardianship' => 'people:write',
        'list_membership_types' => 'people:read', 'create_membership_type' => 'people:write', 'update_membership_type' => 'people:write',
        'get_automation' => 'automations:read', 'update_automation' => 'automations:write',
        'create_event' => 'events:write', 'update_event' => 'events:write', 'set_participation_travel' => 'events:write',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('ai_processing', 'non_confidential');
        $person = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Admin', 'birth_date' => '1980-01-01']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes): string
    {
        return ApiToken::issue($this->user, 'assistant', $scopes, true)['plain'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return TestResponse<JsonResponse>
     */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withToken($token)->withHeaders(['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
    }

    /**
     * Calls a tool that has to work and returns its JSON answer.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $token, string $name, array $arguments = []): array
    {
        $response = $this->callTool($token, $name, $arguments)->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $this->assertFalse((bool) ($response->json('error') ?? $response->json('result.isError')), "{$name} failed: {$text}");

        return (array) json_decode($text, true);
    }

    /**
     * Calls a tool that has to be refused and returns the error text.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function refused(string $token, string $name, array $arguments, string $why = ''): string
    {
        $response = $this->callTool($token, $name, $arguments);
        $this->assertTrue((bool) ($response->json('error') ?? $response->json('result.isError')), "{$name} must be refused. {$why}");

        return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
    }

    /**
     * The names of all tools the token is offered (the list comes in pages).
     *
     * @return list<string>
     */
    private function listed(string $token): array
    {
        $names = [];
        $cursor = null;
        do {
            $page = $this->withToken($token)->withHeaders(['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
                ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => $cursor !== null ? ['cursor' => $cursor] : []])->assertOk();
            foreach ((array) $page->json('result.tools') as $tool) {
                $names[] = (string) $tool['name'];
            }
            $cursor = $page->json('result.nextCursor');
        } while (is_string($cursor) && $cursor !== '');

        return $names;
    }

    private function child(): Person
    {
        return Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => now()->subYears(10)->toDateString()]);
    }

    public function test_new_tools_are_listed_by_scope_and_refused_without_it(): void
    {
        $all = $this->listed($this->token(array_values(array_unique(self::TOOLS))));
        $none = $this->token(['audit:read']);
        $listed = $this->listed($none);
        $child = $this->child();
        $event = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addWeek()]);
        $arguments = ['person' => $child->public_id, 'guardian' => $child->public_id, 'id' => $event->public_id, 'key' => 'member', 'type' => 'email', 'value' => 'ann@example.org',
            'name' => ['de' => 'A', 'en' => 'B'], 'title' => 'X', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->toIso8601String(), 'travel' => 'bus', 'active' => true];
        $events = Event::query()->count();
        foreach (array_keys(self::TOOLS) as $name) {
            $this->assertContains($name, $all, "{$name} is offered with its scope");
            $this->assertNotContains($name, $listed, "{$name} is hidden without its scope");
            $this->refused($none, $name, $arguments, "{$name} is refused without its scope");
        }
        // Reading does not allow writing.
        $reader = $this->token(['people:read', 'events:read', 'automations:read']);
        foreach (array_keys(array_filter(self::TOOLS, fn (string $scope) => str_ends_with($scope, ':write'))) as $name) {
            $this->assertNotContains($name, $this->listed($reader));
            $this->refused($reader, $name, $arguments, "{$name} is refused with read scopes");
        }
        $this->assertSame($events, Event::query()->count());
        $this->assertSame(0, $child->contactPoints()->count());
        $this->assertSame(0, $child->guardians()->count());
    }

    public function test_contact_points(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $child = $this->child();
        $added = $this->tool($token, 'add_contact_point', ['person' => $child->public_id, 'type' => 'email', 'value' => ' Ann@Example.org ', 'purposes' => ['information']]);
        $this->assertSame('ann@example.org', $added['data'][0]['value'] ?? null);
        $this->assertTrue($added['data'][0]['primary']);
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'ContactPoint.created')->latest('id')->firstOrFail()->actor_type);
        $this->tool($token, 'add_contact_point', ['person' => $child->public_id, 'type' => 'phone', 'value' => '+43 1 234 56']);

        $list = $this->tool($token, 'list_contact_points', ['person' => $child->public_id]);
        $this->assertCount(2, $list['data'] ?? []);

        $error = $this->refused($token, 'add_contact_point', ['person' => $child->public_id, 'type' => 'email', 'value' => 'not an address'], 'the checks of the REST API apply');
        $this->assertStringContainsString('HTTP 422', $error);

        $left = $this->tool($token, 'remove_contact_point', ['person' => $child->public_id, 'id' => $list['data'][1]['id']]);
        $this->assertCount(1, $left['data'] ?? []);
        $this->assertSame(1, $child->contactPoints()->count());
    }

    public function test_legal_guardians(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $child = $this->child();
        $guardian = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Birch', 'birth_date' => '1985-05-05']);

        $added = $this->tool($token, 'add_guardian', ['person' => $child->public_id, 'guardian' => $guardian->public_id]);
        $this->assertSame($guardian->public_id, $added['data'][0]['id'] ?? null);
        $this->assertSame([['id' => $guardian->public_id, 'name' => 'Robin Birch']], $this->tool($token, 'list_guardians', ['person' => $child->public_id])['data'] ?? null);

        $error = $this->refused($token, 'add_guardian', ['person' => $child->public_id, 'guardian' => $child->public_id]);
        $this->assertStringContainsString('HTTP 422', $error);

        $this->assertSame([], $this->tool($token, 'end_guardianship', ['person' => $child->public_id, 'guardian' => $guardian->public_id])['data'] ?? null);
        $this->assertSame(0, $child->guardians()->count());
        $this->assertTrue(Person::query()->whereKey($guardian->id)->exists(), 'the legal guardian stays a person');
    }

    public function test_membership_types(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $before = MembershipType::query()->count();
        $created = $this->tool($token, 'create_membership_type', ['key' => 'supporter', 'name' => ['de' => 'Fördermitglied', 'en' => 'Supporter'], 'pays_fee' => true, 'fee' => 25.5]);
        $this->assertSame('supporter', $created['data']['key'] ?? null);
        $this->assertSame(2550, MembershipType::query()->where('key', 'supporter')->firstOrFail()->fee_cents);
        $this->assertCount($before + 1, $this->tool($token, 'list_membership_types')['data'] ?? []);

        $updated = $this->tool($token, 'update_membership_type', ['key' => 'supporter', 'fee' => 30, 'pays_fee' => false]);
        $this->assertEquals(30, $updated['data']['fee'] ?? null);
        $this->assertFalse($updated['data']['pays_fee']);
        $this->assertSame('Supporter', $updated['data']['name']['en']);

        $error = $this->refused($token, 'create_membership_type', ['key' => 'supporter', 'name' => ['de' => 'A', 'en' => 'B']], 'the key is taken');
        $this->assertStringContainsString('HTTP 422', $error);
    }

    public function test_events_and_travel(): void
    {
        $token = $this->token(['events:read', 'events:write']);
        $start = now()->addMonths(4)->startOfDay();
        $created = $this->tool($token, 'create_event', ['title' => 'Summer camp', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addWeek()->toIso8601String(), 'location' => 'Lake',
            'roles' => [['role' => 'child', 'capacity' => 30, 'min_age' => 8, 'max_age' => 13, 'price' => 420.5], ['role' => 'staff', 'registered_by' => 'self']]]);
        $this->assertSame('Summer camp', $created['data']['title'] ?? null);
        $this->assertCount(2, $created['data']['roles']);
        $id = (string) $created['data']['id'];
        $event = Event::query()->where('public_id', $id)->firstOrFail();
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'Event.created')->latest('id')->firstOrFail()->actor_type);

        $updated = $this->tool($token, 'update_event', ['id' => $id, 'status' => 'open', 'meeting_point' => 'Station', 'public_page' => false, 'clear' => ['location']]);
        $this->assertSame('open', $updated['data']['status'] ?? null);
        $this->assertSame('Station', $updated['data']['meeting_point']);
        $this->assertNull($updated['data']['location'], 'emptied by name');
        $this->assertCount(2, $updated['data']['roles'], 'roles stay without "roles"');
        $this->assertSame('Summer camp', $event->refresh()->title);

        $error = $this->refused($token, 'update_event', ['id' => $id, 'ends_at' => $start->copy()->subDay()->toIso8601String()]);
        $this->assertStringContainsString('HTTP 422', $error);
        $this->refused($token, 'update_event', ['id' => $id, 'clear' => ['title']], 'a required field cannot be emptied');
        $this->assertSame('Summer camp', $event->refresh()->title);

        $adult = Participation::query()->create(['event_id' => $event->id, 'person_id' => $this->user->person_id, 'role_key' => 'staff', 'status' => 'confirmed', 'registered_at' => now()]);
        $child = Participation::query()->create(['event_id' => $event->id, 'person_id' => $this->child()->id, 'role_key' => 'child', 'status' => 'confirmed', 'registered_at' => now()]);
        $travel = $this->tool($token, 'set_participation_travel', ['id' => $adult->id, 'travel' => 'car']);
        $this->assertSame('car', $travel['data']['travel'] ?? null);
        $this->assertSame('confirmed', $travel['data']['status'], 'the status is not touched');
        $error = $this->refused($token, 'set_participation_travel', ['id' => $child->id, 'travel' => 'car'], 'children go by bus');
        $this->assertStringContainsString('Only adults', $error);
        $this->assertSame('bus', $this->tool($token, 'set_participation_travel', ['id' => $adult->id, 'travel' => 'bus'])['data']['travel'] ?? null);
    }

    public function test_automation_can_be_read_and_changed(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $automation = Automation::query()->where('action', 'invoice_run')->where('trigger', 'before_event')->firstOrFail();
        $automation->update(['mode' => 'confirm', 'active' => false]);

        $read = $this->tool($token, 'get_automation', ['key' => $automation->key]);
        $this->assertSame('invoice_run', $read['data']['action'] ?? null);
        $this->assertTrue($read['data']['approver_roles_default']);

        // Same values for the approval settings are not a change.
        $changed = $this->tool($token, 'update_automation', ['key' => $automation->key, 'active' => true, 'trigger_config' => ['days' => 60], 'mode' => 'confirm', 'approver_roles' => []]);
        $this->assertTrue($changed['data']['active'] ?? null);
        $this->assertSame(60, $changed['data']['trigger_config']['days']);
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'Automation.updated')->latest('id')->firstOrFail()->actor_type);

        $error = $this->refused($token, 'update_automation', ['key' => $automation->key, 'trigger_config' => ['days' => -1]], 'checked like in the REST API');
        $this->assertStringContainsString('HTTP 422', $error);
    }

    public function test_an_assistant_cannot_switch_an_approval_off(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $automation = Automation::query()->where('action', 'invoice_run')->where('trigger', 'before_event')->firstOrFail();
        $automation->update(['mode' => 'confirm', 'active' => true, 'max_items' => 20, 'approvals_needed' => 2, 'approver_roles' => ['treasurer']]);
        $key = $automation->key;

        foreach ([['mode' => 'automatic'], ['mode' => 'manual'], ['max_items' => 5000], ['approvals_needed' => 1], ['approver_roles' => ['administrator']], ['approver_roles' => []],
            ['active' => false, 'mode' => 'automatic']] as $change) {
            $error = $this->refused($token, 'update_automation', ['key' => $key] + $change, json_encode($change).' is refused');
            $this->assertStringContainsString('HTTP 403', $error);
            $this->assertStringContainsString('approvals:decide', $error);
        }
        $automation->refresh();
        $this->assertSame(['confirm', true, 20, 2, ['treasurer']], [$automation->mode, $automation->active, $automation->max_items, $automation->approvals_needed, $automation->approver_roles]);

        // An automation that runs without approval: what it does cannot be changed, and it cannot be switched on.
        $automation->update(['mode' => 'automatic', 'active' => false]);
        foreach ([['trigger_config' => ['days' => 1]], ['config' => ['installment' => 'balance', 'send' => false]], ['trigger' => 'weekly', 'trigger_config' => ['weekday' => 1]], ['active' => true]] as $change) {
            $error = $this->refused($token, 'update_automation', ['key' => $key] + $change, json_encode($change).' is refused');
            $this->assertStringContainsString('HTTP 403', $error);
        }
        $automation->refresh();
        $this->assertSame([false, 'before_event', 90], [$automation->active, $automation->trigger, $automation->trigger_config['days'] ?? null]);
        // The name can be changed, and it stays switched off when asked.
        $renamed = $this->tool($token, 'update_automation', ['key' => $key, 'name' => ['de' => 'Lauf', 'en' => 'Run'], 'active' => false]);
        $this->assertSame('automatic', $renamed['data']['mode'] ?? null);
        $automation->update(['active' => true]);
        $this->assertFalse($this->tool($token, 'update_automation', ['key' => $key, 'active' => false])['data']['active'] ?? null, 'switching off is always possible');

        // With approvals:decide, the token of a person who may decide can change the approval.
        $decider = $this->token(['automations:read', 'automations:write', 'approvals:decide']);
        $changed = $this->tool($decider, 'update_automation', ['key' => $key, 'mode' => 'confirm', 'approver_roles' => ['administrator'], 'approvals_needed' => 1, 'trigger_config' => ['days' => 30]]);
        $this->assertSame('confirm', $changed['data']['mode'] ?? null);
        $this->assertSame(['administrator'], $changed['data']['approver_roles']);
        $this->assertFalse($changed['data']['approver_roles_default']);

        // The REST API keeps the scope automations:write as it is.
        $rest = ApiToken::issue($this->user, 'integration', ['automations:write'])['plain'];
        $this->withToken($rest)->patchJson('/api/v1/automations/'.$key, ['mode' => 'automatic'])->assertOk()->assertJsonPath('data.mode', 'automatic');
    }

    public function test_lists_come_in_pages(): void
    {
        $token = $this->token(['events:read', 'automations:read']);
        foreach (range(1, 3) as $i) {
            Event::query()->create(['title' => 'Camp '.$i, 'starts_at' => now()->addMonths($i), 'ends_at' => now()->addMonths($i)->addWeek()]);
        }
        $page = $this->tool($token, 'list_events', ['per_page' => 2, 'page' => 2]);
        $this->assertSame(['total' => 3, 'page' => 2, 'last_page' => 2], $page['meta'] ?? null);
        $this->assertSame('Camp 1', $page['data'][0]['title']);
        $this->assertSame(['total' => 0, 'page' => 1, 'last_page' => 1], $this->tool($token, 'list_approvals')['meta'] ?? null);
    }
}
