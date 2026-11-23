<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\Event;

/**
 * A built-in action of an automation. plan() must not change anything (dry run, AT07); execute() carries out one
 * planned item and returns a short result text for the run log.
 */
interface Action
{
    /** @return list<array{key: string, label: string, details?: string|null}> */
    public function plan(Automation $automation, ?Event $event): array;

    /** @param  array{key: string, label: string, details?: string|null}  $item */
    public function execute(Automation $automation, array $item, ?Event $event): string;
}
