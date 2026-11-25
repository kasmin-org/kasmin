<?php

// Used by the CI upgrade job after kasmin:upgrade: the old data is there, nothing is left to migrate, and the standard texts,
// automations and event types of the new version are installed.

use App\Models\EventTemplate;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Automations\Engine;
use App\Support\Install\AutomationDefaults;
use App\Support\Install\MailTemplateDefaults;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$problems = [];
$check = function (string $what, bool $ok) use (&$problems): void {
    echo ($ok ? 'ok   ' : 'FAIL ').$what.PHP_EOL;
    if (! $ok) {
        $problems[] = $what;
    }
};
$files = array_map(fn (string $f) => basename($f, '.php'), glob(__DIR__.'/../database/migrations/*.php') ?: []);
$check('all migrations done', array_diff($files, DB::table('migrations')->pluck('migration')->all()) === []);
$check('people, registrations and invoices are still there', Person::query()->count() > 20 && Participation::query()->exists() && Invoice::query()->exists());
$check('event types', EventTemplate::query()->exists());
$missing = array_diff(app(MailTemplateDefaults::class)->keys(), DB::table('mail_templates')->pluck('key')->all());
$check('mail templates '.implode(', ', $missing), $missing === []);
$missing = array_diff(array_keys(AutomationDefaults::definitions()), DB::table('automations')->pluck('key')->all());
$check('automations '.implode(', ', $missing), $missing === []);
$check('automations have known actions', array_diff(DB::table('automations')->pluck('action')->all(), array_keys(Engine::ACTIONS)) === []);
exit($problems === [] ? 0 : 1);
