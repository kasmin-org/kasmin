<?php

namespace App\Filament\Resources\Consents\Pages;

use App\Filament\Resources\Consents\ConsentResource;
use App\Filament\Support\ListTools;
use Filament\Resources\Pages\ListRecords;

class ListConsents extends ListRecords
{
    use ListTools;

    protected static string $resource = ConsentResource::class;

    protected function getHeaderActions(): array
    {
        return $this->listTools();
    }
}
