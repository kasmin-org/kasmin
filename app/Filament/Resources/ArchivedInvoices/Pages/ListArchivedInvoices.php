<?php

namespace App\Filament\Resources\ArchivedInvoices\Pages;

use App\Filament\Resources\ArchivedInvoices\ArchivedInvoiceResource;
use App\Filament\Support\Money;
use App\Support\Audit\Audit;
use App\Support\Finance\InvoiceArchive;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListArchivedInvoices extends ListRecords
{
    protected static string $resource = ArchivedInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label(__('finance.archive.import'))->icon('heroicon-o-arrow-up-tray')->visible(fn () => Money::canManage())
                ->modalDescription(__('finance.archive.import_hint'))
                ->schema([
                    FileUpload::make('table')->label(__('finance.archive.table'))->disk('local')->directory('imports')->maxSize(20480)->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->storeFileNamesIn('table_name'),
                    FileUpload::make('zip')->label(__('finance.archive.zip'))->disk('local')->directory('imports')->maxSize(204800)
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->helperText(__('finance.archive.zip_hint')),
                ])
                ->action(function (array $data) {
                    $disk = Storage::disk('local');
                    $result = app(InvoiceArchive::class)->import($disk->path((string) $data['table']), (string) ($data['table_name'] ?? $data['table']), filled($data['zip'] ?? null) ? $disk->path((string) $data['zip']) : null);
                    $disk->delete(array_filter([(string) $data['table'], (string) ($data['zip'] ?? '')])); // the uploads are not kept
                    Audit::record('invoice_archive.imported', null, null, ['imported' => $result['imported'], 'updated' => $result['updated'], 'pdfs' => $result['pdfs']]);
                    $body = __('finance.archive.result', ['imported' => $result['imported'], 'updated' => $result['updated'], 'pdfs' => $result['pdfs']])
                        .($result['next_number'] !== null ? ' '.__('finance.archive.next', ['number' => $result['next_number']]) : '')
                        .($result['skipped'] !== [] ? ' '.implode(' ', array_slice($result['skipped'], 0, 8)) : '');
                    $note = Notification::make()->title(__('finance.archive.done'))->body($body)->persistent();
                    $result['skipped'] !== [] ? $note->warning()->send() : $note->success()->send();
                }),
        ];
    }
}
