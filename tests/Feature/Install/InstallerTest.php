<?php

namespace Tests\Feature\Install;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Schema::dropAllTables();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        // Other tests use RefreshDatabase; make it migrate again.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_empty_database_redirects_to_installer(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('Kasmin installieren');
        $this->get('/install?lang=en')->assertOk()->assertSee('No defaults (not recommended)');
    }

    public function test_web_installer_installs_kasmin(): void
    {
        // Like a real installation: no cache table yet, database cache store configured.
        config(['cache.default' => 'database']);
        $this->post('/install', [
            'organisation' => 'Example Camp Association',
            'locale' => 'en',
            'defaults' => 'en',
            'admin_name' => 'Alex Example',
            'admin_email' => 'Admin@Example.org',
        ])->assertOk()->assertSee('Kasmin is installed');

        $this->assertSame('admin@example.org', User::query()->value('email'));
        $this->assertSame('en', DB::table('system_meta')->where('key', 'installed_defaults')->value('value'));
        $this->get('/')->assertOk();
        $this->get('/install')->assertRedirect('/');
    }

    public function test_web_installer_validates_input(): void
    {
        $this->post('/install', ['organisation' => '', 'locale' => 'xx', 'defaults' => 'all', 'admin_email' => 'no'])
            ->assertSessionHasErrors(['organisation', 'locale', 'defaults', 'admin_name', 'admin_email']);
    }

    public function test_cli_installer_installs_kasmin(): void
    {
        $this->artisan('kasmin:install', [
            '--organisation' => 'Example Camp Association',
            '--locale' => 'de',
            '--defaults' => 'none',
            '--admin-name' => 'Alex Example',
            '--admin-email' => 'admin@example.org',
        ])->assertSuccessful(); // @phpstan-ignore method.nonObject

        $this->assertSame(1, User::query()->count());
        $this->artisan('kasmin:install', ['--organisation' => 'x', '--admin-name' => 'x', '--admin-email' => 'a@b.c'])->assertFailed(); // @phpstan-ignore method.nonObject
    }
}
