<?php

namespace App\Support\Install;

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Installs Kasmin into an empty database. Used by the web installer and by `php artisan kasmin:install`.
 */
class Installer
{
    public const DEFAULTS = ['de', 'en', 'none'];

    public function __construct(private readonly SchemaGuard $guard, private readonly Defaults $defaults) {}

    public function isInstalled(): bool
    {
        return $this->guard->status(fresh: true) !== SchemaStatus::NotInstalled;
    }

    /**
     * @param  string  $defaults  'de', 'en' or 'none': which default content (forms, templates, automations …) to install
     */
    public function install(string $organisation, string $locale, string $defaults, string $adminName, string $adminEmail): User
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('Kasmin is already installed.');
        }
        if (! in_array($defaults, self::DEFAULTS, true) || ! in_array($locale, ['de', 'en'], true)) {
            throw new RuntimeException('Invalid language or defaults.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->guard->recordCodeVersion();

        foreach ([
            'organisation_name' => $organisation,
            'default_locale' => $locale,
            'installed_defaults' => $defaults,
            'installed_at' => now()->toIso8601String(),
        ] as $key => $value) {
            DB::table('system_meta')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->defaults->install($defaults);

        $email = Str::lower($adminEmail);
        [$first, $last] = array_pad(explode(' ', trim($adminName), 2), 2, '');
        $person = Person::query()->create(['first_name' => $first, 'last_name' => $last, 'locale' => $locale]);
        $person->contactPoints()->create(['type' => 'email', 'value' => $email, 'purposes' => ['login', 'information'], 'is_primary' => true]);
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', 'administrator')->value('id'), 'starts_on' => now()->toDateString()]);

        // Login works with one-time links; the random password is never shown or used.
        return User::query()->create([
            'name' => $adminName,
            'email' => $email,
            'password' => Str::password(64),
            'person_id' => $person->id,
        ]);
    }
}
