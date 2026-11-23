<?php

namespace App\Filament\Resources\SenderProfiles\Pages;

use App\Filament\Resources\SenderProfiles\SenderProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSenderProfile extends EditRecord
{
    protected static string $resource = SenderProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
