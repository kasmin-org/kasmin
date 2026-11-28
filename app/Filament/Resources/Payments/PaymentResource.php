<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\InArea;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Finance\Credits;
use App\Support\Finance\Payments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Payments and bank import (AD41, PY01–PY04): certain matches are booked automatically, suggestions are
 * confirmed with one click, the rest is assigned by hand.
 */
class PaymentResource extends Resource
{
    use InArea;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getModelLabel(): string
    {
        return __('admin.finance.payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.finance.payments');
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
                        'auto', 'manual' => 'success', 'suggested', 'credit' => 'warning', 'refunded' => 'gray', default => 'danger',
                    }),
                TextColumn::make('invoice.number')->label(__('finance.invoice'))->placeholder('–')
                    ->state(fn (Payment $record) => $record->invoice->number ?? ($record->suggestedInvoice ? '→ '.$record->suggestedInvoice->number : null))
                    ->url(fn (Payment $record) => ($i = $record->invoice ?? $record->suggestedInvoice) ? InvoiceResource::getUrl('view', ['record' => $i]) : null),
            ])
            ->filters([
                SelectFilter::make('match')->label(__('admin.finance.match'))->options(__('finance.match')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('confirm')->label(__('admin.finance.confirm_match'))->icon('heroicon-o-check')->color('success')
                        ->visible(fn (Payment $record) => Money::canManage() && $record->match === 'suggested' && $record->suggestedInvoice !== null)
                        ->action(function (Payment $record) {
                            try {
                                app(Payments::class)->assign($record, $record->suggestedInvoice ?? throw new \RuntimeException(__('finance.not_open')));
                            } catch (\RuntimeException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('assign')->label(__('admin.finance.assign'))->icon('heroicon-o-link')
                        ->visible(fn (Payment $record) => Money::canManage() && in_array($record->match, ['suggested', 'unmatched'], true))
                        ->schema([
                            Select::make('invoice_id')->label(__('finance.invoice'))->required()->searchable()->options(fn () => self::openInvoices()),
                        ])
                        ->action(function (Payment $record, array $data) {
                            try {
                                app(Payments::class)->assign($record, Invoice::query()->findOrFail((int) $data['invoice_id']));
                            } catch (\RuntimeException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('split')->label(__('admin.finance.split'))->icon('heroicon-o-scissors')
                        ->visible(fn (Payment $record) => Money::canManage() && $record->invoice_id === null && in_array($record->match, ['suggested', 'unmatched'], true))
                        ->modalDescription(__('admin.finance.split_hint'))
                        ->schema([
                            Repeater::make('parts')->label('')->columns(2)->minItems(1)->defaultItems(2)->schema([
                                Select::make('invoice_id')->label(__('finance.invoice'))->required()->searchable()->distinct()->options(fn () => self::openInvoices()),
                                TextInput::make('amount')->label(__('finance.amount'))->numeric()->minValue(0.01)->suffix('€')->required(),
                            ]),
                        ])
                        ->action(function (Payment $record, array $data) {
                            $amounts = [];
                            foreach ((array) $data['parts'] as $part) {
                                $amounts[(int) $part['invoice_id']] = Money::toCents($part['amount']);
                            }
                            try {
                                app(Payments::class)->split($record, $amounts);
                            } catch (\RuntimeException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('use_credit')->label(__('admin.finance.use_credit'))->icon('heroicon-o-arrow-right-circle')
                        ->visible(fn (Payment $record) => Money::canManage() && $record->match === 'credit')
                        ->schema([Select::make('invoice_id')->label(__('finance.invoice'))->required()->searchable()->options(fn () => self::openInvoices())])
                        ->action(function (Payment $record, array $data) {
                            try {
                                app(Credits::class)->useFor($record, Invoice::query()->findOrFail((int) $data['invoice_id']));
                            } catch (\RuntimeException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('refund')->label(__('admin.finance.propose_refund'))->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
                        ->visible(fn (Payment $record) => Money::canManage() && $record->invoice_id === null && in_array($record->match, ['credit', 'unmatched'], true))
                        ->schema([TextInput::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(250)])
                        ->action(function (Payment $record, array $data) {
                            $record->forceFill(['match' => 'credit'])->save();
                            app(Credits::class)->proposeRefund($record, (string) $data['reason']);
                        }),
                    DeleteAction::make()->label(__('admin.finance.ignore'))->visible(fn (Payment $record) => Money::canManage() && $record->invoice_id === null && $record->match === 'unmatched' && $record->parent_id === null),
                ]),
            ], RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkAction::make('confirm_all')->label(__('admin.finance.confirm_suggestions'))->icon('heroicon-o-check')->visible(fn () => Money::canManage())
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        foreach ($records as $payment) {
                            /** @var Payment $payment */
                            if ($payment->match === 'suggested' && $payment->suggestedInvoice !== null) {
                                try {
                                    app(Payments::class)->assign($payment, $payment->suggestedInvoice);
                                } catch (\RuntimeException $e) {
                                    Notification::make()->title($e->getMessage())->danger()->send();
                                }
                            }
                        }
                    }),
            ]);
    }

    /** @return array<int, string> */
    private static function openInvoices(): array
    {
        return Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->orderBy('number')->get()
            ->mapWithKeys(fn (Invoice $i) => [$i->id => $i->number.' – '.$i->payer_name.' – '.Invoice::money($i->openCents(), $i->currency)])->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
