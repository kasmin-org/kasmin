<?php

namespace App\Support\System;

use Illuminate\Support\Facades\DB;

/**
 * Runs scheduled jobs once per day, hour or some minutes at the first scheduler run after their time. Laravel's dailyAt() needs a run
 * in exactly that minute, which a cron job every 5 minutes (or one that starts late) does not guarantee.
 */
class DueOnce
{
    public static function day(string $job, string $time): bool
    {
        return now()->format('H:i') >= $time && self::claim($job, now()->toDateString());
    }

    public static function hour(string $job): bool
    {
        return self::claim($job, now()->format('Y-m-d H'));
    }

    public static function minutes(string $job, int $minutes): bool
    {
        return self::claim($job, (string) intdiv(now()->getTimestamp(), $minutes * 60));
    }

    /** Marks the period as done; only one scheduler run gets true. */
    private static function claim(string $job, string $period): bool
    {
        $key = 'scheduled:'.$job;
        DB::table('system_meta')->insertOrIgnore(['key' => $key, 'value' => '', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('system_meta')->where('key', $key)->where('value', '!=', $period)->update(['value' => $period, 'updated_at' => now()]) === 1;
    }
}
