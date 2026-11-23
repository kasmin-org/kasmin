<?php

namespace App\Providers;

use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Console\Events\CommandStarting;
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

    public function boot(): void
    {
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
