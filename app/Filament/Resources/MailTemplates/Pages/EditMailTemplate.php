<?php

namespace App\Filament\Resources\MailTemplates\Pages;

use App\Filament\Resources\MailTemplates\MailTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMailTemplate extends EditRecord
{
    /** Every save is a new version with a snapshot for restoring (MT07). */
    protected function afterSave(): void
    {
        /** @var \App\Models\MailTemplate $template */
        $template = $this->getRecord();
        $template->increment('version');
        $template->versions()->create([
            'version' => $template->version,
            'snapshot' => ['name' => $template->name, 'contents' => $template->contents()->get(['locale', 'subject', 'html', 'text'])->toArray()],
            'changed_by_id' => auth()->id(),
        ]);
    }

    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
