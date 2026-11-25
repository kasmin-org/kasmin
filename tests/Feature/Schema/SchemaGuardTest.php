<?php

namespace Tests\Feature\Schema;

use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): SchemaGuard
    {
        return $this->app->make(SchemaGuard::class);
    }

    public function test_status_is_ok_after_upgrade(): void
    {
        $this->artisan('kasmin:upgrade', ['--no-backup' => true])->assertSuccessful(); // @phpstan-ignore method.nonObject

        $this->assertSame(SchemaStatus::Ok, $this->guard()->status(fresh: true));
        $this->assertSame(config('kasmin.version'), $this->guard()->databaseVersion());
    }

    public function test_unknown_migration_in_database_means_database_is_newer(): void
    {
        DB::table('migrations')->insert(['migration' => '2999_01_01_000000_from_a_newer_version', 'batch' => 99]);

        $this->assertSame(SchemaStatus::DatabaseNewer, $this->guard()->status(fresh: true));
    }

    public function test_higher_recorded_version_means_database_is_newer(): void
    {
        config(['kasmin.version' => '0.1.0']);
        DB::table('system_meta')->updateOrInsert(['key' => SchemaGuard::VERSION_KEY], ['value' => '0.2.0']);

        $this->assertSame(SchemaStatus::DatabaseNewer, $this->guard()->status(fresh: true));
    }

    public function test_a_version_without_database_changes_needs_no_upgrade(): void
    {
        DB::table('system_meta')->updateOrInsert(['key' => SchemaGuard::VERSION_KEY], ['value' => '9.8.0']);
        config(['kasmin.version' => '9.9.0']);
        $this->assertSame(SchemaStatus::Ok, $this->guard()->status(fresh: true));
        $this->assertSame('9.9.0', $this->guard()->databaseVersion(), 'only the number is written down');
    }

    public function test_missing_migration_means_upgrade_required(): void
    {
        DB::table('migrations')->where('migration', 'like', '%create_system_meta_table')->delete();

        $this->assertSame(SchemaStatus::UpgradeRequired, $this->guard()->status(fresh: true));
    }

    public function test_web_requests_are_stopped_when_database_is_newer(): void
    {
        DB::table('migrations')->insert(['migration' => '2999_01_01_000000_from_a_newer_version', 'batch' => 99]);

        $this->get('/')->assertStatus(503)->assertSee('Kasmin');
    }

    public function test_upgrade_refuses_when_database_is_newer(): void
    {
        DB::table('migrations')->insert(['migration' => '2999_01_01_000000_from_a_newer_version', 'batch' => 99]);

        $this->artisan('kasmin:upgrade', ['--no-backup' => true])->assertFailed(); // @phpstan-ignore method.nonObject
    }

    public function test_web_requests_work_when_schema_matches(): void
    {
        $this->artisan('kasmin:upgrade', ['--no-backup' => true])->run(); // @phpstan-ignore method.nonObject

        $this->get('/')->assertOk();
    }
}
