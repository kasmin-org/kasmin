<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use App\Models\User;
use App\Support\People\Timeline;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Timeline of a person (AD21).
 */
class PersonTimeline extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PersonResource::class;

    protected string $view = 'filament.pages.person-timeline';

    public string $type = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(PersonResource::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        /** @var Person $person */
        $person = $this->getRecord();

        return __('people.timeline').': '.$person->fullName();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Person $person */
        $person = $this->getRecord();
        /** @var User $user */
        $user = auth()->user();

        return ['items' => app(Timeline::class)->for($person, $user, in_array($this->type, Timeline::TYPES, true) ? $this->type : null)];
    }
}
