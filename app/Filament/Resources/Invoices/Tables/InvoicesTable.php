<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Support\Money;
use App\Models\Event;
use App\Models\Invoice;
use App\Support\Finance\Invoices;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('event.installments'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.finance.number'))->searchable()->sortable()->placeholder(__('finance.status.draft')),
                TextColumn::make('payer_name')->label(__('admin.finance.payer'))->searchable()->wrap(),
                TextColumn::make('event.title')->label(__('finance.event'))->toggleable()->wrap(),
                TextColumn::make('installment')->label(__('admin.finance.installment'))->toggleable()->badge()->color('gray')
                    ->state(fn (Invoice $record) => $record->installmentName()),
                TextColumn::make('total_cents')->label(__('finance.total'))->alignEnd()->sortable()
                    ->formatStateUsing(fn (Invoice $record, $state) => Invoice::money((int) $state, $record->currency)),
                TextColumn::make('open')->label(__('finance.open'))->alignEnd()
                    ->state(fn (Invoice $record) => in_array($record->status, ['issued', 'partially_paid'], true) ? Invoice::money($record->openCents(), $record->currency) : '–'),
                TextColumn::make('status')->label(__('admin.event.status'))->badge()
                    ->state(fn (Invoice $record) => $record->isOverdue() ? 'overdue' : $record->status)
                    ->formatStateUsing(fn (string $state) => __('finance.status.'.$state))->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('due_on')->label(__('finance.due_on'))->date()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.event.status'))->options(collect(Invoice::STATUSES)->mapWithKeys(fn ($s) => [$s => __('finance.status.'.$s)])->all()),
                Filter::make('overdue')->label(__('finance.status.overdue'))
                    ->query(fn (Builder $q) => $q->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_on', '<', now()->toDateString())),
                SelectFilter::make('event_id')->label(__('finance.event'))->options(fn () => Event::query()->orderByDesc('starts_at')->pluck('title', 'id')->all()),
                SelectFilter::make('type')->label(__('admin.finance.type'))->options(__('finance.type')),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                BulkAction::make('issue_send')->label(__('admin.finance.issue_and_send'))->icon('heroicon-o-paper-airplane')
                    ->visible(fn () => Money::canManage())->requiresConfirmation()
                    ->modalDescription(__('admin.finance.issue_and_send_hint'))
                    ->action(function (Collection $records) {
                        $service = app(Invoices::class);
                        $count = 0;
                        foreach ($records as $invoice) {
                            /** @var Invoice $invoice */
                            if ($invoice->status !== 'draft') {
                                continue;
                            }
                            $service->send($service->issue($invoice));
                            $count++;
                        }
                        Notification::make()->title(__('admin.finance.issued_count', ['count' => $count]))->success()->send();
                    }),
            ]);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'paid' => 'success', 'partially_paid', 'issued' => 'warning', 'overdue' => 'danger', default => 'gray',
        };
    }
}
