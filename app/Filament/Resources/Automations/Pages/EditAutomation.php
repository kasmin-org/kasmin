<?php

namespace App\Filament\Resources\Automations\Pages;

use App\Filament\Resources\Automations\AutomationResource;
use Filament\Resources\Pages\EditRecord;

class EditAutomation extends EditRecord
{
    protected static string $resource = AutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [AutomationResource::dryRunAction(), AutomationResource::runNowAction()];
    }
}
