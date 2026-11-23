<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginLinkMail;
use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Install\Defaults;
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

    public function test_code_login_and_brute_force_protection(): void
    {
        $user = $this->guardian();
        ['code' => $code] = app(LoginLinks::class)->issue($user);
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/code', ['email' => $user->email, 'code' => $wrong])->assertSessionHasErrors('code');
        }
        // After five wrong attempts even the right code no longer works.
        $this->post('/login/code', ['email' => $user->email, 'code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        ['code' => $fresh] = app(LoginLinks::class)->issue($user);
        $this->post('/login/code', ['email' => $user->email, 'code' => $fresh])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
    }

    public function test_administrators_need_a_second_factor(): void
    {
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

        $this->actingAs($user)->get('/portal')->assertOk()->assertSee('Family Example')->assertSee('Kim Example')->assertSee('10');
    }
}
