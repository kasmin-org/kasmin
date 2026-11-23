<?php

namespace App\Filament\Resources\Mailings\Pages;

use App\Filament\Resources\Mailings\MailingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMailing extends CreateRecord
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by_id' => auth()->id()];
    }

    protected static string $resource = MailingResource::class;
}
