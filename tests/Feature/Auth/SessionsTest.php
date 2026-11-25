<?php

namespace Tests\Feature\Auth;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * List of logins and "log out everywhere else" (AD84).
 */
class SessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_devices_are_listed_and_can_be_logged_out(): void
    {
        config(['session.driver' => 'database']);
        $this->withoutMiddleware(AuthenticateSession::class);
        $person = Person::factory()->create(['locale' => 'en']);
        $user = User::query()->create(['name' => 'Kim', 'email' => 'kim@example.org', 'password' => 'x', 'person_id' => $person->id]);
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $row = fn (string $id, int $userId) => DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'payload' => '', 'last_activity' => now()->subHour()->timestamp]);
        $row('old-phone', $user->id);
        $row('somebody-else', $other->id);
        $token = $user->getRememberToken();

        $this->actingAs($user)->get(route('portal.profile'))->assertOk()->assertSee('Firefox, Linux')->assertSee('Log out everywhere else');
        $this->actingAs($user)->post(route('portal.profile.sessions'))->assertRedirect();

        $this->assertFalse(DB::table('sessions')->where('id', 'old-phone')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'somebody-else')->exists());
        $this->assertNotSame($token, $user->refresh()->getRememberToken());
        $this->assertDatabaseHas('audit_events', ['action' => 'login.others_ended']);
    }
}
