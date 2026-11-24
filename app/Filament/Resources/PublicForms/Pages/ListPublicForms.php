<?php

namespace App\Filament\Resources\PublicForms\Pages;

use App\Filament\Resources\PublicForms\PublicFormResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ListPublicForms extends ManageRecords
{
    protected static string $resource = PublicFormResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
