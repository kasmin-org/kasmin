<?php

namespace App\Filament\Resources\OutgoingMails\Pages;

use App\Filament\Resources\OutgoingMails\OutgoingMailResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutgoingMails extends ListRecords
{
    use ListTools;

    protected static string $resource = OutgoingMailResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
