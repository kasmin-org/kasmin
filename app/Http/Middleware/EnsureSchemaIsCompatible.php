<?php

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops every web request while the database does not match the code (see SchemaGuard).
 */
class EnsureSchemaIsCompatible
{
    public function __construct(private readonly SchemaGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->guard->status();

        if ($status === SchemaStatus::Ok) {
            return $next($request);
        }

        if ($status === SchemaStatus::NotInstalled) {
            // Before installation there are no session/cache tables yet: use files for session, cache and
            // the rate limiter of the installer routes.
            config(['session.driver' => 'file', 'cache.default' => 'file', 'cache.limiter' => 'file']);
            app()->forgetInstance('cache');
            app()->forgetInstance('cache.store');
            app()->forgetInstance(RateLimiter::class);
            AppServiceProvider::defineRateLimiters();

            return $request->is('install') ? $next($request) : redirect('/install');
        }

        return response()->view('system.schema', [
            'status' => $status,
            'codeVersion' => $this->guard->codeVersion(),
            'databaseVersion' => $this->guard->databaseVersion(),
        ], 503);
    }
}
