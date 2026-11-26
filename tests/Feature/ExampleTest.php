<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Tests\TestCase;

/**
 * The start page. For a long time it still showed the text of the very first version ("login follows in the next
 * versions") and no way to log in – nobody had opened the bare address, every check started from a login link.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_start_page_leads_to_the_login_and_shows_public_events(): void
    {
        Settings::set('organisation_name', 'Example Camp');
        Event::query()->create(['title' => 'Summer camp', 'starts_at' => now()->addMonths(3), 'ends_at' => now()->addMonths(3)->addWeek(), 'status' => 'open', 'public_page' => true]);
        Event::query()->create(['title' => 'Internal meeting', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay(), 'status' => 'open']);
        Event::query()->create(['title' => 'Last year', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addWeek(), 'status' => 'finished', 'public_page' => true]);

        $this->get('/')->assertOk()
            ->assertSee('Example Camp')->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('register').'"', false)
            ->assertSee('Summer camp')->assertDontSee('Internal meeting')->assertDontSee('Last year')
            ->assertDontSee('next versions')->assertDontSee('Version');
    }

    public function test_who_is_logged_in_goes_on_to_the_portal(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $user = User::query()->create(['name' => 'Kim', 'email' => 'kim@example.org', 'password' => 'x', 'person_id' => Person::factory()->create()->id]);
        $this->actingAs($user)->get('/')->assertRedirect(route('portal'));
    }
}
