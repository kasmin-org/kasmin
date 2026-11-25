<?php

namespace App\Filament\Resources\Automations\Pages;

use App\Filament\Resources\Automations\AutomationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * Own automation (AT01, X09): always switched off at first and with approval, so it can be checked with a dry run.
 */
class CreateAutomation extends CreateRecord
{
    protected static string $resource = AutomationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['key' => 'own_'.Str::lower(Str::random(10)), 'action' => 'custom', 'is_standard' => false, 'active' => false] + $data;
    }

    protected function getRedirectUrl(): string
    {
        return AutomationResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
