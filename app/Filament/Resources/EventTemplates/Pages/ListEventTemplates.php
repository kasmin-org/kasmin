<?php

namespace App\Filament\Resources\EventTemplates\Pages;

use App\Filament\Resources\EventTemplates\EventTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEventTemplates extends ListRecords
{
    protected static string $resource = EventTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
