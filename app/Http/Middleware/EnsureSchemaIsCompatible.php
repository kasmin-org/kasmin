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
 * Stops every request (pages, admin interface, API, Livewire) while the database does not match the code (see SchemaGuard).
 */
class EnsureSchemaIsCompatible
{
    /** Hooks with their own token (upgrade, cron, second step of the update) and the health check. */
    public const ALWAYS = ['system/hooks/*', 'system/cron', 'system/update/finish', 'up'];

    /** Pages that work while an upgrade is pending. */
    public const DURING_UPGRADE = ['login', 'login/*', 'logout', 'language', 'two-factor', 'system/upgrade'];

    public function __construct(private readonly SchemaGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->guard->status();
        view()->share('upgradePending', $status === SchemaStatus::UpgradeRequired);

        if ($status === SchemaStatus::Ok || $request->is(...self::ALWAYS)) {
            return $next($request);
        }

        // The API never works on a database that does not match the code.
        if ($request->is('api/*', 'mcp')) {
            return response()->json(['message' => __('system.schema.title.'.$status->value)], 503, ['Retry-After' => '300']);
        }

        // Files of a new version are installed: the login stays open for administrators, who run the upgrade in the browser.
        if ($status === SchemaStatus::UpgradeRequired) {
            if ($request->is(...self::DURING_UPGRADE)) {
                return $next($request);
            }

            return $request->expectsJson() ? response()->json(['message' => __('system.schema.title.upgrade_required')], 503) : redirect('/system/upgrade');
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
