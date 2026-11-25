<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kasmin can only be shown in frames of other websites on the public pages meant for it, and only on the websites of the
 * settings (EE07, RG09). Addresses with one-time links are never passed on as referrer. Pages load scripts, styles,
 * images and fonts only from Kasmin itself and send forms only to it; they are not kept by browsers or proxies (Q05,
 * checked by the CI job "dast", see tests/zap).
 */
class SecurityHeaders
{
    /** Routes that may be embedded with ?embed=1. */
    public const EMBEDDABLE = ['public.form', 'public.form.store', 'public.event'];

    /** Features of the device that no page of Kasmin uses. */
    public const PERMISSIONS = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $origins = $request->boolean('embed') && $request->routeIs(...self::EMBEDDABLE) ? self::origins() : [];
        $response->headers->set('Content-Security-Policy', self::policy($request, $origins), true);
        if ($origins === []) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Pages hold personal data or a form token: nothing is stored unless the answer itself allows caching.
        if (array_filter(['public', 'max-age', 's-maxage', 'no-store'], fn (string $d) => $response->headers->hasCacheControlDirective($d)) === []) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    /**
     * The admin interface (Filament, Livewire, Alpine) needs inline scripts and styles and evaluates expressions written
     * in the HTML; the other pages use inline event handlers and styles. So the policy cannot forbid inline scripts, but
     * it keeps out everything from other servers, plugins, a changed base address and forms sent elsewhere.
     *
     * @param  list<string>  $origins
     */
    public static function policy(Request $request, array $origins = []): string
    {
        $dev = self::devServer();
        $script = ["'self'", "'unsafe-inline'", ...($request->is('admin', 'admin/*') ? ["'unsafe-eval'"] : []), ...$dev];

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', ["'self'", "'unsafe-inline'", ...$dev]),
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', ["'self'", ...$dev, ...array_map(fn (string $o) => (string) preg_replace('#^http#', 'ws', $o), $dev)]),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'frame-ancestors '.implode(' ', ["'self'", ...$origins]),
        ]);
    }

    /** @return list<string> the address of the Vite development server while "npm run dev" runs, never in a release */
    private static function devServer(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }
        $origin = rtrim(trim((string) @file_get_contents(Vite::hotFile())), '/');

        return preg_match('#^https?://[a-z0-9.\[\]:-]+$#i', $origin) === 1 ? [$origin] : [];
    }

    /** @return list<string> only plain https origins, so nothing else can get into the header */
    public static function origins(): array
    {
        $origins = preg_split('/[\s,]+/', (string) Settings::get('embed_origins')) ?: [];

        return array_values(array_filter($origins, fn (string $o) => preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $o) === 1));
    }
}
