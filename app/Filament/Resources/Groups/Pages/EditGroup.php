<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use App\Models\Group;
use App\Support\People\GroupMembers;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function afterSave(): void
    {
        /** @var Group $group */
        $group = $this->getRecord();
        app(GroupMembers::class)->update($group);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
