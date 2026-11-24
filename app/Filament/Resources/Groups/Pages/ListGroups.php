<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    use ListTools;

    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
