<?php

namespace Tests\Feature\Container;

use App\Models\MailAccount;
use App\Models\SenderProfile;
use App\Support\Install\EnvConfig;
use App\Support\RuntimeSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnvConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $set = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['kasmin.runtime' => 'docker']);
        DB::table('system_meta')->delete(); // empty installation
    }

    protected function tearDown(): void
    {
        foreach ($this->set as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    private function env(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $this->set[] = $name;
    }

    private function variables(): void
    {
        $this->env('KASMIN_PUBLIC_URL', 'https://camp.example.org/');
        $this->env('KASMIN_ORGANISATION', 'Example Camp');
        $this->env('KASMIN_ORGANISATION_IBAN', 'at61 1904 3002 3457 3201');
        $this->env('KASMIN_INVOICE_DUE_DAYS', '21');
        $this->env('KASMIN_MAIL_HOST', 'mail.example.org');
        $this->env('KASMIN_MAIL_USERNAME', 'camp');
        $this->env('KASMIN_MAIL_PASSWORD', 'first-secret');
        $this->env('KASMIN_MAIL_FROM_ADDRESS', 'office@example.org');
    }

    /** @return array<int, string> */
    private function differences(): array
    {
        return app(EnvConfig::class)->differences();
    }

    private function store(): void
    {
        Settings::set('organisation_name', 'Example Camp');
        app(EnvConfig::class)->store('Admin@Example.org');
        Settings::flush();
    }

    public function test_variables_are_stored_once_and_differences_are_only_reported(): void
    {
        $this->variables();
        $this->store();
        $this->assertSame('https://camp.example.org', Settings::get('public_url'));
        $this->assertSame('AT611904300234573201', Settings::get('organisation_iban'));
        $this->assertSame('21', Settings::get('invoice_due_days'));
        $account = MailAccount::query()->firstOrFail();
        $this->assertSame(['mail.example.org', 465, 'ssl', 'first-secret', true], [$account->smtp_host, (int) $account->smtp_port, $account->smtp_encryption, $account->smtp_password, $account->is_default]);
        $this->assertSame('Example Camp', SenderProfile::query()->firstOrFail()->from_name);
        $this->assertCount(0, $this->differences());

        // A new container with changed variables and the existing database.
        $this->env('KASMIN_INVOICE_DUE_DAYS', '30');
        $this->env('KASMIN_MAIL_PASSWORD', 'second-secret');
        $changed = $this->differences();
        $this->assertCount(2, $changed);
        $this->assertStringContainsString('KASMIN_INVOICE_DUE_DAYS is "30", but the database contains "21"', $changed[0] ?? '');
        $this->assertStringContainsString('KASMIN_MAIL_PASSWORD differs', $changed[1] ?? '');
        $this->assertStringNotContainsString('secret', implode(' ', $changed), 'secrets never appear in the log');
        $this->assertSame('21', Settings::get('invoice_due_days'));
        $this->assertSame('first-secret', MailAccount::query()->firstOrFail()->smtp_password);

        // Changed in the admin interface: the old variable is reported.
        Settings::set('invoice_due_days', '30');
        $this->assertCount(1, $this->differences());
    }

    public function test_values_from_files_and_wrong_values(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($file, "from-file\n");
        $this->env('KASMIN_MAIL_PASSWORD_FILE', $file);
        $this->assertSame('from-file', EnvConfig::value('KASMIN_MAIL_PASSWORD'));
        unlink($file);
        $this->env('KASMIN_PUBLIC_URL', 'camp.example.org');
        $this->env('KASMIN_MAIL_ENCRYPTION', 'none');
        $this->env('KASMIN_DIGEST_TIME', '25:00');
        $problems = implode("\n", EnvConfig::problems());
        foreach (['KASMIN_PUBLIC_URL', 'KASMIN_MAIL_ENCRYPTION', 'KASMIN_DIGEST_TIME', 'KASMIN_MAIL_PASSWORD_FILE'] as $name) {
            $this->assertStringContainsString($name, $problems);
        }
    }

    public function test_database_settings_win_at_runtime_and_login_mails_use_the_mail_account(): void
    {
        $this->variables();
        $this->store();
        Settings::set('digest_time', '06:30');
        RuntimeSettings::apply();
        $this->assertSame('06:30', config('kasmin.digest_time'));
        $this->assertSame('https://camp.example.org', config('app.url'));
        $this->assertSame('mail.example.org', config('mail.mailers.smtp.host'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertSame('office@example.org', config('mail.from.address'));
    }
}
