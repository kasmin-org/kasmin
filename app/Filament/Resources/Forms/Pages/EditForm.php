<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Resources\Forms\FormResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditForm extends EditRecord
{
    /** Every change of the form definition creates a new version; existing answers keep their version (SF07). */
    protected function afterSave(): void
    {
        $this->getRecord()->increment('version');
    }

    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
