<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoData;
use App\Support\Install\Defaults;
use Illuminate\Console\Command;

class DemoCommand extends Command
{
    protected $signature = 'kasmin:demo {--locale=de : de or en} {--families=30} {--staff=15} {--seed=2026} {--email-domain=example.org : Domain of the fictitious e-mail addresses} {--force : Also outside local/testing/demo environments}';

    protected $description = 'Fill the database with random, fictitious demo data (never real data)';

    public function handle(DemoData $demo, Defaults $defaults): int
    {
        if (! $this->option('force') && ! app()->environment('local', 'testing', 'demo')) {
            $this->error('Demo data is only generated in local, testing or demo environments (use --force to override).');

            return self::FAILURE;
        }
        $defaults->install((string) $this->option('locale') === 'en' ? 'en' : 'de');
        $result = $demo->generate((string) $this->option('locale'), (int) $this->option('families'), (int) $this->option('staff'), (int) $this->option('seed'), (string) $this->option('email-domain'));
        $this->info("Created {$result['households']} families and {$result['people']} people.");

        return self::SUCCESS;
    }
}
