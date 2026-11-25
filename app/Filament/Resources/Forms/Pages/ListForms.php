<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Resources\Forms\FormResource;
use App\Support\Auth\Permissions;
use App\Support\Forms\FormDefinitions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListForms extends ListRecords
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label(__('admin.form.import'))->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn () => Permissions::current('forms', 'edit'))
                ->modalDescription(__('admin.form.import_hint'))
                ->schema([FileUpload::make('file')->label(__('admin.finance.file'))->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(1024)->storeFiles(false)->required()])
                ->action(function (array $data) {
                    $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $definition = $file instanceof TemporaryUploadedFile ? json_decode((string) $file->get(), true) : null;
                    try {
                        $form = app(FormDefinitions::class)->import(is_array($definition) ? $definition : []);
                    } catch (ValidationException $e) {
                        Notification::make()->title(__('admin.form.import_failed'))->body(implode(' ', array_slice($e->validator->errors()->all(), 0, 3)))->danger()->persistent()->send();

                        return;
                    }
                    Notification::make()->title(__('admin.form.imported', ['form' => $form->label()]))->success()->send();
                }),
            CreateAction::make(),
        ];
    }
}
