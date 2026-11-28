<?php

namespace App\Filament\Resources\CostCenters\Pages;

use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Models\Period;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCenters extends ManageRecords
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        // A new cost centre is active in all open periods.
        return [CreateAction::make()->after(fn ($record) => $record->periods()->syncWithoutDetaching(Period::query()->where('status', 'open')->pluck('id')->all()))];
    }
}
