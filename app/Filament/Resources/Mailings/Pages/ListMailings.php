<?php

namespace App\Filament\Resources\Mailings\Pages;

use App\Filament\Resources\Mailings\MailingResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMailings extends ListRecords
{
    use ListTools;

    protected static string $resource = MailingResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            CreateAction::make(),
        ];
    }
}
