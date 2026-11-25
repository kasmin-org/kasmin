<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Audit\AuditRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The devices a person is logged in on, and "log out everywhere else" (AD84). Needs sessions in the database,
 * which is the default.
 */
class Sessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return list<array{current: bool, device: string, ip: string, last: Carbon}> */
    public static function of(User $user, Request $request): array
    {
        if (! self::available()) {
            return [];
        }
        $current = $request->session()->getId();

        return array_values(DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()->map(fn (object $row) => [
            'current' => $row->id === $current,
            'device' => (string) (AuditRetention::shortAgent($row->user_agent) ?? '–'),
            'ip' => (string) ($row->ip_address ?? '–'),
            'last' => Carbon::createFromTimestamp((int) $row->last_activity)->setTimezone(config('app.timezone')),
        ])->all());
    }

    /** Ends all other logins of the user, also remembered ones. Returns how many were ended. */
    public static function logoutOthers(User $user, Request $request): int
    {
        if (! self::available()) {
            return 0;
        }
        $ended = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();
        Audit::record('login.others_ended', $user, meta: ['sessions' => $ended]);

        return $ended;
    }
}
