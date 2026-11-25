<?php

namespace App\Filament\Resources\Households\Pages;

use App\Filament\Resources\Households\HouseholdResource;
use App\Models\Household;
use App\Support\People\Families;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHousehold extends EditRecord
{
    protected static string $resource = HouseholdResource::class;

    /** Legal guardians of the family are the guardians of its children (RG07); an ended guardianship stays ended. */
    protected function afterSave(): void
    {
        /** @var Household $household */
        $household = $this->getRecord();
        app(Families::class)->linkGuardians($household);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
