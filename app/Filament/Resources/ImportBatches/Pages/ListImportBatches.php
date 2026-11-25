<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Pages\ImportPeoplePage;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('new')->label(__('import.title'))->icon('heroicon-o-arrow-up-tray')->url(ImportPeoplePage::getUrl())->visible(fn () => ImportPeoplePage::canAccess())];
    }
}
