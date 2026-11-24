<?php

use App\Support\System\DueOnce;
use App\Support\System\SystemStatus;
use Illuminate\Support\Facades\Schedule;

// Runs via cron ("php artisan schedule:run", every 5 minutes) or the web cron hook (GET /system/cron).
Schedule::command('kasmin:mail:send')->everyMinute()->withoutOverlapping();
Schedule::command('kasmin:mail:fetch')->everyMinute()->when(fn () => DueOnce::minutes('kasmin:mail:fetch', 5))->withoutOverlapping();
Schedule::command('kasmin:notifications:digest')->everyMinute()->when(fn () => DueOnce::day('kasmin:notifications:digest', (string) config('kasmin.digest_time', '07:00')));
Schedule::call(fn () => SystemStatus::heartbeat())->everyMinute()->name('kasmin-heartbeat');
Schedule::command('kasmin:system:check')->everyMinute()->when(fn () => DueOnce::hour('kasmin:system:check'));
Schedule::command('kasmin:invoices:remind')->everyMinute()->when(fn () => DueOnce::day('kasmin:invoices:remind', '09:00'));
Schedule::command('kasmin:automations:run')->everyMinute()->when(fn () => DueOnce::hour('kasmin:automations:run'))->withoutOverlapping();
Schedule::command('kasmin:webhooks:deliver')->everyMinute()->withoutOverlapping();
Schedule::command('kasmin:privacy:retention')->everyMinute()->when(fn () => DueOnce::day('kasmin:privacy:retention', '03:30'));
