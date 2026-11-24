<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kasmin can only be shown in frames of other websites on the public pages meant for it, and only on the websites of the
 * settings (EE07, RG09). Addresses with one-time links are never passed on as referrer.
 */
class SecurityHeaders
{
    /** Routes that may be embedded with ?embed=1. */
    public const EMBEDDABLE = ['public.form', 'public.form.store', 'public.event'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $origins = $request->boolean('embed') && $request->routeIs(...self::EMBEDDABLE) ? self::origins() : [];
        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', ["'self'", ...$origins]), true);
        if ($origins === []) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    /** @return list<string> only plain https origins, so nothing else can get into the header */
    public static function origins(): array
    {
        $origins = preg_split('/[\s,]+/', (string) Settings::get('embed_origins')) ?: [];

        return array_values(array_filter($origins, fn (string $o) => preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $o) === 1));
    }
}
