<?php

namespace Tests\Feature\System;

use App\Models\MailAccount;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\Backup\DatabaseRestore;
use App\Support\Install\Defaults;
use App\Support\Install\Requirements;
use App\Support\Mail\MailDns;
use App\Support\System\Docs;
use App\Support\System\PhpSupport;
use App\Support\System\Releases;
use App\Support\System\SystemNotifications;
use App\Support\System\SystemStatus;
use App\Support\System\Updater;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(Defaults::class)->install('en');
        $this->artisan('kasmin:upgrade', ['--no-backup' => true])->run(); // @phpstan-ignore method.nonObject
        Http::fake([
            'endoflife.date/*' => Http::response([
                ['cycle' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'eol' => now()->addMonths(10)->toDateString()],
                ['cycle' => '9.9', 'eol' => now()->addYears(3)->toDateString()],
            ]),
            'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0', 'html_url' => 'https://github.com/kasmin-org/kasmin/releases/tag/v99.0.0', 'assets' => []]),
        ]);
        Cache::flush();
    }

    private function user(string $role, string $email): User
    {
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $email, 'password' => 'x', 'person_id' => $person->id]);
    }

    public function test_lowest_php_version_and_new_release_are_detected(): void
    {
        $this->assertTrue(app(PhpSupport::class)->isLowestSupported());
        $this->assertTrue(app(Releases::class)->updateAvailable());
    }

    public function test_system_notifications_go_only_to_administrators_and_are_mailed_once(): void
    {
        $admin = $this->user('administrator', 'admin@example.org');
        $this->user('board', 'board@example.org');

        app(SystemStatus::class)->notify(app(SystemNotifications::class));
        app(SystemStatus::class)->notify(app(SystemNotifications::class));

        $this->assertTrue(SystemNotification::query()->where('key', 'cron_missing')->exists());
        $this->assertTrue(SystemNotification::query()->where('key', 'like', 'php_lowest:%')->exists());
        $this->assertTrue(SystemNotification::query()->where('key', 'kasmin_update:99.0.0')->exists());
        $this->assertSame(3, OutgoingMail::query()->where('to_address', 'admin@example.org')->count());
        $this->assertSame(0, OutgoingMail::query()->where('to_address', 'board@example.org')->count());

        $open = app(SystemNotifications::class)->openFor($admin);
        $this->assertCount(3, $open);
        $first = $open->first();
        $this->assertNotNull($first);
        app(SystemNotifications::class)->dismiss($first, $admin);
        $this->assertCount(2, app(SystemNotifications::class)->openFor($admin));

        // Scheduler runs → cron notification resolved.
        SystemStatus::heartbeat();
        app(SystemStatus::class)->notify(app(SystemNotifications::class));
        $this->assertNotNull(SystemNotification::query()->where('key', 'cron_missing')->value('resolved_at'));
    }

    public function test_status_page_only_for_administrators(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $admin = $this->user('administrator', 'admin@example.org');
        $board = $this->user('board', 'board@example.org');

        $this->actingAs($board)->withSession(['two_factor_passed' => true])->get('/admin/system')->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get('/admin/system')
            ->assertOk()->assertSee('Cron job')->assertSee('Mail: DNS records')->assertSee('Cron job not configured');
    }

    public function test_dns_check_suggests_records_and_warns_about_weak_dmarc(): void
    {
        $records = [
            'example.org' => [['txt' => 'v=spf1 mx -all']],
            '_dmarc.example.org' => [['txt' => 'v=DMARC1; p=none']],
        ];
        $dns = new MailDns(fn (string $name) => $records[$name] ?? []);
        $account = MailAccount::query()->create(['name' => 'Direct', 'delivery' => 'direct', 'smtp_host' => '', 'dkim_selector' => 'kasmin1', 'dkim_domain' => 'example.org']);
        $record = $account->generateDkimKey();

        $report = $dns->check('example.org', $account);
        $checks = [];
        foreach ($report['checks'] as $c) {
            $checks[$c['record']] = $c;
        }
        $this->assertSame('ok', $checks['SPF']['status']);
        $this->assertSame('error', $checks['DKIM']['status']);
        $this->assertStringContainsString($record, (string) $checks['DKIM']['suggestion']);
        $this->assertSame('warning', $checks['DMARC']['status']);
        $this->assertStringContainsString('p=reject', (string) $checks['DMARC']['suggestion']);
        $this->assertTrue(MailDns::canSignDkim());
    }

    public function test_updater_is_disabled_in_docker_and_checks_requirements(): void
    {
        config(['kasmin.runtime' => 'docker']);
        $pre = app(Updater::class)->precheck();
        $this->assertFalse($pre['passes']);
        $this->assertFalse(app(Updater::class)->available());

        config(['kasmin.runtime' => null]);
        $this->assertTrue(collect(app(Updater::class)->precheck()['checks'])->contains(fn ($c) => str_starts_with($c['label'], 'PHP '.PHP_VERSION)));
        $this->assertFalse(app(Updater::class)->finish('wrong-token'));
    }

    public function test_requirements_name_every_item_and_the_database_version_and_memory_are_shown(): void
    {
        /** @var array<string, array{items: list<array{name: string, purpose: string}>, detail: string}> $checks */
        $checks = collect(app(SystemStatus::class)->checks())->keyBy('key')->all();
        $names = array_column($checks['requirements']['items'], 'name');
        foreach (['pdo_mysql', 'dom', 'zip', 'curl', 'proc_open()', 'storage/', 'bootstrap/cache/'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach ($checks['requirements']['items'] as $item) {
            $this->assertNotSame('', $item['purpose'], $item['name']);
            $this->assertStringNotContainsString('system.requirements', $item['purpose'], 'missing text for '.$item['name']);
        }
        $this->assertMatchesRegularExpression('/(MariaDB|MySQL|SQLite) \d+\.\d+/', $checks['database']['detail']);
        $this->assertArrayHasKey('memory', $checks);
        $this->assertGreaterThanOrEqual(128, Requirements::MEMORY_MB);

        $this->withoutMiddleware(AuthenticateSession::class);
        $this->actingAs($this->user('administrator', 'admin@example.org'))->withSession(['two_factor_passed' => true])->get('/admin/system')->assertOk()
            ->assertSee('data-requirements', false)->assertSee('pdo_mysql')->assertSee('exact amounts of money')
            ->assertSee(route('admin.phpinfo'), false);
        // Backups have their own page since B11.
        $backups = $this->withSession(['two_factor_passed' => true])->get('/admin/backups')->assertOk()->assertSee('data-backups', false);
        if (DatabaseRestore::available()) { // only on MariaDB/MySQL
            $backups->assertSee('<strong>restore</strong>', false);
        }
    }

    public function test_phpinfo_only_for_administrators_and_without_the_environment(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        $admin = $this->user('administrator', 'admin@example.org');
        $board = $this->user('board', 'board@example.org');
        $this->actingAs($board)->withSession(['two_factor_passed' => true])->get('/admin/system/phpinfo')->assertForbidden();
        putenv('KASMIN_TEST_SECRET=must-not-show');
        $_ENV['KASMIN_TEST_SECRET'] = $_SERVER['KASMIN_TEST_SECRET'] = 'must-not-show';
        $response = $this->actingAs($admin)->withSession(['two_factor_passed' => true])->get('/admin/system/phpinfo')->assertOk();
        $this->assertStringContainsString('memory_limit', (string) $response->getContent());
        $this->assertStringNotContainsString('must-not-show', (string) $response->getContent());
        $this->assertDatabaseHas('audit_events', ['action' => 'system.phpinfo_viewed']);
    }

    public function test_help_links_follow_the_language_of_the_interface(): void
    {
        $this->assertStringStartsWith('https://www.kasmin.org/docs/', Docs::url('admin/hosting'));
        app()->setLocale('de');
        $this->assertStringStartsWith('https://www.kasmin.org/de/docs/', Docs::url('admin/hosting'));
        $this->assertStringEndsWith('/admin/hosting/', Docs::url('admin/hosting'));
    }

    public function test_the_page_shown_while_files_are_replaced_needs_no_framework(): void
    {
        $stub = base_path('resources/maintenance/updating.php');
        $run = function (string $uri, string $accept = 'text/html') use ($stub): string {
            $copy = sys_get_temp_dir().'/kasmin-maintenance-'.bin2hex(random_bytes(4)).'.php';
            copy($stub, $copy);
            $code = '$_SERVER["REQUEST_URI"]='.var_export($uri, true).';$_SERVER["HTTP_ACCEPT"]='.var_export($accept, true).';$_SERVER["HTTP_ACCEPT_LANGUAGE"]="de-AT";require '.var_export($copy, true).';echo "PASSED-ON";';
            $out = (string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
            unlink($copy);

            return $out;
        };
        $this->assertStringContainsString('Kasmin wird gerade aktualisiert', $run('/admin/people'));
        $this->assertStringNotContainsString('PASSED-ON', $run('/admin/people'));
        $this->assertStringContainsString('"message"', $run('/api/v1/people', 'application/json'));
        $this->assertStringContainsString('PASSED-ON', $run('/system/update/finish'));
        $this->assertStringContainsString('PASSED-ON', $run('/system/hooks/demo-reset'));
    }
}
