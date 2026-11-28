<?php

namespace App\Filament\Resources\Automations\Pages;

use App\Filament\Resources\Automations\AutomationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Own automation (AT01, X09): always switched off at first and with approval, so it can be checked with a dry run.
 */
class CreateAutomation extends CreateRecord
{
    protected static string $resource = AutomationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['action' => 'custom', 'is_standard' => false, 'active' => false] + $data; // the internal key is made from the name
    }

    protected function getRedirectUrl(): string
    {
        return AutomationResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
