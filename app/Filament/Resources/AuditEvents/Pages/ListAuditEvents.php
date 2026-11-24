<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Support\ListTools;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    use ListTools;

    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return $this->listTools();
    }
}
