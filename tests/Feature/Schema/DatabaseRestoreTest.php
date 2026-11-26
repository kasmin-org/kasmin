<?php

namespace Tests\Feature\Schema;

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Backup\BackupCrypt;
use App\Support\Backup\Backups;
use App\Support\Backup\DatabaseBackup;
use App\Support\Backup\DatabaseRestore;
use App\Support\Install\Defaults;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * A backup is brought back in the browser, without phpMyAdmin or a shell (no-shell rule, N07).
 */
class DatabaseRestoreTest extends TestCase
{
    private bool $touched = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! DatabaseRestore::available()) {
            $this->markTestSkipped('Restoring in the browser is for MySQL and MariaDB.');
        }
        // Restoring drops all tables, so there is no transaction around these tests: a fresh database before and after.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->touched = true;
        Storage::fake('local');
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->withoutMiddleware(AuthenticateSession::class);
    }

    protected function tearDown(): void
    {
        if ($this->touched) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $person = Person::factory()->create(['first_name' => 'Alex', 'last_name' => 'Admin']);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id')]);

        return User::query()->create(['name' => 'Admin', 'email' => 'admin@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_statements_with_line_breaks_quotes_and_semicolons_are_split_correctly(): void
    {
        $name = "Nüß'\"; DROP TABLE people; -- \\ \n zweite Zeile;\n😀";
        $person = Person::factory()->create(['first_name' => $name]);
        $file = app(DatabaseBackup::class)->create('test');
        $restore = app(DatabaseRestore::class);
        $info = $restore->inspect($file);
        $this->assertGreaterThan(50, $info['statements']);
        $inserts = array_filter(iterator_to_array($restore->statements($file), false), fn (string $s) => str_starts_with($s, 'INSERT INTO `people`'));
        $this->assertCount(Person::query()->count(), $inserts);

        Person::query()->whereKey($person->id)->update(['first_name' => 'changed']);
        Schema::create('left_over', fn ($table) => $table->id());
        $before = $restore->restore($file);
        $this->assertSame($name, Person::query()->findOrFail($person->id)->first_name);
        $this->assertFalse(Schema::hasTable('left_over'), 'tables the backup does not know are gone');
        $this->assertStringContainsString('before-restore', $before);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS c')->c);
    }

    public function test_files_that_are_no_complete_backup_change_nothing(): void
    {
        Person::factory()->create(['first_name' => 'Stays']);
        $restore = app(DatabaseRestore::class);
        $bad = (string) tempnam(sys_get_temp_dir(), 'bad');
        foreach (["-- something else\nDROP TABLE people;\n", "-- Kasmin database backup (mariadb), 2027-01-01T00:00:00+00:00\nDROP TABLE IF EXISTS `people`;\n", "-- Kasmin database backup (sqlite), 2027-01-01\n".DatabaseBackup::END."\n"] as $content) {
            file_put_contents($bad, gzencode($content));
            try {
                $restore->restore($bad);
                $this->fail('must be refused');
            } catch (RuntimeException) {
            }
            $this->assertTrue(Person::query()->where('first_name', 'Stays')->exists());
        }
        unlink($bad);
    }

    public function test_an_administrator_restores_in_the_browser_and_has_to_log_in_again(): void
    {
        $admin = $this->admin();
        $session = ['two_factor_passed' => true];
        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.create'))->assertRedirect();
        $name = app(Backups::class)->all()[0]['name'];
        Person::factory()->create(['first_name' => 'Later']);

        $this->actingAs($admin)->withSession($session)->get('/admin/system')->assertOk()->assertSee('Restore a backup')->assertSee('data-restore', false);
        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.restore'), ['name' => $name, 'confirm' => 'yes'])->assertSessionHasErrors('confirm');
        $this->assertTrue(Person::query()->where('first_name', 'Later')->exists());

        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.restore'), ['name' => $name, 'confirm' => 'restore'])->assertRedirect(route('login'));
        $this->assertFalse(Person::query()->where('first_name', 'Later')->exists());
        $this->assertGuest();
        $this->assertTrue(collect(app(Backups::class)->all())->contains(fn (array $b) => str_contains($b['name'], 'before-restore')), 'the state before is kept');
        $this->assertDatabaseHas('audit_events', ['action' => 'backup.restored']);
    }

    public function test_an_encrypted_upload_is_restored_and_other_roles_are_refused(): void
    {
        $admin = $this->admin();
        $marker = Person::factory()->create(['first_name' => 'Marker']);
        $file = app(DatabaseBackup::class)->create('test');
        $encrypted = BackupCrypt::encrypt((string) file_get_contents($file), 'correct horse battery');
        $marker->update(['first_name' => 'changed']);
        $session = ['two_factor_passed' => true];

        $board = Person::factory()->create();
        $board->roleAssignments()->create(['role_id' => Role::query()->where('key', 'board')->value('id')]);
        $other = User::query()->create(['name' => 'Board', 'email' => 'board@example.org', 'password' => 'x', 'person_id' => $board->id]);
        $upload = fn () => UploadedFile::fake()->createWithContent('backup.sql.gz.enc', $encrypted);
        $this->actingAs($other)->withSession($session)->post(route('admin.backups.restore'), ['file' => $upload(), 'passphrase' => 'correct horse battery', 'confirm' => 'restore'])->assertForbidden();

        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.restore'), ['file' => $upload(), 'passphrase' => 'wrong', 'confirm' => 'restore'])->assertRedirect();
        $this->assertSame('changed', $marker->refresh()->first_name);
        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.restore'), ['file' => $upload(), 'passphrase' => 'correct horse battery', 'confirm' => 'restore'])->assertRedirect(route('login'));
        $this->assertSame('Marker', Person::query()->findOrFail($marker->id)->first_name);
    }
}
