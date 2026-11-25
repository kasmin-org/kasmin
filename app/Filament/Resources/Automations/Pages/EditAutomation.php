<?php

namespace App\Filament\Resources\Automations\Pages;

use App\Filament\Resources\Automations\AutomationResource;
use App\Models\Automation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutomation extends EditRecord
{
    protected static string $resource = AutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AutomationResource::dryRunAction(),
            AutomationResource::runNowAction(),
            DeleteAction::make()->visible(fn (Automation $record) => AutomationResource::canDelete($record)),
        ];
    }
}
