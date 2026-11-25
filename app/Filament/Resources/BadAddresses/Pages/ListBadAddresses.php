<?php

namespace App\Filament\Resources\BadAddresses\Pages;

use App\Filament\Resources\BadAddresses\BadAddressResource;
use Filament\Resources\Pages\ListRecords;

class ListBadAddresses extends ListRecords
{
    protected static string $resource = BadAddressResource::class;
}
