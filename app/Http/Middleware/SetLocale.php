<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the interface: the person's language, otherwise the chosen one, the browser's or the default of the
 * installation.
 */
class SetLocale
{
    public const LOCALES = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user instanceof User ? $user->person?->locale : ($request->hasSession() ? $request->session()->get('locale') : null);
        if (! in_array($locale, self::LOCALES, true)) {
            $default = Settings::get('default_locale') ?? config('app.locale');
            $locale = $request->getPreferredLanguage($default === 'de' ? ['de', 'en'] : ['en', 'de']);
        }
        app()->setLocale($locale === 'de' ? 'de' : 'en');

        return $next($request);
    }
}
