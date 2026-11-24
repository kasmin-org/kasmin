<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    use ListTools;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
