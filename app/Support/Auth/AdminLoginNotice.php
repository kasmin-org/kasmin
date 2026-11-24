<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Mail\Composer;
use App\Support\System\SystemNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every login of a person with an administrative role is reported to all administrators by mail (LG02). It replaces the
 * second factor and cannot be switched off.
 */
class AdminLoginNotice
{
    public function __construct(private readonly SystemNotifications $notifications, private readonly Composer $composer) {}

    public function send(User $user, Request $request, string $method): int
    {
        if (! $user->isAdministrative()) {
            return 0;
        }
        $sent = 0;
        foreach ($this->notifications->administrators() as $admin) {
            $locale = $admin->person->locale ?? app()->getLocale();
            $rows = [
                'who' => $user->name.' ('.$user->email.')',
                'when' => Carbon::now()->settings(['locale' => $locale])->isoFormat('LLLL').' ('.now()->getTimezone()->getName().')',
                'method' => __('auth_link.methods.'.$method, [], $locale),
                'ip' => (string) $request->ip(),
                'browser' => Str::limit((string) $request->userAgent(), 200),
            ];
            $html = '<p>'.e(__('auth_link.notice_intro', [], $locale)).'</p><ul>';
            foreach ($rows as $key => $value) {
                $html .= '<li>'.e(__('auth_link.notice_'.$key, [], $locale)).': <strong>'.e($value).'</strong></li>';
            }
            $html .= '</ul><p>'.e(__('auth_link.notice_hint', [], $locale)).'</p>';
            $this->composer->queueRaw($admin->email, $admin->name, __('auth_link.notice_subject', ['name' => $user->name], $locale), $html, $locale, 'notification', $admin->person_id);
            $sent++;
        }

        return $sent;
    }
}
