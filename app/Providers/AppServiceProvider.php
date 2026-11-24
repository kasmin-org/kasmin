<?php

namespace App\Providers;

use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /** Commands that would take the schema backwards; Kasmin is upgrade-only. */
    private const BACKWARD_COMMANDS = ['migrate:rollback', 'migrate:reset', 'migrate:refresh', 'migrate:fresh', 'db:wipe'];

    /** Commands that stay available while the database is newer than the code. */
    private const ALLOWED_WHEN_DATABASE_NEWER = [
        'list', 'help', 'about', 'env', 'kasmin:status', 'package:discover',
        'config:clear', 'cache:clear', 'optimize:clear', 'view:clear', 'route:clear', 'down', 'up',
    ];

    public function register(): void
    {
        $this->app->singleton(SchemaGuard::class);
    }

    /** Also called again when the limiter is re-created before installation (EnsureSchemaIsCompatible). */
    public static function defineRateLimiters(): void
    {
        app(RateLimiter::class)->for('api', fn (Request $request) => Limit::perMinute(120)->by('api|'.($request->bearerToken() !== null ? hash('sha256', (string) $request->bearerToken()) : $request->ip())));
        foreach (['install' => 10, 'preferences' => 10, 'register' => 5, 'login-send' => 5, 'login-code' => 10, 'login-link' => 10, 'approvals' => 10, 'two-factor' => 10] as $name => $perMinute) {
            app(RateLimiter::class)->for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }
    }

    public function boot(): void
    {
        // Separate rate limits per purpose: anonymous "throttle:x,y" limits share one counter per IP across all
        // routes, so e.g. using login links or approvals would block requesting a login link.
        self::defineRateLimiters();

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = (string) $event->command;

            if (in_array($command, self::BACKWARD_COMMANDS, true) && ! $this->app->environment('local', 'testing')) {
                throw new RuntimeException("'{$command}' is disabled: Kasmin migrations are upgrade-only.");
            }

            if ($command === '' || in_array($command, self::ALLOWED_WHEN_DATABASE_NEWER, true)) {
                return;
            }

            if ($this->app->make(SchemaGuard::class)->status() === SchemaStatus::DatabaseNewer) {
                throw new RuntimeException((string) __('system.schema.text.database_newer'));
            }
        });
    }
}
