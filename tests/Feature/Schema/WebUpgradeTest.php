<?php

namespace Tests\Feature\Schema;

use App\Mail\LoginLinkMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\LoginLinks;
use App\Support\Install\Defaults;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Upgrade in the browser: the login stays open for administrators, who start the upgrade with a button.
 */
class WebUpgradeTest extends TestCase
{
    use RefreshDatabase;

    /** A migration that only changes data, so it can run again. */
    private const MIGRATION = '2026_12_11_000100_remove_approval_links_from_stored_mails';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        Storage::fake('local');
    }

    private function user(string $role): User
    {
        $person = Person::factory()->create(['locale' => 'en']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    private function newFilesInstalled(): void
    {
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $this->assertSame(SchemaStatus::UpgradeRequired, app(SchemaGuard::class)->status(fresh: true));
    }

    private function link(User $user, string $purpose = 'login', string $scope = 'full'): string
    {
        return '/login/link/'.app(LoginLinks::class)->issue($user, $purpose, $scope)['token'];
    }

    public function test_login_page_stays_with_a_notice_and_everything_else_waits(): void
    {
        $this->newFilesInstalled();
        $this->get('/login')->assertOk()->assertSee('only administrators can log in')->assertSee('name="email"', false);
        $this->get('/')->assertRedirect('/system/upgrade');
        $this->get('/system/upgrade')->assertRedirect('/login');
        $this->get('/portal')->assertRedirect('/system/upgrade');
        $this->get('/register')->assertRedirect('/system/upgrade');
        $this->getJson('/api/v1/public/events')->assertStatus(503);
        $this->post('/livewire/update', [], ['X-Livewire' => 'true', 'Accept' => 'application/json'])->assertStatus(503);
        $this->get('/up')->assertOk();
        $this->post('/system/upgrade')->assertRedirect('/login');
        $this->assertSame(SchemaStatus::UpgradeRequired, app(SchemaGuard::class)->status(fresh: true));
    }

    public function test_only_administrators_get_a_link_and_can_log_in(): void
    {
        $admin = $this->user('administrator');
        $board = $this->user('board');
        $this->newFilesInstalled();
        Mail::fake();
        $this->post('/login', ['email' => $board->email])->assertRedirect('/login/code');
        Mail::assertNothingSent();
        $this->post('/login', ['email' => $admin->email])->assertRedirect('/login/code');
        Mail::assertSent(LoginLinkMail::class, 1);

        $this->post($this->link($board))->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        // A link from an older mail is not enough.
        $this->post($this->link($admin, 'mail_link', 'limited'))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_administrator_logs_in_and_upgrades_with_a_button(): void
    {
        $admin = $this->user('administrator');
        $this->newFilesInstalled();
        $this->post($this->link($admin))->assertRedirect('/system/upgrade');
        $this->get('/system/upgrade')->assertOk()->assertSee('Upgrade now')->assertSee('1 step')->assertDontSee('php artisan');
        $this->get('/admin')->assertRedirect('/system/upgrade');
        $this->post('/system/upgrade')->assertOk()->assertSee('Everybody can log in again')->assertDontSee('Upgrade now');
        $this->assertSame(SchemaStatus::Ok, app(SchemaGuard::class)->status(fresh: true));
        $this->assertNotEmpty(Storage::disk('local')->allFiles(), 'a backup was made first');
        $this->get('/system/upgrade')->assertRedirect('/admin');
        $this->get('/login')->assertDontSee('only administrators can log in');
    }

    public function test_people_who_were_logged_in_before_cannot_upgrade(): void
    {
        $board = $this->user('board');
        $this->newFilesInstalled();
        $this->actingAs($board)->withSession(['login_scope' => 'full'])->post('/system/upgrade')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(SchemaStatus::UpgradeRequired, app(SchemaGuard::class)->status(fresh: true));
        $this->actingAs($this->user('administrator'))->withSession(['login_scope' => 'limited'])->post('/system/upgrade')->assertRedirect('/login');
        $this->assertSame(SchemaStatus::UpgradeRequired, app(SchemaGuard::class)->status(fresh: true));
    }

    public function test_a_newer_database_still_stops_everything(): void
    {
        DB::table('migrations')->insert(['migration' => '2999_01_01_000000_from_a_newer_version', 'batch' => 99]);
        app(SchemaGuard::class)->status(fresh: true);
        $this->get('/login')->assertStatus(503)->assertDontSee('php artisan');
        $this->get('/system/upgrade')->assertStatus(503);
        $this->actingAs($this->user('administrator'))->get('/admin')->assertStatus(503);
        $this->getJson('/api/v1/public/events')->assertStatus(503);
    }
}
