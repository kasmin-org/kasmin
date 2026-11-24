<?php

namespace App\Filament\Resources\EventTemplates\Pages;

use App\Filament\Resources\EventTemplates\EventTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEventTemplate extends EditRecord
{
    protected static string $resource = EventTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
