<?php

namespace App\Filament\Resources\SenderProfiles\Pages;

use App\Filament\Resources\SenderProfiles\SenderProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSenderProfiles extends ListRecords
{
    protected static string $resource = SenderProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
