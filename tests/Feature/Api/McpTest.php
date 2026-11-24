<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Api\ApiContext;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        $person = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Admin']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        $this->user = User::query()->create(['name' => 'Alex', 'email' => 'alex@example.org', 'password' => 'x', 'person_id' => $person->id]);
        Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Brook']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TestResponse<JsonResponse>
     */
    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withToken($token)->withHeaders(['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /** @param  list<string>  $scopes */
    private function token(array $scopes, bool $mcp = true): string
    {
        return ApiToken::issue($this->user, 'assistant', $scopes, $mcp)['plain'];
    }

    public function test_mcp_needs_a_marked_token_and_the_ai_setting(): void
    {
        $token = $this->token(['people:read']);
        $this->rpc($token, 'tools/list')->assertForbidden();
        Settings::set('ai_processing', 'non_confidential');
        $this->rpc($this->token(['people:read'], false), 'tools/list')->assertForbidden();
        $this->rpc($token, 'tools/list')->assertOk();
        $this->flushHeaders()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_tools_follow_the_scopes_and_call_the_api(): void
    {
        Settings::set('ai_processing', 'non_confidential');
        $token = $this->token(['people:read']);
        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'Kasmin');

        $names = array_column((array) $this->rpc($token, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('search_people', $names);
        $this->assertNotContains('create_person', $names, 'no write scope');
        $this->assertNotContains('decide_approval', $names);

        $result = $this->rpc($token, 'tools/call', ['name' => 'search_people', 'arguments' => ['search' => 'Brook']])->assertOk();
        $text = (string) $result->json('result.content.0.text');
        $this->assertStringContainsString('"first_name":"Kim"', $text);

        $denied = $this->rpc($token, 'tools/call', ['name' => 'create_person', 'arguments' => ['first_name' => 'X', 'last_name' => 'Y']]);
        $this->assertTrue((bool) ($denied->json('result.isError') ?? $denied->json('error')), 'unlisted tool cannot be called');
        $this->assertSame(2, Person::query()->count());
    }

    public function test_writes_via_mcp_are_audited_as_mcp(): void
    {
        Settings::set('ai_processing', 'non_confidential');
        $token = $this->token(['people:write', 'people:read']);
        $this->rpc($token, 'tools/call', ['name' => 'create_person', 'arguments' => ['first_name' => 'Robin', 'last_name' => 'Ash']])->assertOk();
        $this->assertTrue(Person::query()->where('first_name', 'Robin')->exists());
        $audit = AuditEvent::query()->where('action', 'import.batch_imported')->latest('id')->firstOrFail();
        $this->assertSame('mcp', $audit->actor_type);
    }

    public function test_health_scope_via_mcp_only_if_all_data_allowed(): void
    {
        $token = ApiToken::issue($this->user, 'x', ['health:read'], true)['token'];
        Settings::set('ai_processing', 'non_confidential');
        ApiContext::set($token, true);
        $this->assertFalse(ApiContext::allows('health:read'));
        Settings::set('ai_processing', 'all');
        $this->assertTrue(ApiContext::allows('health:read'));
        ApiContext::set($token, false);
        $this->assertTrue(ApiContext::allows('health:read'));
        ApiContext::set(null);
    }
}
