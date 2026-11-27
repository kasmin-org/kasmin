<?php

namespace App\Filament\Resources\ArchivedInvoices;

use App\Filament\Resources\ArchivedInvoices\Pages\ListArchivedInvoices;
use App\Filament\Support\InArea;
use App\Filament\Support\Money;
use App\Models\ArchivedInvoice;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoices from the time before Kasmin (MI07): a read-only archive with the PDFs.
 */
class ArchivedInvoiceResource extends Resource
{
    use InArea;

    protected static ?string $model = ArchivedInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    public static function getModelLabel(): string
    {
        return __('finance.archive.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('finance.archive.title');
    }

    public static function canViewAny(): bool
    {
        return Money::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('issued_on', 'desc')->columns([
            TextColumn::make('number')->label(__('finance.number'))->searchable()->sortable(),
            TextColumn::make('issued_on')->label(__('finance.date'))->date()->sortable()->placeholder('–'),
            TextColumn::make('payer_name')->label(__('finance.recipient'))->searchable()->placeholder('–')->description(fn (ArchivedInvoice $record) => $record->note),
            TextColumn::make('total_cents')->label(__('finance.amount'))->alignEnd()->formatStateUsing(fn (ArchivedInvoice $record) => Invoice::money($record->total_cents, $record->currency)),
            TextColumn::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.archive.status.'.$state))
                ->color(fn (string $state) => match ($state) {
                    'paid' => 'success', 'open' => 'warning', default => 'gray',
                }),
            TextColumn::make('paid_on')->label(__('admin.finance.paid_on'))->date()->placeholder('–')->toggleable(),
        ])->filters([
            SelectFilter::make('status')->label(__('admin.event.status'))->options(__('finance.archive.status')),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->icon('heroicon-o-document')->visible(fn (ArchivedInvoice $record) => $record->pdf_path !== null)
                ->url(fn (ArchivedInvoice $record) => route('admin.invoice-archive.pdf', $record))->openUrlInNewTab(),
        ])->emptyStateDescription(__('finance.archive.empty'));
    }

    public static function getPages(): array
    {
        return ['index' => ListArchivedInvoices::route('/')];
    }
}
