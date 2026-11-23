<?php

namespace App\Filament\Resources\EventSeries\Pages;

use App\Filament\Resources\EventSeries\EventSeriesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEventSeries extends EditRecord
{
    protected static string $resource = EventSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
