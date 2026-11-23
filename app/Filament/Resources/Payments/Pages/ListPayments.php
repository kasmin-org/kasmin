<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Money;
use App\Support\Finance\Payments;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Storage;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        $saved = json_decode((string) Settings::get('bank_import_mapping'), true);
        $saved = is_array($saved) ? $saved : ['date' => 1, 'amount' => 2, 'reference' => 3, 'name' => 4, 'iban' => 5, 'skip_rows' => 1, 'delimiter' => ';', 'date_format' => 'd.m.Y'];
        $column = fn (string $key, bool $required) => TextInput::make($key)->label(__('admin.finance.column_'.$key))->numeric()->minValue(1)->required($required)->default($saved[$key] ?? null);

        return [
            // Bank import (PY02, AD41): CSV or XLSX export of the bank account; the column mapping is saved for next time.
            Action::make('import')->label(__('admin.finance.bank_import'))->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Money::canManage())
                ->modalDescription(__('admin.finance.bank_import_hint'))
                ->schema([
                    FileUpload::make('file')->label(__('admin.finance.file'))->required()->disk('local')->directory('bank-imports')
                        ->preserveFilenames()->maxSize(10240)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                    Section::make(__('admin.finance.mapping'))->columns(3)->schema([
                        $column('date', true), $column('amount', true), $column('reference', true), $column('name', false), $column('iban', false),
                        TextInput::make('skip_rows')->label(__('admin.finance.skip_rows'))->numeric()->minValue(0)->default($saved['skip_rows'] ?? 1),
                        Select::make('delimiter')->label(__('admin.finance.delimiter'))->options([';' => ';', ',' => ',', "\t" => 'Tab'])->default($saved['delimiter'] ?? ';'),
                        Select::make('date_format')->label(__('admin.finance.date_format'))->options(['d.m.Y' => '31.12.2027', 'Y-m-d' => '2027-12-31', 'd/m/Y' => '31/12/2027', 'm/d/Y' => '12/31/2027'])->default($saved['date_format'] ?? 'd.m.Y'),
                    ]),
                ])
                ->action(function (array $data) {
                    $shown = ['skip_rows' => (int) $data['skip_rows'], 'delimiter' => (string) $data['delimiter'], 'date_format' => (string) $data['date_format']];
                    $mapping = $shown;
                    foreach (['date', 'amount', 'reference', 'name', 'iban'] as $key) {
                        if (filled($data[$key] ?? null)) {
                            $shown[$key] = (int) $data[$key];
                            $mapping[$key] = (int) $data[$key] - 1; // shown 1-based
                        }
                    }
                    Settings::set('bank_import_mapping', (string) json_encode($shown));
                    $path = (string) $data['file'];
                    try {
                        $result = app(Payments::class)->import(Storage::disk('local')->path($path), basename($path), $mapping);
                    } finally {
                        Storage::disk('local')->delete($path); // bank data is not kept as a file
                    }
                    Notification::make()->title(__('admin.finance.import_done', $result))->success()->persistent()->send();
                }),
        ];
    }
}
