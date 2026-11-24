<?php

namespace App\Filament\Resources\EventTemplates\Pages;

use App\Filament\Resources\EventTemplates\EventTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventTemplate extends CreateRecord
{
    protected static string $resource = EventTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['is_template' => true, 'status' => 'draft', 'public_page' => false] + $data;
    }
}
