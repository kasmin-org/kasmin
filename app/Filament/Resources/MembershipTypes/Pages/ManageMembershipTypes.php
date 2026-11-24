<?php

namespace App\Filament\Resources\MembershipTypes\Pages;

use App\Filament\Resources\MembershipTypes\MembershipTypeResource;
use App\Filament\Support\ListTools;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMembershipTypes extends ManageRecords
{
    use ListTools;

    protected static string $resource = MembershipTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(), CreateAction::make()];
    }
}
