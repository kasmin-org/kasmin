<?php

namespace Tests\Feature\Schema;

use App\Support\Backup\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_contains_tables_and_rows(): void
    {
        $file = $this->app->make(DatabaseBackup::class)->create('test');

        $this->assertFileExists($file);
        $sql = (string) gzdecode((string) file_get_contents($file));
        $this->assertStringContainsString('system_meta', $sql);
        $this->assertStringContainsString('INSERT INTO `migrations`', $sql);
        @unlink($file);
    }
}
