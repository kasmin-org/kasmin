<?php

namespace App\Filament\Resources\MailTemplates\Pages;

use App\Filament\Resources\MailTemplates\MailTemplateResource;
use App\Models\MailTemplate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMailTemplate extends EditRecord
{
    protected function afterSave(): void
    {
        /** @var MailTemplate $template */
        $template = $this->getRecord();
        $template->newVersion();
    }

    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
