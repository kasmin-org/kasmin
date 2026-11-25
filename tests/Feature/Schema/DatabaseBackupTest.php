<?php

namespace Tests\Feature\Schema;

use App\Models\Person;
use App\Support\Backup\DatabaseBackup;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_is_complete_and_can_be_restored_in_any_table_order(): void
    {
        app(Defaults::class)->install('en');
        $name = "Nüß'\"; DROP TABLE people; -- \\ \n zweite Zeile 😀";
        $person = Person::factory()->create(['first_name' => $name]);
        $file = $this->app->make(DatabaseBackup::class)->create('test');
        try {
            $sql = (string) gzdecode((string) file_get_contents($file));
            $this->assertSame(0600, fileperms($file) & 0777);
        } finally {
            @unlink($file);
        }
        $this->assertStringContainsString('INSERT INTO `migrations`', $sql);
        $this->assertStringEndsWith(DatabaseBackup::END."\n", $sql);
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=0', substr($sql, 0, 300));
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false) as $table) {
            $this->assertSame(DB::table($table)->count(), preg_match_all('/^INSERT INTO `'.preg_quote($table, '/').'` /m', $sql), $table);
        }
        $this->assertStringContainsString((string) DB::connection()->getPdo()->quote($person->first_name), $sql);
    }

    public function test_an_interrupted_backup_leaves_no_file_that_looks_complete(): void
    {
        $backup = new class extends DatabaseBackup
        {
            protected function createStatement(string $driver, string $table): string
            {
                throw new \RuntimeException('stop');
            }
        };
        $directory = storage_path('app/private/'.config('kasmin.backup_path'));
        $before = glob($directory.'/kasmin-broken-*') ?: [];
        try {
            $backup->create('broken');
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('stop', $e->getMessage());
        }
        $this->assertSame($before, glob($directory.'/kasmin-broken-*') ?: []);
    }
}
