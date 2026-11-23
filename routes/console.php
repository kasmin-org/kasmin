<?php

use App\Support\System\SystemStatus;
use Illuminate\Support\Facades\Schedule;

// Runs via cron ("php artisan schedule:run" every minute) or the web cron hook (GET /system/cron).
Schedule::command('kasmin:mail:send')->everyMinute()->withoutOverlapping();
Schedule::command('kasmin:mail:fetch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('kasmin:notifications:digest')->dailyAt((string) config('kasmin.digest_time', '07:00'));
Schedule::call(fn () => SystemStatus::heartbeat())->everyMinute()->name('kasmin-heartbeat');
Schedule::command('kasmin:system:check')->hourly();
Schedule::command('kasmin:invoices:remind')->dailyAt('09:00');
