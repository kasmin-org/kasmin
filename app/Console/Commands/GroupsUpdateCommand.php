<?php

namespace App\Console\Commands;

use App\Support\People\GroupMembers;
use Illuminate\Console\Command;

class GroupsUpdateCommand extends Command
{
    protected $signature = 'kasmin:groups:update';

    protected $description = 'Recalculates the members of dynamic and event groups (runs hourly from the scheduler)';

    public function handle(GroupMembers $members): int
    {
        $this->info('Changes: '.$members->updateAll());

        return self::SUCCESS;
    }
}
