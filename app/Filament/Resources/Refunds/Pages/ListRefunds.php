<?php

namespace App\Filament\Resources\Refunds\Pages;

use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\ListTools;
use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    use ListTools;

    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        return $this->listTools();
    }
}
