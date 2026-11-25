<?php

namespace Tests\Feature\System;

use App\Filament\Support\InitialsAvatar;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Event;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Headers that the dynamic scan of the running application checks (Q05, CI job "dast", tests/zap).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
    }

    private function admin(): User
    {
        $person = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Example', 'locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);

        return User::query()->create(['name' => 'Alex Example', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, string> directive => sources
     */
    private function policy(TestResponse $response): array
    {
        $policy = [];
        foreach (explode('; ', (string) $response->headers->get('Content-Security-Policy')) as $directive) {
            [$name, $sources] = explode(' ', $directive, 2);
            $policy[$name] = $sources;
        }

        return $policy;
    }

    public function test_pages_only_load_from_kasmin_and_only_send_forms_to_it(): void
    {
        $policy = $this->policy($this->get('/login')->assertOk());
        $this->assertSame("'self'", $policy['default-src']);
        $this->assertSame("'self' 'unsafe-inline'", $policy['script-src']);
        $this->assertSame("'self' 'unsafe-inline'", $policy['style-src']);
        $this->assertSame("'self' data: blob:", $policy['img-src']);
        $this->assertSame("'self'", $policy['connect-src']);
        $this->assertSame("'none'", $policy['object-src']);
        $this->assertSame("'self'", $policy['base-uri']);
        $this->assertSame("'self'", $policy['form-action']);
        $this->assertSame("'self'", $policy['frame-ancestors']);
        foreach ($policy as $sources) {
            $this->assertStringNotContainsString('*', $sources);
            $this->assertStringNotContainsString('http', $sources);
        }
    }

    public function test_only_the_admin_interface_may_evaluate_expressions(): void
    {
        $this->actingAs($this->admin())->withSession(['two_factor_passed' => true]);
        $this->assertSame("'self' 'unsafe-inline' 'unsafe-eval'", $this->policy($this->get('/admin')->assertOk())['script-src']);
        $this->assertSame("'self' 'unsafe-inline'", $this->policy($this->get('/portal')->assertOk())['script-src']);
    }

    public function test_every_answer_has_the_headers_also_errors_and_the_api(): void
    {
        foreach (['/login', '/no-such-page', '/api/v1/public/events', '/api/v1/people', '/up'] as $url) {
            $response = $this->get($url);
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'same-origin')
                ->assertHeader('Permissions-Policy', SecurityHeaders::PERMISSIONS)->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $this->assertArrayHasKey('default-src', $this->policy($response), $url);
        }
        $this->assertStringContainsString('camera=()', SecurityHeaders::PERMISSIONS);
    }

    public function test_pages_are_not_stored_but_public_files_may_be(): void
    {
        $this->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->admin())->withSession(['two_factor_passed' => true]);
        $this->get('/portal')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('no-store', (string) $this->get('/admin')->assertOk()->headers->get('Cache-Control'));

        Storage::fake('local');
        Storage::disk('local')->put('events/camp.png', 'png');
        $event = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addWeek(), 'status' => 'open', 'public_page' => true, 'image_path' => 'events/camp.png']);
        $cache = (string) $this->get('/events/'.$event->public_id.'/image')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $cache);
        $this->assertStringNotContainsString('no-store', $cache);
    }

    public function test_health_page_loads_nothing_from_other_servers(): void
    {
        $response = $this->get('/up')->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_avatar_of_the_admin_interface_is_made_on_the_server(): void
    {
        $user = $this->admin();
        $avatar = (new InitialsAvatar)->get($user);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringContainsString('>AE</text>', (string) base64_decode(substr($avatar, 26)));
        $this->actingAs($user)->withSession(['two_factor_passed' => true])->get('/admin')->assertOk()->assertDontSee('ui-avatars.com')->assertSee($avatar, false);
    }
}
