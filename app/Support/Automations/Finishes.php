<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\Event;

/** Actions that do something once after all items of a run (e.g. a summary for the team). */
interface Finishes
{
    public function finish(Automation $automation, ?Event $event): void;
}
