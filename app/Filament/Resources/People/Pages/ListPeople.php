<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPeople extends ListRecords
{
    use ListTools;

    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
