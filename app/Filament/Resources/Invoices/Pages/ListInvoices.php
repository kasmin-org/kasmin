<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Money;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Support\Finance\Invoices;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Invoice run (AD40, IN01): drafts per family for one instalment; review, then "issue and send".
            Action::make('run')->label(__('admin.finance.invoice_run'))->icon('heroicon-o-document-duplicate')
                ->visible(fn () => Money::canManage())
                ->modalDescription(__('admin.finance.invoice_run_hint'))
                ->schema([
                    Select::make('event_id')->label(__('finance.event'))->required()->live()
                        ->options(fn () => Event::query()->whereHas('installments')->orderByDesc('starts_at')->pluck('title', 'id')->all()),
                    Select::make('installment_id')->label(__('admin.finance.installment'))->required()
                        ->options(fn (Get $get) => EventInstallment::query()->where('event_id', (int) $get('event_id'))->orderBy('sort')->get()
                            ->mapWithKeys(fn (EventInstallment $i) => [$i->id => $i->labelText()])->all()),
                ])
                ->action(function (array $data) {
                    $event = Event::query()->with('roles', 'installments')->findOrFail((int) $data['event_id']);
                    $installment = EventInstallment::query()->where('event_id', $event->id)->findOrFail((int) $data['installment_id']);
                    $count = app(Invoices::class)->draftRun($event, $installment);
                    Notification::make()->title(__('admin.finance.run_done', ['count' => $count]))->success()->send();
                }),
        ];
    }
}
