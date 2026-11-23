<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Finance\Payments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Payments and bank import (AD41, PY01–PY04): certain matches are booked automatically, suggestions are
 * confirmed with one click, the rest is assigned by hand.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('admin.finance.payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.payments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Payment::query()->whereIn('match', ['suggested', 'unmatched'])->count();

        return $open > 0 ? (string) $open : null;
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
        return $table
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label(__('admin.finance.paid_on'))->date()->sortable(),
                TextColumn::make('amount_cents')->label(__('finance.amount'))->alignEnd()->sortable()->formatStateUsing(fn ($state) => Invoice::money((int) $state)),
                TextColumn::make('payer_name')->label(__('admin.finance.payer'))->searchable()->placeholder('–')->wrap(),
                TextColumn::make('reference')->label(__('finance.reference'))->searchable()->placeholder('–')->wrap()->limit(60),
                TextColumn::make('match')->label(__('admin.finance.match'))->badge()->formatStateUsing(fn (string $state) => __('finance.match.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'auto', 'manual' => 'success', 'suggested' => 'warning', default => 'danger',
                    }),
                TextColumn::make('invoice.number')->label(__('finance.invoice'))->placeholder('–')
                    ->state(fn (Payment $record) => $record->invoice->number ?? ($record->suggestedInvoice ? '→ '.$record->suggestedInvoice->number : null))
                    ->url(fn (Payment $record) => ($i = $record->invoice ?? $record->suggestedInvoice) ? InvoiceResource::getUrl('view', ['record' => $i]) : null),
            ])
            ->filters([
                SelectFilter::make('match')->label(__('admin.finance.match'))->options(__('finance.match')),
            ])
            ->recordActions([
                Action::make('confirm')->label(__('admin.finance.confirm_match'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Payment $record) => Money::canManage() && $record->match === 'suggested' && $record->suggestedInvoice !== null)
                    ->action(fn (Payment $record) => app(Payments::class)->assign($record, $record->suggestedInvoice ?? throw new \RuntimeException)),
                Action::make('assign')->label(__('admin.finance.assign'))->icon('heroicon-o-link')
                    ->visible(fn (Payment $record) => Money::canManage() && in_array($record->match, ['suggested', 'unmatched'], true))
                    ->schema([
                        Select::make('invoice_id')->label(__('finance.invoice'))->required()->searchable()
                            ->options(fn () => Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->orderBy('number')->get()
                                ->mapWithKeys(fn (Invoice $i) => [$i->id => $i->number.' – '.$i->payer_name.' – '.Invoice::money($i->openCents(), $i->currency)])->all()),
                    ])
                    ->action(fn (Payment $record, array $data) => app(Payments::class)->assign($record, Invoice::query()->findOrFail((int) $data['invoice_id']))),
                DeleteAction::make()->label(__('admin.finance.ignore'))->visible(fn (Payment $record) => Money::canManage() && $record->invoice_id === null),
            ])
            ->toolbarActions([
                BulkAction::make('confirm_all')->label(__('admin.finance.confirm_suggestions'))->icon('heroicon-o-check')->visible(fn () => Money::canManage())
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        foreach ($records as $payment) {
                            /** @var Payment $payment */
                            if ($payment->match === 'suggested' && $payment->suggestedInvoice !== null) {
                                app(Payments::class)->assign($payment, $payment->suggestedInvoice);
                            }
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
