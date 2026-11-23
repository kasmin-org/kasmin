<?php

use Illuminate\Support\Facades\Schedule;

// Runs via cron ("php artisan schedule:run" every minute) or the web cron hook (GET /system/cron).
Schedule::command('kasmin:mail:send')->everyMinute()->withoutOverlapping();
Schedule::command('kasmin:mail:fetch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('kasmin:notifications:digest')->dailyAt((string) config('kasmin.digest_time', '07:00'));
