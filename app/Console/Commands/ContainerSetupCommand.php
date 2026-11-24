<?php

namespace App\Console\Commands;

use App\Support\Install\EnvConfig;
use App\Support\Install\Installer;
use App\Support\Schema\SchemaGuard;
use App\Support\Schema\SchemaStatus;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Runs at every start of the container: installs Kasmin into an empty database (if the administrator is given by
 * variables), upgrades an older database, and reports variables that differ from the database.
 */
class ContainerSetupCommand extends Command
{
    protected $signature = 'kasmin:container:setup {--public-url : only print the public URL from the database (or the variable before the set-up)}';

    protected $description = 'Set-up, upgrade and configuration check at the start of the container';

    public function handle(SchemaGuard $guard, Installer $installer, EnvConfig $env): int
    {
        $status = $guard->status(fresh: true);
        if ($this->option('public-url')) {
            $stored = in_array($status, [SchemaStatus::Ok, SchemaStatus::UpgradeRequired], true) ? Settings::get('public_url') : null;
            $this->line((string) ($stored ?: EnvConfig::value('KASMIN_PUBLIC_URL')));

            return self::SUCCESS;
        }
        if ($status === SchemaStatus::DatabaseNewer) {
            $this->error('The database belongs to a newer version of Kasmin. Use a newer image.');

            return self::FAILURE;
        }
        if ($status === SchemaStatus::NotInstalled) {
            foreach ($problems = EnvConfig::problems() as $problem) {
                $this->error('[kasmin] '.$problem);
            }
            if ($problems !== []) {
                return self::FAILURE;
            }
            $email = EnvConfig::value('KASMIN_ADMIN_EMAIL');
            $organisation = EnvConfig::value('KASMIN_ORGANISATION');
            if ($email === null || $organisation === null) {
                $this->line('[kasmin] Not installed yet: open /install in the browser, or set KASMIN_ORGANISATION and KASMIN_ADMIN_EMAIL.');

                return self::SUCCESS;
            }
            $locale = EnvConfig::value('KASMIN_LOCALE') ?? 'en';
            $installer->install($organisation, $locale, EnvConfig::value('KASMIN_DEFAULTS') ?? $locale, EnvConfig::value('KASMIN_ADMIN_NAME') ?? 'Administrator', $email);
            $this->line('[kasmin] Installed. The variables were written to the database; from now on only the database counts.');

            return self::SUCCESS;
        }
        if ($status === SchemaStatus::UpgradeRequired) {
            if (! filter_var(EnvConfig::value('KASMIN_AUTO_UPGRADE') ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
                $this->warn('[kasmin] The database needs an upgrade: run "php artisan kasmin:upgrade" (KASMIN_AUTO_UPGRADE is off).');

                return self::SUCCESS;
            }
            $this->line('[kasmin] Upgrading the database (with backup) …');
            if ($this->call('kasmin:upgrade') !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        foreach ($env->differences() as $difference) {
            $this->warn('[kasmin] WARNING: '.$difference.' The variable is ignored; change the value in the admin interface or correct the variable.');
        }

        return self::SUCCESS;
    }
}
