<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\RolesController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Mcp\ApiTool;
use App\Mcp\KasminServer;
use App\Models\ApiToken;
use App\Models\ArchivedInvoice;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Consent;
use App\Models\ContactPoint;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\Form;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MailAccount;
use App\Models\MailTemplate;
use App\Models\MembershipType;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Payment;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Support\Api\ApiContext;
use App\Support\Finance\Invoices;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Tests\TestCase;

/**
 * AP01: everything the REST API offers for administrative use is also an MCP tool. The first test walks the routes
 * of the API and fails for every route without a tool or a reason; the others show one way through every group of
 * tools, the refusal without the scope, and what an AI assistant must not do (approvals, roles, health data).
 */
class McpParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes that are deliberately not an MCP tool, each with the reason. Everything else needs a tool.
     */
    private const NOT_FOR_MCP = [
        // A tool call carries JSON only, no files (App\Mcp\ApiTool).
        'POST /events/{event}/documents' => 'file upload (multipart/form-data); documents are added in the admin interface or via REST',
        // A tool answer is text; a file would have to go through the assistant and its provider.
        'GET /event-documents/{document}/download' => 'binary file download',
        'GET /invoices/{invoice}/pdf' => 'binary PDF; the data of the invoice is in get_invoice',
        // The signing secret is shown exactly once and belongs to the human who sets up the receiving system; an
        // assistant must also not be able to send data of the organisation to an address of its choice.
        'POST /webhooks' => 'answers with the signing secret, shown once; creates a new address that receives data',
        'POST /webhooks/{webhook}/secret' => 'answers with the new signing secret, shown once',
    ];

    /**
     * The tools added for AP01, by the scopes they need via MCP.
     */
    private const NEW_TOOLS = [
        'people:read' => ['list_memberships', 'list_roles', 'list_person_roles', 'list_duplicates', 'list_households', 'get_household', 'get_person_form', 'list_public_forms', 'list_public_submissions'],
        'people:write' => ['update_person', 'add_membership', 'update_membership', 'merge_duplicates', 'dismiss_duplicate', 'create_household', 'update_household', 'add_household_member', 'remove_household_member',
            'update_group', 'update_person_form', 'accept_public_submission', 'reject_public_submission'],
        'roles:write,approvals:decide' => ['assign_role', 'remove_role'],
        'events:read' => ['get_event_schedule', 'list_event_series', 'list_event_templates', 'list_registration_rounds', 'list_event_documents', 'list_divisions', 'list_event_lists', 'get_event_list', 'get_statistics', 'get_dashboard'],
        'events:write' => ['update_participation_status', 'assign_reserve_place', 'offer_place', 'release_reserve_places', 'create_event_series', 'update_event_series', 'create_event_template', 'update_event_template',
            'delete_event_template', 'save_registration_rounds', 'delete_event_document', 'create_division', 'delete_division', 'propose_division', 'move_division_member', 'add_division_unit', 'update_division_unit', 'delete_division_unit'],
        'finance:read' => ['list_event_costs', 'list_payments', 'list_refunds', 'list_archived_invoices'],
        'finance:write' => ['add_event_cost', 'update_event_cost', 'delete_event_cost', 'issue_invoice', 'send_invoice', 'cancel_invoice', 'assign_payment', 'mark_refund_done', 'keep_refund_as_credit'],
        'automations:read' => ['get_automation_run'],
        'automations:write' => ['create_automation', 'delete_automation'],
        'import:write' => ['suggest_import_families'],
        'mail:read' => ['list_mail_template_versions', 'list_outgoing_mails', 'list_bad_addresses', 'list_sender_profiles'],
        'mail:write' => ['update_mail_template', 'restore_mail_template_version', 'clear_bad_address'],
        'settings:write' => ['create_sender_profile', 'update_sender_profile', 'get_settings', 'update_settings', 'list_webhooks', 'update_webhook', 'delete_webhook'],
        'audit:read' => ['list_consents', 'list_deletion_requests'],
        'audit:read,people:read' => ['export_person_data'],
        'events:read,people:read' => ['get_age_group_members'],
        'privacy:write' => ['anonymise_person'],
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        Settings::set('ai_processing', 'non_confidential');
        $person = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Admin', 'locale' => 'en', 'birth_date' => '1980-01-01']);
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
        $this->assertFalse((bool) ($response->json('error') ?? $response->json('result.isError')), "{$name} failed: {$text}".json_encode($response->json('error')));

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

    private function person(string $first, string $last, int $age = 10): Person
    {
        return Person::factory()->create(['first_name' => $first, 'last_name' => $last, 'locale' => 'en', 'birth_date' => now()->subYears($age)->subMonth()->toDateString()]);
    }

    private function event(int $capacity = 10, int $reserve = 0): Event
    {
        $event = Event::query()->create(['title' => 'Summer camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => $capacity, 'reserve_places' => $reserve, 'min_age' => 6, 'max_age' => 15, 'price_cents' => 40000]);

        return $event;
    }

    private function participation(Event $event, Person $person, string $status = 'registered'): Participation
    {
        return Participation::query()->create(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => 'child', 'status' => $status, 'registered_at' => now()]);
    }

    /**
     * The English subject in the answer of a tool for mail templates.
     *
     * @param  array<string, mixed>  $answer
     */
    private function subject(array $answer): ?string
    {
        return isset($answer['data']['subject']) ? (string) $answer['data']['subject'] : null;
    }

    /**
     * The tools of the server.
     *
     * @return list<class-string<ApiTool>>
     */
    private function tools(): array
    {
        /** @var list<class-string<ApiTool>> $tools */
        $tools = (new ReflectionClass(KasminServer::class))->getProperty('tools')->getDefaultValue();

        return $tools;
    }

    /**
     * The authenticated routes of the REST API v1 as "METHOD /uri", the URI as written in routes/api.php.
     *
     * @return list<string>
     */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array(AuthenticateApiToken::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method.' '.substr($route->uri(), strlen('api/v1'));
            }
        }

        return $routes;
    }

    public function test_every_route_of_the_api_has_a_tool_or_a_reason(): void
    {
        $routes = $this->apiRoutes();
        $this->assertGreaterThan(140, count($routes));
        $this->assertNotContains('GET /public/events', $routes, 'public routes need no token and are not administrative');

        $covered = [];
        foreach ($this->tools() as $tool) {
            [$method, $uri] = $tool::ENDPOINT;
            $this->assertContains($method.' '.$uri, $routes, "{$tool} names an endpoint that is not a route of the API");
            $covered[$method.' '.$uri][] = $tool;
        }
        foreach (self::NOT_FOR_MCP as $route => $reason) {
            $this->assertContains($route, $routes, "NOT_FOR_MCP names {$route}, which is not a route of the API");
            $this->assertArrayNotHasKey($route, $covered, "{$route} has a tool and a reason not to have one");
            $this->assertNotSame('', trim($reason));
        }
        $missing = array_values(array_diff($routes, array_keys($covered), array_keys(self::NOT_FOR_MCP)));
        $this->assertSame([], $missing, "Routes of the REST API without an MCP tool. Add a tool in app/Mcp/Tools, or the route with a reason to NOT_FOR_MCP:\n".implode("\n", $missing));

        // One tool per endpoint; only the participation has two, for its two fields.
        $double = array_filter($covered, fn (array $tools) => count($tools) > 1);
        $this->assertSame(['PATCH /participations/{participation}'], array_keys($double));
    }

    public function test_tools_are_declared_in_one_way(): void
    {
        $names = [];
        $files = glob(app_path('Mcp/Tools/*.php')) ?: [];
        $this->assertSame(count($files), count($this->tools()), 'every tool in app/Mcp/Tools is registered in KasminServer');
        foreach ($this->tools() as $tool) {
            $class = new ReflectionClass($tool);
            $name = (string) ($class->getAttributes(Name::class)[0] ?? null)?->newInstance()->value;
            $description = (string) ($class->getAttributes(Description::class)[0] ?? null)?->newInstance()->value;
            $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $name, $tool);
            $this->assertSame(str_replace('_', '', $name), strtolower($class->getShortName()), 'class and tool have the same name');
            $this->assertGreaterThan(20, strlen($description), "{$name} needs a description");
            $this->assertStringNotContainsStringIgnoringCase('parent ', str_replace('parent group', '', $description), "{$name}: legal guardians, not parents");
            $names[] = $name;

            [$method, $uri] = $tool::ENDPOINT;
            $readOnly = $class->getAttributes(IsReadOnly::class) !== [];
            $destructive = $class->getAttributes(IsDestructive::class) !== [];
            $this->assertFalse($readOnly && $destructive, $name);
            if ($method === 'GET') {
                $this->assertTrue($readOnly, "{$name} only reads and is marked read-only");
            } elseif ($readOnly) {
                // Dry runs are sent with POST and change nothing.
                $this->assertContains($name, ['import_preview', 'suggest_import_families', 'preview_automation', 'preview_mail_template'], "{$name} is marked read-only but writes");
            }
            if ($method === 'DELETE') {
                $this->assertTrue($destructive, "{$name} deletes and is marked destructive");
            }
            // A tool that builds its path itself calls what it declares.
            $source = (string) file_get_contents((string) $class->getFileName());
            if (! str_contains($source, 'callEndpoint(')) {
                $this->assertStringContainsString("call('{$method}'", $source, $name);
                foreach (array_filter(explode('/', (string) preg_replace('/\{\w+\}/', '', $uri))) as $segment) {
                    $this->assertStringContainsString($segment, $source, "{$name} calls {$uri}");
                }
            }
        }
        $this->assertSame($names, array_values(array_unique($names)), 'tool names are unique');
        foreach (['anonymise_person', 'merge_duplicates', 'cancel_invoice', 'issue_invoice', 'assign_role', 'remove_role', 'update_participation_status', 'update_settings', 'reject_public_submission'] as $name) {
            $tool = collect($this->tools())->first(fn (string $t) => (new ReflectionClass($t))->getAttributes(Name::class)[0]->newInstance()->value === $name);
            $this->assertNotNull($tool, $name);
            $this->assertNotSame([], (new ReflectionClass($tool))->getAttributes(IsDestructive::class), "{$name} is marked destructive");
        }
        $new = array_merge(...array_values(self::NEW_TOOLS));
        $this->assertSame([], array_values(array_diff($new, $names)));
        $this->assertCount(88, $new);
        $this->assertCount(140, $names);
    }

    public function test_new_tools_are_listed_by_scope_and_refused_without_it(): void
    {
        $scopes = array_values(array_unique(explode(',', implode(',', array_keys(self::NEW_TOOLS)))));
        $all = $this->listed($this->token($scopes));
        $listedWithout = $this->listed($this->token(['automations:run']));
        $arguments = ['id' => 1, 'person' => 'x', 'key' => 'x', 'form' => 'x', 'version' => 1, 'participation' => 1];
        $people = Person::query()->count();
        $calls = 0;
        $none = $this->token(['automations:run']);
        foreach (self::NEW_TOOLS as $needed => $tools) {
            $needed = explode(',', $needed);
            foreach ($tools as $name) {
                $this->assertContains($name, $all, "{$name} is offered with its scopes");
                $this->assertNotContains($name, $listedWithout, "{$name} is hidden without its scope");
                $none = ++$calls % 40 === 0 ? $this->token(['automations:run']) : $none; // the rate limit counts per token
                $this->refused($none, $name, $arguments, 'refused without its scope');
            }
            // One of two scopes is not enough.
            if (count($needed) > 1) {
                foreach ($needed as $one) {
                    $half = $this->token([$one]);
                    foreach ($tools as $name) {
                        $this->assertNotContains($name, $this->listed($half), "{$name} needs all of ".implode(' and ', $needed));
                        $this->refused($half, $name, $arguments);
                    }
                }
            }
        }
        // Reading does not allow writing.
        $reader = $this->token(['people:read', 'events:read', 'finance:read', 'automations:read', 'mail:read', 'audit:read', 'health:read']);
        $offered = $this->listed($reader);
        foreach (self::NEW_TOOLS as $needed => $tools) {
            if (! str_ends_with($needed, ':read') && $needed !== 'audit:read,people:read') {
                $this->assertSame([], array_values(array_intersect($tools, $offered)), "read scopes do not offer the tools of {$needed}");
            }
        }
        foreach (['update_person', 'merge_duplicates', 'cancel_invoice', 'create_automation', 'update_settings', 'anonymise_person', 'assign_role', 'delete_division'] as $name) {
            $this->refused($reader, $name, $arguments, 'refused with read scopes');
        }
        $this->assertSame($people, Person::query()->count());
        // Files and webhook secrets are no tools at all.
        $everything = $this->listed($this->token([...$scopes, 'health:read', 'health:write', 'automations:run']));
        foreach (['create_webhook', 'new_webhook_secret', 'rotate_webhook_secret', 'upload_event_document', 'add_event_document', 'download_event_document', 'get_invoice_pdf'] as $name) {
            $this->assertNotContains($name, $everything);
        }
    }

    public function test_people_families_memberships_and_duplicates(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $ann = $this->person('Ann', 'Ash');
        $ann->update(['gender' => 'f']);
        $robin = $this->person('Robin', 'Birch', 40);

        $changed = $this->tool($token, 'update_person', ['id' => $ann->public_id, 'first_name' => 'Anna', 'clear' => ['gender', 'last_name']]);
        $this->assertSame('Anna', $changed['data']['first_name'] ?? null);
        $ann->refresh();
        $this->assertSame(['Anna', 'Ash', null], [$ann->first_name, $ann->last_name, $ann->getAttribute('gender')], 'only optional fields can be emptied');
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'Person.updated')->latest('id')->firstOrFail()->actor_type);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_person', ['id' => $ann->public_id, 'birth_date' => 'not a date']));

        // Families: create, members, invoice recipient.
        $family = $this->tool($token, 'create_household', ['name' => 'Ash-Birch', 'city' => 'Lakeside', 'country' => 'at']);
        $id = (string) ($family['data']['id'] ?? '');
        $this->assertSame('AT', $family['data']['country']);
        $this->tool($token, 'add_household_member', ['id' => $id, 'person' => $robin->public_id, 'role' => 'guardian']);
        $members = $this->tool($token, 'add_household_member', ['id' => $id, 'person' => $ann->public_id, 'role' => 'child']);
        $this->assertCount(2, $members['data']['members'] ?? []);
        $this->assertTrue($ann->guardians()->whereKey($robin->id)->exists(), 'the legal guardian of the family is the legal guardian of its child');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_household', ['id' => $id, 'invoice_recipient' => $ann->public_id], 'a child gets no invoices'));
        $updated = $this->tool($token, 'update_household', ['id' => $id, 'invoice_recipient' => $robin->public_id, 'street' => 'Elm Road 1', 'clear' => ['city']]);
        $this->assertSame([$robin->public_id, 'Elm Road 1', null], [$updated['data']['invoice_recipient'] ?? null, $updated['data']['street'], $updated['data']['city']]);
        $this->assertNull($this->tool($token, 'update_household', ['id' => $id, 'clear' => ['invoice_recipient']])['data']['invoice_recipient'] ?? null);
        $this->assertSame(1, $this->tool($token, 'list_households', ['search' => 'Birch'])['meta']['total'] ?? null);
        $this->assertSame('Ash-Birch', $this->tool($token, 'get_household', ['id' => $id])['data']['name'] ?? null);
        $this->assertCount(1, $this->tool($token, 'remove_household_member', ['id' => $id, 'person' => $ann->public_id])['data']['members'] ?? []);
        $this->assertTrue(Person::query()->whereKey($ann->id)->exists());

        // Memberships: give, end, let run again.
        $type = MembershipType::query()->orderBy('id')->firstOrFail();
        $given = $this->tool($token, 'add_membership', ['person' => $robin->public_id, 'type' => (string) $type->label()]);
        $membership = (int) ($given['data'][0]['id'] ?? 0);
        $this->assertSame($type->id, $given['data'][0]['type'] ?? null);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'add_membership', ['person' => $robin->public_id, 'type' => (string) $type->id], 'one running membership per type'));
        $ended = $this->tool($token, 'update_membership', ['person' => $robin->public_id, 'id' => $membership, 'ends_on' => now()->addMonth()->toDateString()]);
        $this->assertSame(now()->addMonth()->toDateString(), $ended['data'][0]['ends_on'] ?? null);
        $this->assertNull($this->tool($token, 'update_membership', ['person' => $robin->public_id, 'id' => $membership])['data'][0]['ends_on'] ?? null);
        $this->assertCount(1, $this->tool($token, 'list_memberships', ['person' => $robin->public_id])['data'] ?? []);

        // Duplicates: list, mark as different, merge.
        $twin = Person::factory()->create(['first_name' => 'Anna', 'last_name' => 'Ash', 'birth_date' => $ann->birth_date]);
        $cleo = Person::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Cedar', 'birth_date' => '2015-05-05']);
        $copy = Person::factory()->create(['first_name' => 'Cleo', 'last_name' => 'Cedar', 'birth_date' => '2015-05-05']);
        $this->assertCount(2, $this->tool($token, 'list_duplicates')['data'] ?? []);
        $this->tool($token, 'dismiss_duplicate', ['first' => $cleo->public_id, 'second' => $copy->public_id]);
        $this->assertCount(1, $this->tool($token, 'list_duplicates')['data'] ?? []);
        $this->assertTrue(Person::query()->whereKey($copy->id)->exists(), 'marking as different deletes nobody');
        $merged = $this->tool($token, 'merge_duplicates', ['keep' => $ann->public_id, 'duplicate' => $twin->public_id]);
        $this->assertSame($ann->public_id, $merged['data']['id'] ?? null);
        $this->assertFalse(Person::query()->whereKey($twin->id)->exists());
    }

    public function test_roles_need_the_approval_permission_and_never_give_the_administrator(): void
    {
        $robin = $this->person('Robin', 'Birch', 40);
        $robin->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true]);
        $reader = $this->token(['people:read']);
        $treasurer = Role::query()->where('key', 'treasurer')->firstOrFail();
        $this->assertContains($treasurer->label(), array_column($this->tool($reader, 'list_roles')['data'] ?? [], 'name', 'id'));
        $this->assertSame([], $this->tool($reader, 'list_person_roles', ['person' => $robin->public_id])['data'] ?? null);

        // roles:write alone is enough for REST, not for an AI assistant: a role can make somebody an approver.
        $writer = $this->token(['people:read', 'roles:write']);
        $this->assertSame([], array_values(array_intersect(['assign_role', 'remove_role'], $this->listed($writer))));
        $this->refused($writer, 'assign_role', ['person' => $robin->public_id, 'role' => 'treasurer']);
        $this->assertSame(0, $robin->roleAssignments()->count());
        // The endpoint itself refuses as well, not only the list of tools.
        ApiContext::set(ApiToken::issue($this->user, 'assistant', ['roles:write'], true)['token'], true);
        try {
            foreach (['store', 'destroy'] as $action) {
                try {
                    $action === 'store'
                        ? app(RolesController::class)->store(Request::create('/', 'POST', ['role' => 'treasurer']), $robin->public_id)
                        : app(RolesController::class)->destroy($robin->public_id, 1);
                    $this->fail('Roles via MCP need approvals:decide.');
                } catch (AuthorizationException $e) {
                    $this->assertStringContainsString('approvals:decide', $e->getMessage());
                }
            }
        } finally {
            ApiContext::set(null);
        }
        $this->assertSame(0, $robin->roleAssignments()->count());

        $decider = $this->token(['people:read', 'roles:write', 'approvals:decide']);
        $given = $this->tool($decider, 'assign_role', ['person' => $robin->public_id, 'role' => (string) $treasurer->label()]);
        $this->assertSame($treasurer->id, $given['data'][0]['role'] ?? null);
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'RoleAssignment.created')->latest('id')->firstOrFail()->actor_type);
        $error = $this->refused($decider, 'assign_role', ['person' => $robin->public_id, 'role' => 'administrator'], 'never the administrator');
        $this->assertStringContainsString('HTTP 403', $error);
        $this->assertSame(['treasurer'], $robin->refresh()->activeRoleKeys());
        // The administrator role of somebody cannot be taken either.
        $own = (int) Person::query()->findOrFail($this->user->person_id)->roleAssignments()->value('id');
        $this->assertStringContainsString('HTTP 403', $this->refused($decider, 'remove_role', ['person' => Person::query()->findOrFail($this->user->person_id)->public_id, 'id' => $own]));
        $this->assertSame([], $this->tool($decider, 'remove_role', ['person' => $robin->public_id, 'id' => $given['data'][0]['id']])['data'] ?? null);

        // The REST API keeps roles:write as it is.
        $rest = ApiToken::issue($this->user, 'integration', ['roles:write'])['plain'];
        $this->withToken($rest)->postJson("/api/v1/people/{$robin->public_id}/roles", ['role' => 'treasurer'])->assertCreated();
    }

    public function test_events_templates_rounds_costs_documents_and_places(): void
    {
        $token = $this->token(['events:read', 'events:write', 'finance:read', 'finance:write']);
        $series = (int) ($this->tool($token, 'create_event_series', ['name' => 'Summer camps'])['data']['id'] ?? 0);
        $this->assertSame('Camps', $this->tool($token, 'update_event_series', ['id' => $series, 'name' => 'Camps'])['data']['name'] ?? null);
        $this->assertArrayNotHasKey('type', $this->tool($token, 'list_event_series')['data'][0] ?? []);
        $this->assertCount(1, $this->tool($token, 'list_event_series')['data'] ?? []);

        $event = $this->event(1, 2);
        $id = $event->public_id;
        // The whole event form: bus and prices by age.
        $priced = $this->tool($token, 'update_event', ['id' => $id, 'bus_booked' => true, 'bus_seats' => 40, 'prices' => [['role' => 'child', 'max_age' => 8, 'price' => 380]]]);
        $this->assertSame([true, 40], [$priced['data']['bus_booked'] ?? null, $priced['data']['bus_seats'] ?? null]);
        $this->assertSame(38000, $event->prices()->sole()->price_cents);

        // Templates: from an event, change, a new event from the template, delete.
        $template = $this->tool($token, 'create_event_template', ['event' => $id, 'title' => 'Camp template']);
        $templateId = (string) ($template['data']['id'] ?? '');
        $this->assertContains($templateId, array_column($this->tool($token, 'list_event_templates')['data'] ?? [], 'id'));
        $changed = $this->tool($token, 'update_event_template', ['id' => $templateId, 'location' => 'Lake', 'bus_booked' => false, 'clear' => ['bus_seats', 'title']]);
        $this->assertSame(['Lake', 'Camp template', 'draft', null], [$changed['data']['location'] ?? null, $changed['data']['title'], $changed['data']['status'], $changed['data']['bus_seats']]);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_event_template', ['id' => $templateId, 'status' => 'open'], 'a template stays a draft'));
        $copy = $this->tool($token, 'create_event', ['template' => $templateId, 'title' => 'Camp next year', 'starts_at' => now()->addYear()->toDateString()]);
        $this->assertSame(['Camp next year', 'Lake'], [$copy['data']['title'] ?? null, $copy['data']['location']]);
        $this->assertNull($this->tool($token, 'delete_event_template', ['id' => $templateId])['data'] ?? null);
        $this->assertNotContains($templateId, array_column($this->tool($token, 'list_event_templates')['data'] ?? [], 'id'));
        $this->assertTrue(Event::query()->where('title', 'Camp next year')->exists(), 'events made from the template stay');

        // Registration rounds, costs, documents.
        $rounds = $this->tool($token, 'save_registration_rounds', ['id' => $id, 'rounds' => [['role' => 'child', 'kind' => 'open', 'opens_at' => now()->subDay()->toIso8601String()]]]);
        $this->assertCount(1, $rounds['data'] ?? []);
        $this->assertSame('open', $this->tool($token, 'list_registration_rounds', ['id' => $id])['data'][0]['kind'] ?? null);
        $this->assertSame([], $this->tool($token, 'save_registration_rounds', ['id' => $id, 'rounds' => []])['data'] ?? null);
        $cost = $this->tool($token, 'add_event_cost', ['id' => $id, 'label' => 'House', 'category' => 'accommodation', 'amount' => 1200.5]);
        $costId = (int) ($cost['data'][0]['id'] ?? 0);
        $this->assertEquals(900, $this->tool($token, 'update_event_cost', ['id' => $costId, 'amount' => 900])['total'] ?? null);
        $this->assertCount(1, $this->tool($token, 'list_event_costs', ['id' => $id])['data'] ?? []);
        $this->assertSame([], $this->tool($token, 'delete_event_cost', ['id' => $costId])['data'] ?? null);
        $document = EventDocument::query()->create(['event_id' => $event->id, 'title' => 'Packing list', 'visibility' => 'participants', 'path' => 'event-documents/a.pdf']);
        $this->assertSame('Packing list', $this->tool($token, 'list_event_documents', ['id' => $id])['data'][0]['title'] ?? null);
        $this->tool($token, 'delete_event_document', ['id' => $document->id]);
        $this->assertFalse(EventDocument::query()->whereKey($document->id)->exists());

        // Places: cancel with a reason, a reserve place, release the reserve, an offer out of turn.
        $ann = $this->participation($event, $this->person('Ann', 'Ash'));
        $ben = $this->participation($event, $this->person('Ben', 'Birch'), 'waitlist');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_participation_status', ['id' => $ann->id, 'status' => 'confirmed', 'reason' => 'x'], 'a reason only when cancelling'));
        $this->assertSame('confirmed', $this->tool($token, 'update_participation_status', ['id' => $ann->id, 'status' => 'confirmed'])['data']['status'] ?? null);
        $placed = $this->tool($token, 'assign_reserve_place', ['id' => $ben->id, 'reason' => 'Sibling of a participant']);
        $this->assertNotSame('waitlist', $placed['data']['status'] ?? null);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'assign_reserve_place', ['id' => $ann->id, 'reason' => 'x'], 'only from the waitlist'));
        $released = $this->tool($token, 'release_reserve_places', ['id' => $id, 'role' => 'child', 'count' => 1, 'reason' => 'More beds']);
        $this->assertSame([1, 0], [$released['data']['released'] ?? null, $released['data']['reserve_left'] ?? null]);
        $cancelled = $this->tool($token, 'update_participation_status', ['id' => $ann->id, 'status' => 'cancelled', 'reason' => 'Is ill']);
        $this->assertSame('cancelled', $cancelled['data']['status'] ?? null);
        $this->assertSame('Is ill', $ann->refresh()->getAttribute('cancel_reason'));
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_participation_status', ['id' => $ann->id, 'status' => 'cancelled'], 'not twice'));
        $event->roles()->where('role_key', 'child')->update(['capacity' => 5]);
        $cleo = $this->person('Cleo', 'Cedar');
        $offer = $this->tool($token, 'offer_place', ['id' => $id, 'person' => $cleo->public_id, 'role' => 'child', 'until' => now()->addWeek()->toDateString(), 'reason' => 'Helps in the kitchen']);
        $this->assertSame('offered', $offer['data']['status'] ?? null);
        $this->assertNotEmpty($this->tool($token, 'get_event_schedule', ['id' => $id])['data'] ?? []);

        // Costs are money: not with the scopes for events alone.
        $this->assertNotContains('add_event_cost', $this->listed($this->token(['events:read', 'events:write'])));
    }

    public function test_divisions_lists_and_reports(): void
    {
        $token = $this->token(['events:read', 'events:write']);
        $event = $this->event();
        $people = [];
        foreach ([['Ann', 'Ash', 9], ['Ben', 'Birch', 10], ['Cleo', 'Cedar', 11], ['Dana', 'Dogwood', 12]] as [$first, $last, $age]) {
            $people[] = $this->participation($event, $this->person($first, $last, $age));
        }
        $id = $event->public_id;
        $division = $this->tool($token, 'create_division', ['id' => $id, 'kind' => 'groups', 'name' => 'Groups', 'units' => 2, 'options' => ['siblings' => 'any']]);
        $divisionId = (int) ($division['data']['id'] ?? 0);
        $this->assertCount(4, $division['data']['not_placed'] ?? []);
        $this->assertSame(4, $this->tool($token, 'propose_division', ['id' => $divisionId])['placed'] ?? null);
        $shown = $this->tool($token, 'list_divisions', ['id' => $id]);
        $this->assertSame([], $shown['data'][0]['not_placed'] ?? null);
        $units = array_column($shown['data'][0]['units'], 'id');
        $out = $this->tool($token, 'move_division_member', ['id' => $divisionId, 'participation' => $people[0]->id]);
        $this->assertSame([$people[0]->id], array_column($out['data']['not_placed'] ?? [], 'participation'), 'without a unit the participant is taken out');
        $in = $this->tool($token, 'move_division_member', ['id' => $divisionId, 'participation' => $people[0]->id, 'unit' => $units[1]]);
        $this->assertSame([], $in['data']['not_placed'] ?? null);
        $more = $this->tool($token, 'add_division_unit', ['id' => $divisionId]);
        $this->assertCount(3, $more['data']['units'] ?? []);
        $new = (int) $more['data']['units'][2]['id'];
        $named = $this->tool($token, 'update_division_unit', ['id' => $new, 'name' => 'Owls', 'note' => 'Upstairs', 'capacity' => 6]);
        $this->assertSame(['Owls', 'Upstairs', 6], [$named['data']['units'][2]['name'] ?? null, $named['data']['units'][2]['note'], $named['data']['units'][2]['capacity']]);
        $cleared = $this->tool($token, 'update_division_unit', ['id' => $new, 'clear' => ['note', 'name']])['data']['units'][2] ?? [];
        $this->assertSame(['Owls', null, 6], [$cleared['name'] ?? null, $cleared['note'], $cleared['capacity']], 'only what is named is emptied, and the name cannot be');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'delete_division_unit', ['id' => $units[1]], 'only an empty unit'));
        $this->assertCount(2, $this->tool($token, 'delete_division_unit', ['id' => $new])['data']['units'] ?? []);
        $this->tool($token, 'delete_division', ['id' => $divisionId]);
        $this->assertSame([], $this->tool($token, 'list_divisions', ['id' => $id])['data'] ?? null);
        $this->assertSame(4, Participation::query()->where('event_id', $event->id)->count(), 'the people stay registered');

        $keys = array_column($this->tool($token, 'list_event_lists', ['id' => $id])['data'] ?? [], 'key');
        $this->assertContains('participants', $keys);
        $list = $this->tool($token, 'get_event_list', ['id' => $id, 'key' => 'participants', 'role' => 'child']);
        $this->assertStringContainsString('Dogwood', (string) json_encode($list['data']['rows'] ?? []));
        $statistics = $this->tool($token, 'get_statistics', ['event' => $id, 'groups' => 2]);
        $this->assertSame($id, $statistics['data']['event'] ?? null);
        $this->assertNull($statistics['data']['costs'], 'money only with finance:read');
        $dashboard = $this->tool($token, 'get_dashboard');
        $this->assertArrayHasKey('events', $dashboard['data'] ?? []);
        $this->assertArrayNotHasKey('finance', $dashboard['data']);
    }

    public function test_finance_issue_send_cancel_payments_and_refunds(): void
    {
        $token = $this->token(['finance:read', 'finance:write']);
        $alex = $this->person('Alex', 'Ash', 40);
        $alex->contactPoints()->create(['type' => 'email', 'value' => 'alex.ash@example.org', 'is_primary' => true]);
        $invoice = fn (): Invoice => app(Invoices::class)->single($alex, [['description' => 'Camp', 'amount_cents' => 5000]]);
        $first = $invoice();
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'send_invoice', ['id' => $first->id], 'a draft cannot be sent'));
        $issued = $this->tool($token, 'issue_invoice', ['id' => $first->id, 'send' => false]);
        $this->assertSame(['issued', false], [$issued['data']['status'] ?? null, $issued['sent'] ?? null]);
        $this->assertSame(0, OutgoingMail::query()->count(), 'send false sends nothing');
        $this->assertSame('mcp', AuditEvent::query()->where('subject_type', Invoice::class)->where('subject_id', $first->id)->latest('id')->firstOrFail()->actor_type);
        $this->assertTrue($this->tool($token, 'send_invoice', ['id' => $first->id])['sent'] ?? false);
        $this->assertSame(1, OutgoingMail::query()->where('to_address', 'alex.ash@example.org')->count());
        $second = $invoice();
        $this->assertTrue($this->tool($token, 'issue_invoice', ['id' => $second->id])['sent'] ?? false, 'issuing sends unless send is false');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'cancel_invoice', ['id' => $second->id], 'the reason is required'));
        $credit = $this->tool($token, 'cancel_invoice', ['id' => $second->id, 'reason' => 'Registered twice']);
        $this->assertSame('cancelled', $credit['cancelled']['status'] ?? null);

        $payment = Payment::query()->create(['amount_cents' => 6000, 'paid_on' => now()->toDateString(), 'method' => 'bank', 'match' => 'unmatched', 'payer_name' => 'Alex Ash', 'iban' => 'AT611904300234573201']);
        $this->assertSame(1, $this->tool($token, 'list_payments', ['match' => 'unmatched'])['meta']['total'] ?? null);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'assign_payment', ['id' => $payment->id], 'nothing is suggested'));
        $this->assertSame('manual', $this->tool($token, 'assign_payment', ['id' => $payment->id, 'invoice' => $first->id])['data']['match'] ?? null);
        $this->assertSame('paid', $first->refresh()->status);
        $refunds = $this->tool($token, 'list_refunds', ['status' => 'proposed']);
        $this->assertEquals(10, $refunds['data'][0]['amount'] ?? null);
        $refund = (int) $refunds['data'][0]['id'];
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'mark_refund_done', ['id' => $refund, 'date' => now()->addDay()->toDateString()], 'not in the future'));
        $this->assertSame('declined', $this->tool($token, 'keep_refund_as_credit', ['id' => $refund])['data']['status'] ?? null);
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'mark_refund_done', ['id' => $refund, 'date' => now()->toDateString()], 'only a proposed refund'));

        ArchivedInvoice::query()->create(['number' => 'OLD-17', 'issued_on' => '2024-03-01', 'payer_name' => 'Alex Ash', 'total_cents' => 12000, 'status' => 'paid']);
        $this->assertSame('OLD-17', $this->tool($token, 'list_archived_invoices', ['search' => 'OLD'])['data'][0]['number'] ?? null);
    }

    public function test_own_automations_and_an_assistant_cannot_create_one_without_approval(): void
    {
        $token = $this->token(['automations:read', 'automations:write']);
        $own = [
            'name' => 'Thanks to the team', 'trigger' => 'after_event', 'trigger_config' => ['days' => 2],
            'config' => ['target' => ['source' => 'event', 'roles' => ['staff']], 'steps' => [['type' => 'notify_team', 'text' => 'Say thank you']]],
        ];
        $before = Automation::query()->count();
        // Who approves and when is not for an assistant to choose, also not for a new automation.
        foreach ([['mode' => 'automatic'], ['mode' => 'manual'], ['max_items' => 5000], ['approvals_needed' => 2], ['approver_roles' => ['treasurer']]] as $approval) {
            $error = $this->refused($token, 'create_automation', $own + $approval, json_encode($approval).' is refused');
            $this->assertStringContainsString('HTTP 403', $error);
            $this->assertStringContainsString('approvals:decide', $error);
        }
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'create_automation', ['active' => true] + $own, 'a new automation is switched off'));
        $this->assertSame($before, Automation::query()->count());

        $created = $this->tool($token, 'create_automation', $own);
        $id = (string) ($created['data']['id'] ?? '');
        $key = (string) Automation::query()->whereKey($id)->value('key');
        $this->assertSame(['custom', false, 'confirm'], [$created['data']['action'] ?? null, $created['data']['active'], $created['data']['mode']], 'off, and the approvers decide about every run');
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'Automation.created')->latest('id')->firstOrFail()->actor_type);
        // Switching it on keeps the approval; weakening it afterwards is refused as for every automation.
        $this->assertTrue($this->tool($token, 'update_automation', ['automation' => $id, 'active' => true])['data']['active'] ?? null);
        $this->assertStringContainsString('HTTP 403', $this->refused($token, 'update_automation', ['automation' => $id, 'mode' => 'automatic']));

        $run = AutomationRun::query()->create(['automation_id' => Automation::query()->where('key', $key)->value('id'), 'status' => 'pending_approval', 'trigger' => 'mcp', 'plan' => [], 'expires_at' => now()->addWeek()]);
        $this->assertSame('pending_approval', $this->tool($token, 'get_automation_run', ['id' => $run->id])['data']['status'] ?? null);

        // Deleting removes the automation with its runs; it is not a way around the approval: a new one starts with the approval again.
        $this->assertStringContainsString('HTTP 403', $this->refused($token, 'delete_automation', ['automation' => (string) Automation::query()->where('key', 'mailing_release')->value('id')], 'standard automations stay'));
        $this->assertStringContainsString('HTTP 403', $this->refused($token, 'delete_automation', ['key' => 'mailing_release'], 'also by the key of older versions'));
        $this->assertTrue(Automation::query()->where('key', 'mailing_release')->exists());
        $this->assertNull($this->tool($token, 'delete_automation', ['automation' => $id])['data'] ?? null);
        $this->assertFalse(Automation::query()->where('key', $key)->exists());
        $this->assertFalse(AutomationRun::query()->whereKey($run->id)->exists());
        $this->assertStringContainsString('HTTP 403', $this->refused($token, 'create_automation', $own + ['mode' => 'automatic'], 'delete and create again is no way to run without approval'));
        // An automation that runs without approval and was created by a person cannot be switched on by the assistant.
        $again = Automation::query()->findOrFail($this->tool($token, 'create_automation', $own)['data']['id'] ?? 0);
        $again->update(['mode' => 'automatic']);
        $this->assertStringContainsString('HTTP 403', $this->refused($token, 'update_automation', ['automation' => (string) $again->label(), 'active' => true]));

        // With approvals:decide the token of a person who may decide can choose the approval.
        $decider = $this->token(['automations:read', 'automations:write', 'approvals:decide']);
        $this->assertStringContainsString('HTTP 422', $this->refused($decider, 'create_automation', $own, 'names are unique'));
        $free = $this->tool($decider, 'create_automation', ['name' => 'Thanks without approval'] + $own + ['mode' => 'automatic', 'max_items' => 10]);
        $this->assertSame(['automatic', 10, false], [$free['data']['mode'] ?? null, $free['data']['max_items'], $free['data']['active']]);
    }

    public function test_groups_and_form_answers_respect_the_ai_setting(): void
    {
        $token = $this->token(['people:read', 'people:write', 'health:read', 'health:write']);
        $group = (int) ($this->tool($token, 'create_group', ['name' => 'Helpers', 'kind' => 'manual'])['data']['id'] ?? 0);
        $changed = $this->tool($token, 'update_group', ['id' => $group, 'name' => 'Kitchen helpers', 'description' => 'Cook on Sundays']);
        $this->assertSame(['Kitchen helpers', 'Cook on Sundays'], [$changed['data']['name'] ?? null, $changed['data']['description']]);
        $this->assertNull($this->tool($token, 'update_group', ['id' => $group, 'clear' => ['description', 'name']])['data']['description'] ?? null);
        $this->assertSame('Kitchen helpers', $this->tool($token, 'get_group', ['id' => $group])['data']['name'] ?? null);

        $ann = $this->person('Ann', 'Ash');
        $form = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
        app(Forms::class)->save($form, $ann, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $arguments = ['id' => $ann->public_id, 'form' => 'safety_sheet'];

        // "Non-confidential data": the health scopes of the token do not count via MCP.
        $read = $this->callTool($token, 'get_person_form', $arguments)->assertOk();
        $this->assertStringNotContainsString('Nuts', (string) $read->getContent());
        $shown = (array) json_decode((string) $read->json('result.content.0.text'), true);
        $this->assertSame('yes', $shown['data']['values']['swimmer'] ?? null);
        $this->assertContains('allergies', $shown['data']['hidden']);
        $this->assertArrayNotHasKey('allergies', $shown['data']['values']);
        $saved = $this->tool($token, 'update_person_form', $arguments + ['values' => ['swimmer' => 'no']]);
        $this->assertSame([$form->id, $form->label()], [$saved['data']['form'] ?? null, $saved['data']['name'] ?? null]);
        $error = $this->refused($token, 'update_person_form', $arguments + ['values' => ['allergies' => 'None']], 'health data cannot be written');
        $this->assertStringContainsString('HTTP 403', $error);
        $current = app(Forms::class)->current($form, $ann);
        $this->assertSame(['Nuts', 'no'], [$current->values['allergies'] ?? null, $current->values['swimmer'] ?? null], 'values that are not sent stay');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_person_form', $arguments + ['values' => ['shoe_size' => 38]], 'unknown field'));

        // "All data": with the scopes, and every read is recorded.
        Settings::set('ai_processing', 'all');
        $views = AuditEvent::query()->where('action', 'like', 'form.%view%')->count();
        $this->assertSame('Nuts', $this->tool($token, 'get_person_form', $arguments)['data']['values']['allergies'] ?? null);
        $this->assertGreaterThan($views, AuditEvent::query()->where('action', 'like', 'form.%view%')->count());
        $this->tool($token, 'update_person_form', $arguments + ['values' => ['allergies' => 'Nuts, pollen']]);
        // Without the health scopes, "all data" changes nothing.
        $plain = $this->token(['people:read', 'people:write']);
        $this->assertStringNotContainsString('pollen', (string) $this->callTool($plain, 'get_person_form', $arguments)->getContent());
        $this->assertStringContainsString('HTTP 403', $this->refused($plain, 'update_person_form', $arguments + ['values' => ['allergies' => 'None']]));
    }

    public function test_confidential_lists_of_an_event_respect_the_ai_setting(): void
    {
        $event = $this->event();
        $ann = $this->person('Ann', 'Ash');
        $this->participation($event, $ann);
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $ann, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $token = $this->token(['events:read', 'health:read']);
        $id = $event->public_id;

        $keys = array_column($this->tool($token, 'list_event_lists', ['id' => $id])['data'] ?? [], 'key');
        $this->assertSame([], array_values(array_intersect($keys, ['emergency', 'kitchen', 'medical', 'medication', 'insurance', 'swimmers', 'consents', 'changes'])));
        foreach (['medical', 'kitchen', 'emergency', 'medication', 'insurance', 'swimmers', 'consents', 'changes'] as $key) {
            $response = $this->callTool($token, 'get_event_list', ['id' => $id, 'key' => $key]);
            $this->assertTrue((bool) $response->json('result.isError'), "{$key} is refused");
            $this->assertStringContainsString('HTTP 403', (string) $response->json('result.content.0.text'));
            $this->assertStringNotContainsString('Nuts', (string) $response->getContent());
        }
        $this->assertStringNotContainsString('Nuts', (string) $this->callTool($token, 'get_event_list', ['id' => $id, 'key' => 'answers'])->assertOk()->getContent());
        $this->assertSame(0, AuditEvent::query()->where('action', 'list.viewed')->count());

        Settings::set('ai_processing', 'all');
        $this->assertContains('medical', array_column($this->tool($token, 'list_event_lists', ['id' => $id])['data'] ?? [], 'key'));
        $this->assertStringContainsString('Nuts', (string) json_encode($this->tool($token, 'get_event_list', ['id' => $id, 'key' => 'medical'])));
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'list.viewed')->sole()->actor_type);
        $this->assertStringContainsString('HTTP 403', $this->refused($this->token(['events:read']), 'get_event_list', ['id' => $id, 'key' => 'medical'], 'not without health:read'));
    }

    public function test_mail_templates_log_bad_addresses_and_sender_profiles(): void
    {
        $token = $this->token(['mail:read', 'mail:write', 'settings:write']);
        $invoice = MailTemplate::query()->where('key', 'invoice')->firstOrFail();
        $contents = fn (string $subject) => ['template' => (string) $invoice->id, 'subject' => $subject, 'html' => '<p>Hello</p>'];
        $this->tool($token, 'update_mail_template', $contents('First change'));
        $second = $this->tool($token, 'update_mail_template', $contents('Second change'));
        $this->assertSame('Second change', $this->subject($second));
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_mail_template', ['template' => (string) $invoice->label(), 'subject' => 'x', 'html' => '{{ unknown.placeholder|raw }}{% include "x" %}'], 'checked by the template sandbox'));
        $versions = $this->tool($token, 'list_mail_template_versions', ['template' => (string) $invoice->label()])['data'] ?? [];
        $this->assertCount(3, $versions);
        $restored = $this->tool($token, 'restore_mail_template_version', ['key' => 'invoice', 'version' => 2]);
        $this->assertSame([2, 'First change'], [$restored['data']['restored'] ?? null, $this->subject($restored)]);
        $this->assertCount(4, $this->tool($token, 'list_mail_template_versions', ['key' => 'invoice'])['data'] ?? [], 'restoring is a new version; the argument of older versions still works');

        $robin = $this->person('Robin', 'Birch', 40);
        $point = $robin->contactPoints()->create(['type' => 'email', 'value' => 'robin@example.org', 'is_primary' => true]);
        OutgoingMail::query()->create(['to_address' => 'robin@example.org', 'to_name' => 'Robin Birch', 'person_id' => $robin->id, 'subject' => 'Welcome', 'text' => 'Hello Robin', 'html' => '<p>Hello Robin</p>', 'kind' => 'mailing', 'status' => 'sent', 'sent_at' => now()]);
        $log = $this->tool($token, 'list_outgoing_mails', ['status' => 'sent', 'person' => $robin->public_id]);
        $this->assertSame([1, 'Welcome'], [$log['meta']['total'] ?? null, $log['data'][0]['subject'] ?? null]);
        ContactPoint::query()->whereKey($point->id)->update(['bounced_at' => now(), 'bounce_reason' => 'User unknown']);
        $this->assertSame('robin@example.org', $this->tool($token, 'list_bad_addresses')['data'][0]['address'] ?? null);
        $this->assertNull($this->tool($token, 'clear_bad_address', ['id' => $point->id])['data']['bounced_at'] ?? null);
        $this->assertSame([], $this->tool($token, 'list_bad_addresses')['data'] ?? null);

        $account = MailAccount::query()->create(['name' => 'Office', 'smtp_host' => 'smtp.example.org', 'smtp_username' => 'office', 'smtp_password' => 'smtp-secret-1', 'imap_password' => 'imap-secret-2']);
        $profile = $this->tool($token, 'create_sender_profile', ['name' => 'Camp', 'from_name' => 'Camp team', 'from_address' => 'camp@example.org', 'reply_to' => 'office@example.org', 'mail_account' => $account->id]);
        $updated = $this->tool($token, 'update_sender_profile', ['id' => $profile['data']['id'] ?? 0, 'from_name' => 'The camp team', 'clear' => ['reply_to', 'from_address']]);
        $this->assertSame(['The camp team', 'camp@example.org', null], [$updated['data']['from_name'] ?? null, $updated['data']['from_address'], $updated['data']['reply_to']]);
        $listed = $this->callTool($token, 'list_sender_profiles', [])->assertOk();
        $this->assertStringContainsString('Office', (string) $listed->getContent());
        $this->assertStringNotContainsString('secret', (string) $listed->getContent());
        // Sender profiles are settings: not with the scopes for mail alone.
        $this->assertSame([], array_values(array_intersect(['create_sender_profile', 'update_sender_profile'], $this->listed($this->token(['mail:read', 'mail:write'])))));
    }

    public function test_public_submissions_never_show_confidential_answers(): void
    {
        Settings::set('ai_processing', 'all');
        $token = $this->token(['people:read', 'people:write', 'health:read']);
        $questions = Form::query()->create(['key' => 'team_questions', 'kind' => 'registration', 'name' => ['en' => 'Questions', 'de' => 'Fragen']]);
        $questions->fields()->create(['key' => 'diet', 'type' => 'text', 'label' => ['en' => 'Diet', 'de' => 'Ernährung'], 'sensitivity' => 'health']);
        $questions->fields()->create(['key' => 'skills', 'type' => 'text', 'label' => ['en' => 'Skills', 'de' => 'Kenntnisse']]);
        $form = PublicForm::query()->create(['slug' => 'team', 'kind' => 'application', 'title' => ['en' => 'Join the team', 'de' => 'Mitmachen'], 'active' => true, 'needs_approval' => true, 'form_id' => $questions->id]);
        $make = fn (string $first) => PublicSubmission::query()->create([
            'public_form_id' => $form->id, 'token_hash' => hash('sha256', $first), 'status' => 'pending', 'expires_at' => now()->addDay(), 'confirmed_at' => now(),
            'data' => ['first_name' => $first, 'last_name' => 'Birch', 'email' => strtolower($first).'@example.org', 'values' => ['diet' => 'Coeliac disease', 'skills' => 'Guitar']],
        ]);
        $ben = $make('Ben');
        $cleo = $make('Cleo');
        $this->assertContains('team', array_column($this->tool($token, 'list_public_forms')['data'] ?? [], 'slug')); // next to the sign-up form every installation has

        // Even with health:read and "all data": the answers to confidential questions are not part of the answer.
        $response = $this->callTool($token, 'list_public_submissions', ['status' => 'pending'])->assertOk();
        $this->assertStringNotContainsString('Coeliac', (string) $response->getContent());
        $list = (array) json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertSame(2, $list['meta']['total'] ?? null);
        $this->assertSame(['Guitar', ['diet']], [$list['data'][0]['answers']['values']['skills'] ?? null, $list['data'][0]['hidden']]);

        $accepted = $this->callTool($token, 'accept_public_submission', ['id' => $ben->id])->assertOk();
        $this->assertStringNotContainsString('Coeliac', (string) $accepted->getContent());
        $this->assertSame('done', json_decode((string) $accepted->json('result.content.0.text'), true)['data']['status'] ?? null);
        $this->assertSame(1, Person::query()->where('first_name', 'Ben')->where('last_name', 'Birch')->count());
        $rejected = $this->tool($token, 'reject_public_submission', ['id' => $cleo->id, 'note' => 'Team is complete']);
        $this->assertSame(['declined', 'Team is complete'], [$rejected['data']['status'] ?? null, $rejected['data']['note']]);
        $this->assertNull($cleo->refresh()->data, 'the answers are deleted');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'accept_public_submission', ['id' => $cleo->id], 'only pending ones'));
        $this->assertNotContains('accept_public_submission', $this->listed($this->token(['people:read'])));
    }

    public function test_privacy_export_respects_the_ai_setting_and_anonymise_needs_its_scope(): void
    {
        $read = $this->token(['audit:read', 'people:read', 'health:read']);
        $ann = $this->person('Ann', 'Ash', 30);
        $ann->contactPoints()->create(['type' => 'email', 'value' => 'ann@example.org', 'is_primary' => true]);
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'newsletter', 'granted' => true, 'source' => 'portal']);
        Consent::query()->create(['person_id' => $ann->id, 'kind' => 'deletion_request', 'granted' => true, 'source' => 'portal']);
        $this->assertSame(1, $this->tool($read, 'list_consents', ['kind' => 'newsletter', 'person' => $ann->public_id])['meta']['total'] ?? null);
        $this->assertSame($ann->public_id, $this->tool($read, 'list_deletion_requests')['data'][0]['person'] ?? null);

        // Export without health or safety data.
        $arguments = ['id' => $ann->public_id];
        $export = $this->tool($read, 'export_person_data', $arguments);
        $this->assertSame('ann@example.org', $export['data']['person']['contacts'][0]['value'] ?? null);
        $entry = AuditEvent::query()->where('action', 'privacy.data_exported')->sole();
        $this->assertSame(['mcp', 'mcp'], [$entry->actor_type, $entry->meta['source'] ?? null]);

        // Emergency contacts of the family are safety data.
        $family = Household::factory()->create();
        $family->members()->attach($ann->id, ['role' => 'guardian']);
        $family->emergencyContacts()->create(['name' => 'Olive Oak', 'relation' => 'aunt', 'phone' => '+43 1 555 01', 'sort' => 0]);
        $refused = $this->callTool($read, 'export_person_data', $arguments);
        $this->assertStringContainsString('HTTP 403', (string) $refused->json('result.content.0.text'));
        $this->assertStringNotContainsString('Olive', (string) $refused->getContent());
        $family->emergencyContacts()->delete();
        $this->tool($read, 'export_person_data', $arguments);

        // Answers to confidential questions of a child the person is a legal guardian of.
        $child = $this->person('Cleo', 'Ash');
        $child->guardians()->attach($ann->id);
        app(Forms::class)->save(Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail(), $child, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $refused = $this->callTool($read, 'export_person_data', $arguments);
        $this->assertTrue((bool) $refused->json('result.isError'));
        $this->assertStringContainsString('HTTP 403', (string) $refused->json('result.content.0.text'));
        $this->assertStringNotContainsString('Nuts', (string) $refused->getContent());
        $this->assertSame(2, AuditEvent::query()->where('action', 'privacy.data_exported')->count(), 'a refused export is no export');

        Settings::set('ai_processing', 'all');
        $family->emergencyContacts()->create(['name' => 'Olive Oak', 'relation' => 'aunt', 'phone' => '+43 1 555 01', 'sort' => 0]);
        $full = (string) json_encode($this->tool($read, 'export_person_data', $arguments));
        $this->assertStringContainsString('Nuts', $full);
        $this->assertStringContainsString('Olive Oak', $full);
        $this->assertStringContainsString('HTTP 403', $this->refused($this->token(['audit:read', 'people:read']), 'export_person_data', $arguments, 'not without health:read'));
        Settings::set('ai_processing', 'non_confidential');

        // Anonymise: own scope, preview first, the reason is required.
        $this->assertNotContains('anonymise_person', $this->listed($this->token(['audit:read', 'people:read', 'people:write', 'health:read'])));
        $this->refused($this->token(['people:write', 'audit:read']), 'anonymise_person', $arguments + ['reason' => 'Asked for it']);
        $write = $this->token(['privacy:write']);
        $preview = $this->tool($write, 'anonymise_person', $arguments + ['preview' => true]);
        $this->assertSame([false, 1], [$preview['data']['anonymised'] ?? null, $preview['data']['plan']['delete']['contact_points'] ?? null]);
        $this->assertStringContainsString('HTTP 422', $this->refused($write, 'anonymise_person', $arguments, 'the reason is required'));
        $this->assertNull($ann->refresh()->anonymised_at);
        $admin = Person::query()->findOrFail($this->user->person_id);
        $this->assertStringContainsString('HTTP 422', $this->refused($write, 'anonymise_person', ['id' => $admin->public_id, 'reason' => 'x'], 'not while the person has a role'));
        $this->assertTrue($this->tool($write, 'anonymise_person', $arguments + ['reason' => 'Asked for it'])['data']['anonymised'] ?? false);
        $this->assertNotNull($ann->refresh()->anonymised_at);
        $this->assertSame(0, $ann->contactPoints()->count());
    }

    public function test_settings_and_webhooks_keep_approvals_addresses_and_secrets_from_an_assistant(): void
    {
        $token = $this->token(['settings:write']);
        $settings = $this->tool($token, 'get_settings')['data'] ?? [];
        $this->assertArrayHasKey('organisation_name', $settings);
        foreach (['ai_processing', 'bank_iban', 'public_url', 'mail_test_address'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $settings);
        }
        Settings::set('organisation_address', 'Elm Road 1');
        $changed = $this->tool($token, 'update_settings', ['organisation_name' => 'Camp Birch', 'invoice_attach_paid' => false, 'clear' => ['organisation_address', 'organisation_name']]);
        $this->assertSame('Camp Birch', $changed['data']['organisation_name'] ?? null);
        $this->assertEmpty($changed['data']['organisation_address']);
        $this->assertSame('mcp', AuditEvent::query()->where('action', 'settings.updated')->latest('id')->firstOrFail()->actor_type);
        // The AI setting and the other protected settings cannot be changed here.
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'update_settings', ['ai_processing' => 'all']));
        $this->assertSame('non_confidential', Settings::get('ai_processing'));

        // From how many recipients a mailing needs an approval is an approval setting.
        Settings::set('mailing_approval_above', '50');
        foreach ([0, 100000] as $limit) {
            $error = $this->refused($token, 'update_settings', ['mailing_approval_above' => $limit, 'organisation_name' => 'Changed anyway']);
            $this->assertStringContainsString('HTTP 403', $error);
            $this->assertStringContainsString('approvals:decide', $error);
        }
        $this->assertSame(['50', 'Camp Birch'], [(string) Settings::get('mailing_approval_above'), Settings::get('organisation_name')]);
        $this->tool($token, 'update_settings', ['mailing_approval_above' => 50, 'max_guardians' => 3]); // the same value is no change
        $decider = $this->token(['settings:write', 'approvals:decide']);
        $this->assertEquals(80, $this->tool($decider, 'update_settings', ['mailing_approval_above' => 80])['data']['mailing_approval_above'] ?? null);
        $rest = ApiToken::issue($this->user, 'integration', ['settings:write'])['plain'];
        $this->withToken($rest)->patchJson('/api/v1/settings', ['mailing_approval_above' => 60])->assertOk();

        // Webhooks: read, rename, switch, delete – no secret, no new address.
        $hook = Webhook::query()->create(['name' => 'crm', 'url' => 'https://hooks.example.org/kasmin', 'events' => ['participation.created'], 'secret' => 'top-secret-value', 'active' => true]);
        $list = $this->callTool($token, 'list_webhooks', [])->assertOk();
        $this->assertStringContainsString('hooks.example.org', (string) $list->getContent());
        $this->assertStringNotContainsString('top-secret-value', (string) $list->getContent());
        $off = $this->tool($token, 'update_webhook', ['id' => $hook->id, 'name' => 'CRM', 'active' => false, 'events' => ['person.created']]);
        $this->assertSame(['CRM', false, ['person.created']], [$off['data']['name'] ?? null, $off['data']['active'], $off['data']['events']]);
        $error = $this->refused($token, 'update_webhook', ['id' => $hook->id, 'url' => 'https://elsewhere.example.org/collect', 'active' => true], 'no new address');
        $this->assertStringContainsString('HTTP 403', $error);
        $hook->refresh();
        $this->assertSame(['https://hooks.example.org/kasmin', false], [$hook->url, $hook->active]);
        $this->withToken($rest)->patchJson("/api/v1/webhooks/{$hook->id}", ['url' => 'https://new.example.org/kasmin'])->assertOk();
        $this->assertNull($this->tool($token, 'delete_webhook', ['id' => $hook->id])['data'] ?? null);
        $this->assertSame(0, Webhook::query()->count());
    }

    public function test_family_suggestions_for_an_import(): void
    {
        $token = $this->token(['import:write']);
        $rows = [
            ['first_name' => 'Ann', 'last_name' => 'Ash', 'birth_date' => '2015-04-01', 'guardian1_first_name' => 'Robin', 'guardian1_last_name' => 'Ash', 'guardian1_email' => 'robin@example.org'],
            ['first_name' => 'Ben', 'last_name' => 'Ash', 'birth_date' => '2013-06-01', 'guardian1_first_name' => 'Robin', 'guardian1_last_name' => 'Ash', 'guardian1_email' => 'robin@example.org'],
        ];
        $people = Person::query()->count();
        $this->assertNotEmpty($this->tool($token, 'suggest_import_families', ['source' => 'old-list', 'rows' => $rows])['data'] ?? []);
        $this->assertSame($people, Person::query()->count(), 'nothing is changed');
        $this->assertStringContainsString('HTTP 422', $this->refused($token, 'suggest_import_families', ['rows' => $rows], 'the source is required'));
    }
}
