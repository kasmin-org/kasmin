<?php

namespace App\Models;

/**
 * Event type (ET01): an event that is never shown or registered for, only the source of new events. Same table and settings.
 */
class EventTemplate extends Event
{
    protected $table = 'events';

    /** Roles, prices and instalments belong to the event row. */
    public function getForeignKey(): string
    {
        return 'event_id';
    }
}
