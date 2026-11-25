<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Support\Auth\Permissions;
use App\Support\Automations\Schedule;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Schedule of an event: dates, automations before and after it, runs so far (EC02).
 */
class EventSchedule extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament.pages.event-schedule';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(EventResource::canView($this->event()), 403);
    }

    public function getTitle(): string
    {
        return __('automations.schedule.title').': '.$this->event()->title;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['event' => $this->event(), 'items' => app(Schedule::class)->ofEvent($this->event()), 'links' => Permissions::current('automations')];
    }

    private function event(): Event
    {
        /** @var Event $event */
        $event = $this->getRecord();

        return $event;
    }
}
