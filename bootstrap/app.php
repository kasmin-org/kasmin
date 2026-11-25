<?php

use App\Http\Middleware\EnsureSchemaIsCompatible;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\System\BootCache;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

// A new release must not run with the package list cached by the previous one.
BootCache::refresh(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/hooks.php'));
            // Replaces the health page of the framework, which loads a script and fonts from other servers.
            Route::get('/up', function () {
                Event::dispatch(new DiagnosingHealth);

                return response('OK', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy: addresses whose X-Forwarded-* headers are trusted (comma-separated).
        $proxies = array_filter(array_map('trim', explode(',', (string) getenv('KASMIN_TRUSTED_PROXIES'))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: array_values($proxies));
        }
        // For every request, also the admin interface, the API and Livewire.
        $middleware->prepend(EnsureSchemaIsCompatible::class);
        $middleware->web(append: [SetLocale::class]);
        $middleware->append(SecurityHeaders::class);
        // Public forms work in frames of other websites, where browsers send no cookies. They change nothing before the
        // address is confirmed by mail, and they never use a login.
        // Unsubscribing uses signed links, and mail programs send no form token (one-click, RFC 8058).
        $middleware->validateCsrfTokens(except: ['f/*', 'mail/preferences/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
