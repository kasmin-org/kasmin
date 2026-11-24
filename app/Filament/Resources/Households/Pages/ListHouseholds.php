<?php

namespace App\Filament\Resources\Households\Pages;

use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHouseholds extends ListRecords
{
    use ListTools;

    protected static string $resource = HouseholdResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
