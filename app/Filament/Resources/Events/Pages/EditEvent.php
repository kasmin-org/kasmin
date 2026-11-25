<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('schedule')->label(__('automations.schedule.title'))->icon('heroicon-o-calendar-days')->color('gray')->url(fn () => EventResource::getUrl('schedule', ['record' => $this->getRecord()])),
            Action::make('groups')->label(__('events.divisions.title'))->icon('heroicon-o-user-group')->color('gray')->url(fn () => EventResource::getUrl('groups', ['record' => $this->getRecord()])),
            DeleteAction::make(),
        ];
    }
}
