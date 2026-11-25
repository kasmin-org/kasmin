<?php

namespace App\Providers;

use App\Models\Participation;
use App\Models\Person;
use App\Models\User;
use App\Support\Api\Presenter;
use App\Support\Automations\Triggers;
use App\Support\Install\AfterMigrations;
use App\Support\RuntimeSettings;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use App\Support\Webhooks\Webhooks;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        foreach (['install' => 10, 'preferences' => 10, 'register' => 5, 'public-form' => 5, 'language' => 60, 'login-send' => 5, 'login-code' => 10, 'login-link' => 10, 'approvals' => 10, 'two-factor' => 10] as $name => $perMinute) {
            app(RateLimiter::class)->for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }
    }

    public function boot(): void
    {
        // Separate rate limits per purpose: anonymous "throttle:x,y" limits share one counter per IP across all
        // routes, so e.g. using login links or approvals would block requesting a login link.
        self::defineRateLimiters();
        RuntimeSettings::apply();

        // Webhook events of participations and people (AD81).
        Participation::created(fn (Participation $p) => app(Webhooks::class)->emit('participation.created', Presenter::participation($p->loadMissing('event', 'person'))));
        Participation::created(fn (Participation $p) => Triggers::status($p));
        Person::updated(fn (Person $p) => Triggers::personChanged($p));
        Participation::updated(function (Participation $p): void {
            if ($p->wasChanged('status')) {
                Triggers::status($p);
                app(Webhooks::class)->emit('participation.status_changed', Presenter::participation($p->loadMissing('event', 'person')) + ['previous_status' => $p->getOriginal('status')]);
            }
        });
        Person::created(fn (Person $p) => Triggers::personCreated($p));
        Person::created(fn (Person $p) => app(Webhooks::class)->emit('person.created', Presenter::person($p)));

        Event::listen(MigrationsEnded::class, fn (MigrationsEnded $event) => $event->method === 'up' ? AfterMigrations::run() : null);

        // English dates day first ("8 December 2026"), never the ambiguous American order.
        $dates = fn (string $locale) => Date::setLocale($locale === 'en' ? 'en_GB' : $locale);
        $dates(app()->getLocale());
        Event::listen(LocaleUpdated::class, fn (LocaleUpdated $event) => $dates($event->locale));

        // Admin interface: dates in the format of the language, labels as written (no title case).
        Resource::titleCaseModelLabel(false);
        $formats = fn (Table|Schema $c) => $c
            ->defaultDateDisplayFormat(fn (): string => app()->getLocale() === 'de' ? 'd.m.Y' : 'j M Y')
            ->defaultDateTimeDisplayFormat(fn (): string => app()->getLocale() === 'de' ? 'd.m.Y H:i' : 'j M Y H:i');
        Table::configureUsing($formats);
        Schema::configureUsing($formats);

        // OpenAPI documentation of the REST API (X02): public, contains no data.
        Gate::define('viewApiDocs', fn (?User $user = null) => true);
        Scramble::configure()->withDocumentTransformers(fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer')));

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
