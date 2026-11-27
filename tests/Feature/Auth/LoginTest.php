<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginLinkMail;
use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Install\Defaults;
use App\Support\Install\MailTemplateDefaults;
use App\Support\Mail\Composer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('kasmin:upgrade', ['--no-backup' => true])->run(); // @phpstan-ignore method.nonObject
        app(Defaults::class)->install('en');
    }

    private function guardian(string $email = 'guardian@example.org'): User
    {
        $person = Person::factory()->create(['first_name' => 'Robin', 'last_name' => 'Example']);
        $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login']]);

        return User::query()->create(['name' => 'Robin Example', 'email' => $email, 'password' => Str::password(64), 'person_id' => $person->id]);
    }

    public function test_unknown_address_gets_the_same_answer_and_no_mail(): void
    {
        Mail::fake();
        $this->post('/login', ['email' => 'nobody@example.org'])->assertRedirect('/login/code');
        Mail::assertNothingSent();
    }

    public function test_magic_link_needs_a_click_and_works_once(): void
    {
        Mail::fake();
        $user = $this->guardian();
        $this->post('/login', ['email' => 'Guardian@Example.org'])->assertRedirect('/login/code');

        $url = null;
        Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });
        $path = (string) parse_url((string) $url, PHP_URL_PATH);

        $this->get($path)->assertOk()->assertSee('Continue');
        $this->assertGuest();

        $this->post($path)->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
        $event = AuditEvent::query()->where('action', 'login.succeeded')->firstOrFail();
        $this->assertSame('magic_link', $event->meta['method'] ?? null);
        $this->assertNotNull($event->meta['token_id'] ?? null);

        auth()->logout();
        $this->post($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_cli_login_link_works_once_and_is_audited(): void
    {
        $user = User::query()->create(['name' => 'Robin', 'email' => 'robin@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $this->artisan('kasmin:login-link', ['email' => 'nobody@example.org'])->assertExitCode(1); // @phpstan-ignore method.nonObject
        $this->artisan('kasmin:login-link', ['email' => 'Robin@Example.org'])->assertExitCode(0); // @phpstan-ignore method.nonObject
        $token = $user->loginTokens()->latest('id')->firstOrFail();
        $this->assertSame('cli', $token->purpose);
        $this->assertTrue(AuditEvent::query()->where('action', 'login.token_issued')->exists());

        // The plain token is only printed; issue a known one the same way to test the flow.
        ['token' => $plain] = app(LoginLinks::class)->issue($user, 'cli');
        $this->post('/login/link/'.$plain)->assertRedirect();
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->post('/login/link/'.$plain)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_rate_limits_are_separate_per_purpose(): void
    {
        // Ten invalid link attempts must not block requesting a login link (own counter per purpose).
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login/link/invalid-'.$i);
        }
        $this->post('/login', ['email' => 'nobody@example.org'])->assertRedirect('/login/code');
    }

    public function test_code_login_and_brute_force_protection(): void
    {
        $user = $this->guardian();
        ['code' => $code] = app(LoginLinks::class)->issue($user);
        $this->assertMatchesRegularExpression('/^[0-9A-HJ-NP-RTV-Z]{3}-[0-9A-HJ-NP-RTV-Z]{3}$/', $code);
        $wrong = $code === '111-111' ? '222-222' : '111-111';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/code', ['email' => $user->email, 'code' => $wrong])->assertSessionHasErrors('code');
        }
        // After five wrong attempts the code entry is closed for this person – also for a fresh code …
        $this->post('/login/code', ['email' => $user->email, 'code' => $code])->assertSessionHasErrors('code');
        ['code' => $fresh, 'token' => $link] = app(LoginLinks::class)->issue($user);
        $this->post('/login/code', ['email' => $user->email, 'code' => $fresh])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'login.code_locked']);

        // … while the link in the mail still works, and after 30 minutes codes work again.
        $this->post('/login/link/'.$link)->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->travel(31)->minutes();
        ['code' => $later] = app(LoginLinks::class)->issue($user);
        $this->post('/login/code', ['email' => $user->email, 'code' => $later])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
    }

    public function test_codes_may_be_typed_in_lower_case_without_hyphen_and_with_look_alikes(): void
    {
        $user = $this->guardian();
        ['code' => $code] = app(LoginLinks::class)->issue($user);
        $typed = strtr(strtolower(str_replace('-', ' ', $code)), ['0' => 'o', '1' => 'i', '5' => 's', 'v' => 'u']);
        $this->post('/login/code', ['email' => $user->email, 'code' => $typed])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
    }

    public function test_only_the_code_of_the_newest_mail_counts_but_older_links_work(): void
    {
        $user = $this->guardian();
        ['code' => $old, 'token' => $oldLink] = app(LoginLinks::class)->issue($user);
        ['code' => $new] = app(LoginLinks::class)->issue($user);
        if ($old !== $new) {
            $this->post('/login/code', ['email' => $user->email, 'code' => $old])->assertSessionHasErrors('code');
        }
        $this->post('/login/link/'.$oldLink)->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
    }

    public function test_many_requested_mails_give_no_more_guesses(): void
    {
        $user = $this->guardian();
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = app(LoginLinks::class)->issue($user)['code'];
        }
        // Wrong guesses are counted per person, not per open code.
        // From many IP addresses, so the limit per address of the requests does not hide it.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$i])->post('/login/code', ['email' => $user->email, 'code' => 'ZZZ-ZZ'.$i])->assertSessionHasErrors('code');
        }
        foreach ($codes as $n => $code) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.'.$n])->post('/login/code', ['email' => $user->email, 'code' => $code])->assertSessionHasErrors('code');
        }
        $this->assertGuest();
    }

    public function test_login_mails_to_one_address_are_limited_whatever_the_ip(): void
    {
        Mail::fake();
        $user = $this->guardian();
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $user->email])->assertRedirect('/login/code');
        }
        Mail::assertSent(LoginLinkMail::class, 3);
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->post('/login', ['email' => $user->email]);
        Mail::assertSent(LoginLinkMail::class, 4);
    }

    public function test_administrators_need_a_second_factor_if_it_is_switched_on(): void
    {
        config(['kasmin.second_factor' => true]);
        $user = $this->guardian('admin@example.org');
        $user->person?->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);
        ['token' => $token] = app(LoginLinks::class)->issue($user);

        $this->post('/login/link/'.$token)->assertRedirect('/two-factor');
        $this->get('/admin')->assertRedirect('/two-factor');
        $this->get('/two-factor')->assertOk()->assertSee('QR');

        $secret = (string) session('two_factor_setup_secret');
        $this->post('/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/admin');
        $this->assertNotNull($user->fresh()?->two_factor_confirmed_at);
        $this->get('/admin')->assertOk();
        foreach (['/admin/people', '/admin/households', '/admin/events', '/admin/event-series', '/admin/groups'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_guardians_cannot_open_the_admin_interface(): void
    {
        $user = $this->guardian();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_portal_shows_the_children_of_the_family(): void
    {
        $user = $this->guardian();
        $household = Household::factory()->create(['name' => 'Family Example']);
        $household->members()->attach($user->person_id, ['role' => 'guardian']);
        $child = Person::factory()->create(['first_name' => 'Kim', 'last_name' => 'Example', 'birth_date' => now()->subYears(10)]);
        $household->members()->attach($child->id, ['role' => 'child']);
        $child->guardians()->attach($user->person_id);

        $this->actingAs($user)->get('/portal')->assertOk()->assertSee('Family Example')->assertSee('Kim Example')->assertSee('10');
    }

    private function admin(string $role, string $email): User
    {
        app(Defaults::class)->installRoles();
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $email, 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_every_administrative_login_is_reported_to_all_administrators(): void
    {
        app(MailTemplateDefaults::class)->install();
        $this->admin('administrator', 'a1@example.org');
        $this->admin('administrator', 'a2@example.org');
        $treasurer = $this->admin('treasurer', 't@example.org');
        ['token' => $token] = app(LoginLinks::class)->issue($treasurer);
        $this->post('/login/link/'.$token)->assertRedirect('/admin');

        $mails = OutgoingMail::query()->where('subject', 'Administrator login: treasurer')->get();
        $this->assertEqualsCanonicalizing(['a1@example.org', 'a2@example.org'], $mails->pluck('to_address')->all());
        $this->assertStringContainsString('t@example.org', $mails->first()->html ?? '');
        $this->assertStringContainsString('127.0.0.1', $mails->first()->html ?? '');
        $this->assertEmpty($treasurer->refresh()->getRememberToken(), 'administrative roles are not remembered');
        auth()->logout();

        $guardian = User::query()->create(['name' => 'G', 'email' => 'g@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        ['token' => $token] = app(LoginLinks::class)->issue($guardian);
        $this->post('/login/link/'.$token);
        $this->assertSame(2, OutgoingMail::query()->where('subject', 'like', 'Administrator login%')->count(), 'not for other people');
    }

    public function test_links_in_mails_do_not_open_the_admin_interface_or_safety_sheets(): void
    {
        Mail::fake();
        $admin = $this->admin('administrator', 'a1@example.org');
        $child = Person::factory()->create(['birth_date' => now()->subYears(9)]);
        $child->guardians()->attach($admin->person_id);
        ['token' => $token] = app(LoginLinks::class)->issue($admin, 'mail_link', 'limited');
        $this->post('/login/link/'.$token)->assertRedirect(route('portal'));
        $this->assertEmpty($admin->refresh()->getRememberToken());

        $this->get('/portal')->assertOk();
        foreach (['/admin', '/admin/people', '/portal/children/'.$child->public_id.'/forms/safety_sheet', '/portal/children/new'] as $page) {
            $this->get($page)->assertRedirect(route('step-up'));
        }
        $this->post('/portal/children', ['first_name' => 'X', 'last_name' => 'Y', 'birth_date' => '2016-01-01', 'gender' => 'male'])->assertRedirect(route('step-up'));
        $this->assertFalse(Person::query()->where('first_name', 'X')->exists());

        // Fresh link to the own address, then the page that was asked for.
        $this->get('/admin/people');
        $this->get('/login/confirm')->assertOk();
        $this->post('/login/confirm')->assertRedirect('/login/code');
        $this->assertGuest();
        $code = null;
        Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('a1@example.org');
        });
        $this->post('/login/code', ['email' => 'a1@example.org', 'code' => $code])->assertRedirect('/admin/people');
        $this->get('/admin/people')->assertOk();
    }

    public function test_approval_requests_are_not_shown_among_the_mails_in_the_portal(): void
    {
        $admin = $this->admin('administrator', 'a1@example.org');
        $mail = app(Composer::class)->queueRaw('a1@example.org', 'A', 'Approval needed: secret run', '<p>x</p>', 'en', 'notification', $admin->person_id);
        $this->actingAs($admin)->get('/portal')->assertOk()->assertDontSee('secret run');
        $this->actingAs($admin)->get('/portal/mails/'.$mail->uuid)->assertNotFound();
    }
}
