<?php

namespace App\Console\Commands;

use App\Support\Install\Installer;
use App\Support\Install\Requirements;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'kasmin:install
        {--organisation= : Name of the organisation}
        {--locale=de : Default language (de, en)}
        {--defaults=de : Default content to install: de, en or none (not recommended)}
        {--admin-name= : Name of the first administrator}
        {--admin-email= : E-mail address of the first administrator}';

    protected $description = 'Install Kasmin into an empty database';

    public function handle(Installer $installer, Requirements $requirements): int
    {
        foreach ($requirements->check() as $check) {
            $this->line(($check['ok'] ? '<info>✓</info> ' : '<error>✗</error> ').$check['label']);
        }
        if (! $requirements->passes()) {
            $this->error('Requirements are not met.');

            return self::FAILURE;
        }
        if ($installer->isInstalled()) {
            $this->error('Kasmin is already installed. Use kasmin:upgrade for upgrades.');

            return self::FAILURE;
        }

        $organisation = (string) ($this->option('organisation') ?: $this->ask('Name of the organisation'));
        $adminName = (string) ($this->option('admin-name') ?: $this->ask('Name of the first administrator'));
        $adminEmail = (string) ($this->option('admin-email') ?: $this->ask('E-mail address of the first administrator'));
        $locale = (string) $this->option('locale');
        $defaults = (string) $this->option('defaults');

        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || $organisation === '' || $adminName === '') {
            $this->error('Organisation, administrator name and a valid e-mail address are required.');

            return self::FAILURE;
        }

        $installer->install($organisation, $locale, $defaults, $adminName, $adminEmail);
        $this->info('Kasmin '.config('kasmin.version').' is installed.');

        return self::SUCCESS;
    }
}
