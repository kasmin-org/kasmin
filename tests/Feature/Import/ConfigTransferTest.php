<?php

namespace Tests\Feature\Import;

use App\Models\Automation;
use App\Models\MailTemplate;
use App\Support\Install\ConfigTransfer;
use App\Support\Install\Defaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_import_round_trip(): void
    {
        app(Defaults::class)->install('en');
        $data = app(ConfigTransfer::class)->export();
        $this->assertGreaterThan(5, count($data['mail_templates']));
        $this->assertGreaterThan(5, count($data['automations']));

        $data['mail_templates'][] = ['key' => 'shared_info', 'category' => 'newsletter', 'audience' => 'both', 'name' => ['en' => 'Shared'],
            'contents' => [['locale' => 'en', 'subject' => 'Hi {{ recipient.first_name }}', 'html' => '<p>{{ salutation }}</p>', 'text' => null]]];
        $i = (int) array_search('deposit_invoices', array_column($data['automations'], 'key'), true);
        $data['automations'][$i]['mode'] = 'automatic';
        $dry = app(ConfigTransfer::class)->import($data, false, true);
        $this->assertSame(['template:shared_info'], $dry['created']);
        $this->assertFalse(MailTemplate::query()->where('key', 'shared_info')->exists());

        app(ConfigTransfer::class)->import($data);
        $this->assertTrue(MailTemplate::query()->where('key', 'shared_info')->exists());
        $this->assertSame('confirm', Automation::query()->where('key', 'deposit_invoices')->value('mode'), 'existing entries untouched without update');

        app(ConfigTransfer::class)->import($data, true);
        $this->assertSame('automatic', Automation::query()->where('key', 'deposit_invoices')->value('mode'));
    }

    public function test_unsafe_templates_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ConfigTransfer::class)->import(['format' => ConfigTransfer::FORMAT, 'mail_templates' => [
            ['key' => 'evil', 'category' => 'newsletter', 'name' => ['en' => 'x'], 'contents' => [['locale' => 'en', 'subject' => 'x', 'html' => '{{ _self.env.registerUndefinedFilterCallback("exec") }}']]],
        ]]);
    }
}
