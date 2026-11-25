<?php

namespace App\Filament\Resources\MailTemplates\Pages;

use App\Filament\Resources\MailTemplates\MailTemplateResource;
use App\Models\MailTemplate;
use App\Models\MailTemplateVersion;
use App\Support\Mail\PreviewFrame;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

class EditMailTemplate extends EditRecord
{
    protected function beforeSave(): void
    {
        /** @var MailTemplate $template */
        $template = $this->getRecord();
        $template->keepFirstVersion();
    }

    protected function afterSave(): void
    {
        /** @var MailTemplate $template */
        $template = $this->getRecord();
        $template->newVersion();
    }

    private function template(): MailTemplate
    {
        /** @var MailTemplate $template */
        $template = $this->getRecord();

        return $template;
    }

    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(__('admin.mail.preview'))->icon('heroicon-o-eye')->color('gray')->url(fn () => MailTemplateResource::getUrl('preview', ['record' => $this->getRecord()])),
            Action::make('versions')->label(__('admin.mail.versions'))->icon('heroicon-o-clock')->color('gray')
                ->visible(fn () => $this->template()->versions()->exists())
                ->modalSubmitActionLabel(__('admin.mail.restore'))
                ->schema([
                    Select::make('version')->label(__('admin.form.version'))->required()->live()
                        ->options(fn () => $this->template()->versions()->with('changedBy')->get()->mapWithKeys(fn (MailTemplateVersion $v) => [$v->id => $v->version.' · '.$v->created_at->isoFormat('LLL').($v->changedBy !== null ? ' · '.$v->changedBy->name : '')])->all()),
                    Placeholder::make('contents')->label(__('admin.mail.contents'))->content(function (Get $get): HtmlString {
                        $version = $this->template()->versions()->whereKey($get('version'))->first();
                        $frames = '';
                        foreach ((array) ($version->snapshot['contents'] ?? []) as $content) {
                            $frames .= '<p style="font-weight:600">'.e(strtoupper((string) $content['locale']).': '.$content['subject']).'</p>'.PreviewFrame::make((string) $content['html'], (string) $content['subject'], 220);
                        }

                        return new HtmlString($frames);
                    })->visible(fn (Get $get) => filled($get('version'))),
                ])
                ->action(function (array $data) {
                    $version = $this->template()->versions()->whereKey($data['version'])->firstOrFail();
                    $this->template()->restoreVersion($version);
                    Notification::make()->title(__('admin.mail.restored', ['version' => $version->version]))->success()->send();
                    $this->redirect(MailTemplateResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            DeleteAction::make(),
        ];
    }
}
