<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Invoices and credit notes (AD42): list with status filters, detail with lines and payments, actions.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('finance.invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('finance.invoices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function canViewAny(): bool
    {
        return Money::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (Invoice $record, int $cents) => Invoice::money($cents, $record->currency);

        return $schema->columns(3)->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('admin.finance.number'))->placeholder(__('finance.status.draft')),
                TextEntry::make('type')->label(__('admin.finance.type'))->formatStateUsing(fn (string $state) => __('finance.type.'.$state)),
                TextEntry::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.status.'.$state))->color(fn (string $state) => InvoicesTable::statusColor($state)),
                TextEntry::make('reference')->label(__('finance.reference'))->copyable()->placeholder('–'),
                TextEntry::make('payer_name')->label(__('admin.finance.payer')),
                TextEntry::make('event.title')->label(__('finance.event'))->placeholder('–'),
                TextEntry::make('issued_on')->label(__('finance.issued_on'))->date()->placeholder('–'),
                TextEntry::make('due_on')->label(__('finance.due_on'))->date()->placeholder('–'),
                TextEntry::make('total_cents')->label(__('finance.total'))->formatStateUsing(fn (Invoice $record, $state) => $money($record, (int) $state)),
                TextEntry::make('paid_cents')->label(__('finance.already_paid'))->formatStateUsing(fn (Invoice $record, $state) => $money($record, (int) $state)),
                TextEntry::make('open')->label(__('finance.open'))->state(fn (Invoice $record) => $money($record, $record->openCents())),
            ]),
            Section::make(__('admin.finance.lines'))->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('description')->hiddenLabel()->columnSpan(2),
                    TextEntry::make('amount_cents')->hiddenLabel()->alignEnd()
                        ->formatStateUsing(fn (InvoiceLine $record, $state) => Invoice::money((int) $state).($record->discount_cents > 0 ? ' ('.__('finance.discount').' '.Invoice::money($record->discount_cents).')' : '')),
                ]),
            ]),
            Section::make(__('admin.finance.payments'))->columnSpanFull()->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->columns(4)->placeholder('–')->schema([
                    TextEntry::make('paid_on')->hiddenLabel()->date(),
                    TextEntry::make('amount_cents')->hiddenLabel()->formatStateUsing(fn ($state) => Invoice::money((int) $state)),
                    TextEntry::make('match')->hiddenLabel()->badge()->formatStateUsing(fn (string $state) => __('finance.match.'.$state)),
                    TextEntry::make('reference')->hiddenLabel()->state(fn (Payment $record) => trim($record->payer_name.' '.$record->reference) ?: $record->note)->placeholder('–'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
