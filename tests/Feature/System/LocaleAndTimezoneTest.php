<?php

namespace Tests\Feature\System;

use App\Models\Person;
use App\Models\User;
use App\Support\Install\Defaults;
use App\Support\RuntimeSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleAndTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function user(string $locale): User
    {
        app(Defaults::class)->install('en');
        $person = Person::factory()->create(['locale' => $locale]);

        return User::query()->create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_logged_in_people_see_their_language(): void
    {
        $this->actingAs($this->user('de'))->get('/portal')->assertOk()->assertSee('lang="de"', false);
    }

    public function test_guests_get_the_browser_language_and_can_switch(): void
    {
        $this->user('en');
        Settings::set('default_locale', 'en');
        $this->get('/login', ['Accept-Language' => 'de-AT,de;q=0.9'])->assertSee('<html lang="de"', false);
        $this->get('/login', ['Accept-Language' => 'fr'])->assertSee('<html lang="en"', false);
        Settings::set('default_locale', 'de');
        $this->get('/login', ['Accept-Language' => 'fr'])->assertSee('<html lang="de"', false);
        $this->from('/login')->post('/language', ['locale' => 'en'])->assertRedirect('/login');
        $this->get('/login', ['Accept-Language' => 'de'])->assertSee('<html lang="en"', false);
        $this->post('/language', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_switching_changes_the_language_of_the_person(): void
    {
        $user = $this->user('de');
        $this->actingAs($user)->post('/language', ['locale' => 'en']);
        $this->assertSame('en', $user->person?->fresh()?->locale);
    }

    public function test_time_zone_of_the_settings_is_applied(): void
    {
        Settings::set('timezone', 'Europe/Vienna');
        RuntimeSettings::apply();
        $this->assertSame('Europe/Vienna', now()->getTimezone()->getName());
        Settings::set('timezone', 'Nowhere/Invalid');
        RuntimeSettings::apply();
        $this->assertSame('Europe/Vienna', date_default_timezone_get());
    }
}
