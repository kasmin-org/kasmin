<?php

namespace App\Filament\Resources\Households\Pages;

use App\Filament\Resources\Households\HouseholdResource;
use App\Models\Household;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditHousehold extends EditRecord
{
    protected static string $resource = HouseholdResource::class;

    /** Legal guardians of the family are the guardians of its children (RG07); an ended guardianship stays ended. */
    protected function afterSave(): void
    {
        /** @var Household $household */
        $household = $this->getRecord();
        foreach ($household->children()->get() as $child) {
            foreach ($household->guardians()->pluck('people.id') as $guardian) {
                DB::table('guardianships')->insertOrIgnore(['guardian_id' => $guardian, 'child_id' => $child->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
