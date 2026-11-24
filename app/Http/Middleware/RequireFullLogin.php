<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login links in mails (invoice, newsletter …) are valid for weeks and can be forwarded, so they only give a limited
 * session (L03). The admin interface, health data, contacts and changes need a fresh login link or code (step-up).
 */
class RequireFullLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::limited($request)) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('step-up');
    }

    public static function limited(Request $request): bool
    {
        return $request->hasSession() && $request->session()->get('login_scope') === 'limited';
    }
}
