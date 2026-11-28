<?php

namespace Tests\Feature\Api;

use App\Mail\ConfirmSubmissionMail;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\AutomationRun;
use App\Models\Event;
use App\Models\Form;
use App\Models\Group;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\PublicForm;
use App\Models\PublicSubmission;
use App\Models\Role;
use App\Models\User;
use App\Support\Forms\Forms;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiCompleteTest extends TestCase
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
    private function token(array $scopes, bool $mcp = false): string
    {
        return ApiToken::issue($this->user, 'test', $scopes, $mcp)['plain'];
    }

    public function test_every_endpoint_needs_its_scope(): void
    {
        $none = $this->token(['audit:read']);
        $p = Person::factory()->create()->public_id;
        $g = Group::query()->create(['name' => 'G', 'kind' => 'manual'])->id;
        foreach ([['get', '/groups'], ['post', '/groups'], ['get', '/groups/'.$g], ['patch', '/groups/'.$g], ['post', "/groups/$g/members"], ['delete', "/groups/$g/members/$p"], ['get', '/forms'], ['get', "/people/$p/forms"],
            ['get', "/people/$p/forms/safety_sheet"], ['patch', "/people/$p/forms/safety_sheet"], ['get', '/mail-templates'], ['get', '/mail-templates/newsletter'], ['patch', '/mail-templates/newsletter'], ['post', '/mail-templates/newsletter/preview'],
            ['get', '/mailings'], ['post', '/mailings'], ['get', '/settings'], ['patch', '/settings']] as [$method, $path]) {
            $this->withToken($none)->json($method, '/api/v1'.$path)->assertForbidden();
        }
    }

    public function test_groups(): void
    {
        $token = $this->token(['people:read', 'people:write']);
        $ann = Person::factory()->create(['birth_date' => now()->subYears(14)->toDateString()]);
        $id = (int) $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'Choir', 'kind' => 'manual'])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/groups/$id/members", ['person' => $ann->public_id])->assertCreated()->assertJsonPath('data.members_count', 1);
        $this->withToken($token)->postJson("/api/v1/groups/$id/members", ['person' => $ann->public_id])->assertOk()->assertJsonPath('data.members_count', 1); // already a member
        $this->withToken($token)->getJson("/api/v1/groups/$id")->assertJsonPath('data.members.0.id', $ann->public_id);
        $this->withToken($token)->deleteJson("/api/v1/groups/$id/members/{$ann->public_id}")->assertOk()->assertJsonPath('data.members_count', 0);
        $dynamic = $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'Teens', 'kind' => 'dynamic', 'filter' => ['match' => 'all', 'rules' => [['field' => 'age', 'min' => 14, 'max' => 14]]]])->assertCreated();
        $dynamic->assertJsonPath('data.members_count', 1);
        $this->withToken($token)->postJson('/api/v1/groups/'.$dynamic->json('data.id').'/members', ['person' => $ann->public_id])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'X', 'kind' => 'dynamic', 'filter' => ['rules' => [['field' => 'password']]]])->assertUnprocessable();
    }

    public function test_confidential_form_values_need_the_health_scope(): void
    {
        $child = Person::factory()->create(['birth_date' => now()->subYears(10)->toDateString()]);
        $sheet = Form::query()->where('key', 'safety_sheet')->with('fields')->firstOrFail();
        app(Forms::class)->save($sheet, $child, ['allergies' => 'Nuts', 'swimmer' => 'yes'], null);
        $url = "/api/v1/people/{$child->public_id}/forms/safety_sheet";
        $normal = $this->withToken($this->token(['people:read', 'people:write']))->getJson($url)->assertOk();
        $this->assertStringNotContainsString('Nuts', (string) $normal->getContent());
        $normal->assertJsonPath('data.values.swimmer', 'yes');
        $this->assertNotContains('allergies', (array) $normal->json('data.versions.0.changed_fields'));
        $this->assertSame(0, AuditEvent::query()->where('action', 'form.viewed')->count());
        $this->withToken($this->token(['people:read', 'health:read']))->getJson($url)->assertJsonPath('data.values.allergies', 'Nuts');
        $this->assertSame(1, AuditEvent::query()->where('action', 'form.viewed')->count());

        $this->withToken($this->token(['people:write', 'health:read']))->patchJson($url, ['values' => ['allergies' => 'Wasps']])->assertForbidden();
        $this->withToken($this->token(['people:write']))->patchJson($url, ['values' => ['swimmer' => 'maybe']])->assertUnprocessable();
        $this->withToken($this->token(['people:write', 'health:write']))->patchJson($url, ['values' => ['allergies' => 'Wasps']])->assertOk()->assertJsonPath('data.status', 'missing');
        $this->assertSame(['allergies' => 'Wasps', 'swimmer' => 'yes'], collect(app(Forms::class)->current($sheet, $child)?->values)->sortKeys()->all());
        $this->assertStringNotContainsString('Wasps', (string) AuditEvent::query()->get()->toJson());

        $child->update(['birth_date' => now()->subYears(17)->toDateString()]);
        $old = $this->withToken($this->token(['people:read', 'health:read']))->getJson($url)->assertJsonPath('data.status', 'not_applicable');
        $this->assertStringNotContainsString('Wasps', (string) $old->getContent());
    }

    public function test_templates_are_checked_and_versioned(): void
    {
        $token = $this->token(['mail:read', 'mail:write']);
        $this->withToken($token)->patchJson('/api/v1/mail-templates/newsletter', ['contents' => [['locale' => 'en', 'subject' => 'S', 'html' => '{{ recipient.password }}']]])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/v1/mail-templates/newsletter', ['contents' => [['locale' => 'en', 'subject' => 'News', 'html' => '<p>{{ salutation }}</p>']]])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(2, MailTemplate::query()->where('key', 'newsletter')->firstOrFail()->versions()->count());
        $this->withToken($token)->postJson('/api/v1/mail-templates/newsletter/preview', ['locale' => 'en', 'html' => '<p>{{ recipient.full_name }}</p>'])->assertOk()->assertJsonPath('data.html', '<p>Alex Example</p>');
        $this->withToken($token)->postJson('/api/v1/mail-templates/newsletter/preview', ['html' => '{% include "x" %}'])->assertUnprocessable();
    }

    public function test_mailings_always_need_a_human_unless_the_token_may_decide(): void
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->contactPoints()->create(['type' => 'email', 'value' => 'a@example.org', 'is_primary' => true]);
        $newsletter = MailTemplate::query()->where('key', 'newsletter')->firstOrFail();
        $make = function (string $token) use ($person, $newsletter): int {
            $id = (int) $this->withToken($token)->postJson('/api/v1/mailings', ['name' => 'M', 'template' => $newsletter->label(), 'people' => [$person->public_id], 'routing' => 'person'])->assertCreated()
                ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.template', $newsletter->id)->assertJsonPath('data.template_name', $newsletter->label())->json('data.id');
            $this->withToken($token)->postJson("/api/v1/mailings/$id/release")->assertUnprocessable();
            $this->withToken($token)->postJson("/api/v1/mailings/$id/prepare")->assertOk()->assertJsonPath('data.mails_count', 1);

            return $id;
        };
        $token = $this->token(['mail:read', 'mail:write']);
        $id = $make($token);
        $this->withToken($token)->getJson("/api/v1/mailings/$id")->assertJsonPath('data.recipients.0.id', $person->public_id);
        $this->withToken($token)->postJson("/api/v1/mailings/$id/release")->assertStatus(202)->assertJsonPath('data.status', 'waiting');
        $this->assertSame(0, OutgoingMail::query()->where('mailing_id', $id)->where('status', 'queued')->count());
        $this->assertSame('pending_approval', AutomationRun::query()->sole()->status);
        $may = $this->token(['mail:read', 'mail:write', 'approvals:decide']);
        $second = $make($may);
        $this->withToken($may)->postJson("/api/v1/mailings/$second/release")->assertStatus(202)->assertJsonPath('data.status', 'sending');
    }

    public function test_settings_without_the_dangerous_ones(): void
    {
        $token = $this->token(['settings:write']);
        $this->withToken($token)->patchJson('/api/v1/settings', ['organisation_name' => 'New', 'max_guardians' => 4])->assertOk()->assertJsonPath('data.organisation_name', 'New');
        foreach (['public_url', 'mail_test_address', 'ai_processing', 'organisation_iban', 'embed_origins', 'timezone'] as $key) {
            $this->withToken($token)->patchJson('/api/v1/settings', [$key => 'https://attacker.example.org'])->assertUnprocessable();
            $this->assertArrayNotHasKey($key, (array) $this->withToken($token)->getJson('/api/v1/settings')->json('data'));
        }
        $this->assertSame('4', Settings::get('max_guardians'));
    }

    public function test_public_endpoints_show_only_public_things(): void
    {
        Mail::fake();
        $event = Event::query()->create(['title' => 'Public', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'status' => 'open', 'public_page' => true]);
        $event->roles()->create(['role_key' => 'staff', 'capacity' => 5, 'registered_by' => 'admin']);
        Event::query()->create(['title' => 'Hidden', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'status' => 'open']);
        $this->assertSame(['Public'], array_column((array) $this->getJson('/api/v1/public/events')->assertOk()->assertJsonPath('data.0.roles', [])->json('data'), 'title'));
        PublicForm::query()->create(['slug' => 'news', 'kind' => 'interest', 'title' => ['en' => 'News'], 'active' => true]);
        PublicForm::query()->create(['slug' => 'off', 'kind' => 'interest', 'title' => ['en' => 'Off'], 'active' => false]);
        $this->getJson('/api/v1/public/forms/news')->assertOk()->assertJsonPath('data.state', 'open');
        $this->getJson('/api/v1/public/forms/off')->assertNotFound();
        $data = ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org', 'locale' => 'en'];
        $this->postJson('/api/v1/public/forms/news', $data)->assertUnprocessable();
        $known = $this->postJson('/api/v1/public/forms/news', ['email' => 'owner@example.org', 'privacy' => true] + $data)->assertStatus(202)->getContent();
        $this->assertSame($known, $this->postJson('/api/v1/public/forms/news', ['privacy' => true] + $data)->assertStatus(202)->getContent(), 'known addresses are not revealed');
        Mail::assertSent(ConfirmSubmissionMail::class, 2);
        $this->assertSame(2, PublicSubmission::query()->where('status', 'unconfirmed')->count());
    }

    public function test_idempotency_keys(): void
    {
        $token = $this->token(['people:write']);
        $send = fn (string $name, string $key, ?string $with = null) => $this->withToken($with ?? $token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/groups', ['name' => $name, 'kind' => 'manual']);
        $first = $send('A', 'k1')->assertCreated();
        $again = $send('A', 'k1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $send('B', 'k1')->assertUnprocessable();
        $send('A', 'k1', $this->token(['people:write']))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, Group::query()->where('name', 'A')->count(), 'keys belong to one token');
        $this->assertStringNotContainsString('"name"', (string) DB::table('api_idempotency_keys')->value('body'), 'encrypted');
        DB::table('api_idempotency_keys')->where('status', 201)->limit(1)->update(['status' => null]);
        $send('A', 'k1')->assertStatus(409);
        $this->travel(25)->hours();
        $send('A', 'k1')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_mcp_lists_the_new_tools_by_scope_and_keeps_health_data_away(): void
    {
        Settings::set('ai_processing', 'non_confidential');
        $rpc = fn (string $token, string $method, array $params = []) => $this->withToken($token)->withHeaders(['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $token = $this->token(['people:read', 'mail:read'], true);
        $names = [];
        $cursor = null;
        do {
            $page = $rpc($token, 'tools/list', $cursor !== null ? ['cursor' => $cursor] : [])->assertOk();
            $names = [...$names, ...array_column((array) $page->json('result.tools'), 'name')];
            $cursor = $page->json('result.nextCursor');
        } while ($cursor !== null);
        foreach (['list_groups', 'get_group', 'list_forms', 'get_person_forms', 'list_mail_templates', 'list_mailings', 'preview_mail_template'] as $tool) {
            $this->assertContains($tool, $names);
        }
        foreach (['create_group', 'create_mailing', 'request_mailing_release'] as $tool) {
            $this->assertNotContains($tool, $names);
        }
        Group::query()->create(['name' => 'Choir', 'kind' => 'manual']);
        $this->assertStringContainsString('Choir', (string) $rpc($token, 'tools/call', ['name' => 'list_groups', 'arguments' => (object) []])->json('result.content.0.text'));
    }
}
