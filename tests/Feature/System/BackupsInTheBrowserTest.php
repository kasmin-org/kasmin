<?php

namespace Tests\Feature\System;

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Backup\BackupCrypt;
use App\Support\Backup\Backups;
use App\Support\Backup\RemoteBackup;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backups without a shell (AD83, N07): made, downloaded and opened in the browser; an encrypted copy leaves the server.
 */
class BackupsInTheBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(Defaults::class)->install('en');
        app()->setLocale('en');
        $this->withoutMiddleware(AuthenticateSession::class);
    }

    private function user(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_the_format_can_be_opened_again_and_a_wrong_passphrase_is_noticed(): void
    {
        $gzip = (string) gzencode('-- backup');
        $encrypted = BackupCrypt::encrypt($gzip, 'correct horse battery');
        $this->assertStringStartsWith('Salted__', $encrypted);
        $this->assertStringNotContainsString('backup', $encrypted);
        $this->assertSame($gzip, BackupCrypt::decrypt($encrypted, 'correct horse battery'));
        $this->assertNull(BackupCrypt::decrypt($encrypted, 'wrong'));
        $this->assertNull(BackupCrypt::decrypt('something else', 'correct horse battery'));
    }

    public function test_administrators_make_download_and_open_backups_in_the_browser(): void
    {
        $admin = $this->user('administrator');
        $session = ['two_factor_passed' => true];
        $this->actingAs($this->user('board'))->withSession($session)->post(route('admin.backups.create'))->assertForbidden();

        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.create'))->assertRedirect();
        $backup = app(Backups::class)->all()[0];
        $this->actingAs($admin)->withSession($session)->get('/admin/system')->assertOk()->assertSee($backup['name'])->assertSee('Back up now');
        $plain = $this->actingAs($admin)->withSession($session)->get(route('admin.backups.download', $backup['name']))->assertOk()->getContent();
        $this->assertStringStartsWith("\x1f\x8b", (string) $plain);
        $this->actingAs($admin)->withSession($session)->get(route('admin.backups.download', 'nothing.sql.gz'))->assertNotFound();

        RemoteBackup::storeSecret('backup_passphrase', 'correct horse battery');
        $encrypted = (string) $this->actingAs($admin)->withSession($session)->get(route('admin.backups.download', [$backup['name'], 'encrypted' => 1]))->assertOk()->getContent();
        $this->assertStringStartsWith('Salted__', $encrypted);
        $opened = $this->actingAs($admin)->withSession($session)->post(route('admin.backups.decrypt'), ['file' => UploadedFile::fake()->createWithContent($backup['name'].'.enc', $encrypted)])->assertOk();
        $this->assertSame($plain, $opened->getContent());
        $this->actingAs($admin)->withSession($session)->post(route('admin.backups.decrypt'), ['file' => UploadedFile::fake()->createWithContent('x.enc', $encrypted), 'passphrase' => 'wrong passphrase'])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['action' => 'backup.downloaded']);
    }

    public function test_an_encrypted_copy_goes_to_the_storage_and_failures_are_shown(): void
    {
        Settings::set('backup_remote_url', 'https://storage.example.org/dav/backups/');
        Settings::set('backup_remote_user', 'camp');
        RemoteBackup::storeSecret('backup_remote_password', 'app-password');
        $this->assertFalse(RemoteBackup::configured(), 'not without a passphrase');
        RemoteBackup::storeSecret('backup_passphrase', 'correct horse battery');
        $this->assertNotSame('correct horse battery', Settings::get('backup_passphrase'), 'secrets are stored encrypted');
        Settings::set('backup_keep', '2');

        $status = 201;
        Http::fake(['storage.example.org/*' => function () use (&$status) {
            return Http::response('', $status);
        }]);
        foreach (['2027-05-01', '2027-05-02', '2027-05-03'] as $day) {
            $this->travelTo($day.' 02:30');
            $this->assertSame(0, Artisan::call('kasmin:backup'));
        }
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_starts_with($r->url(), 'https://storage.example.org/dav/backups/kasmin-daily-') && str_ends_with($r->url(), '.sql.gz.enc')
            && str_starts_with($r->body(), 'Salted__') && $r->hasHeader('Authorization'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
        $state = RemoteBackup::state();
        $this->assertTrue($state['ok']);
        $this->assertCount(2, $state['files']);

        $status = 507;
        $this->travelTo('2027-05-04 02:30');
        $this->assertSame(0, Artisan::call('kasmin:backup'));
        $this->assertFalse(RemoteBackup::state()['ok']);
        $this->assertStringContainsString('507', (string) RemoteBackup::state()['error']);

        Settings::set('backup_remote_url', 'http://storage.example.org/dav');
        $this->assertFalse(app(RemoteBackup::class)->upload(app(Backups::class)->all()[0]['path']), 'never without https');
    }
}
