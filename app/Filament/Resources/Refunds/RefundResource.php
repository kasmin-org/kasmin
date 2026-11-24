<?php

namespace App\Filament\Resources\Refunds;

use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Models\Refund;
use App\Support\Finance\Credits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Refund proposals (RF02): pay back by bank transfer and mark as done, or keep as credit.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('finance.refund');
    }

    public static function getPluralModelLabel(): string
    {
        return __('finance.refunds');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.finance');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Refund::query()->where('status', 'proposed')->count();

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
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label(__('finance.issued_on'))->date(),
            TextColumn::make('amount_cents')->label(__('finance.amount'))->alignEnd()->formatStateUsing(fn (Refund $record) => Invoice::money($record->amount_cents, $record->currency)),
            TextColumn::make('household.name')->label(__('admin.household.singular'))->placeholder('–'),
            TextColumn::make('payer_name')->label(__('finance.recipient'))->placeholder('–')->description(fn (Refund $record) => $record->iban),
            TextColumn::make('reason')->label(__('admin.finance.reason'))->wrap(),
            TextColumn::make('status')->label(__('admin.event.status'))->badge()->formatStateUsing(fn (string $state) => __('finance.refund_status.'.$state))
                ->color(fn (string $state) => match ($state) {
                    'proposed' => 'warning', 'done' => 'success', default => 'gray',
                }),
            TextColumn::make('done_on')->label(__('admin.finance.paid_on'))->date()->placeholder('–'),
        ])->filters([
            SelectFilter::make('status')->label(__('admin.event.status'))->options(__('finance.refund_status'))->default('proposed'),
        ])->recordActions([
            Action::make('done')->label(__('admin.finance.refund_done'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (Refund $record) => Money::canManage() && $record->status === 'proposed')
                ->schema([DatePicker::make('on')->label(__('admin.finance.paid_on'))->default(now())->maxDate(now())->required()])
                ->action(function (Refund $record, array $data) {
                    try {
                        app(Credits::class)->markRefunded($record, Carbon::parse((string) $data['on']));
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('keep')->label(__('admin.finance.keep_credit'))->icon('heroicon-o-archive-box')->color('gray')->requiresConfirmation()
                ->visible(fn (Refund $record) => Money::canManage() && $record->status === 'proposed')
                ->action(fn (Refund $record) => app(Credits::class)->decline($record)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRefunds::route('/')];
    }
}
