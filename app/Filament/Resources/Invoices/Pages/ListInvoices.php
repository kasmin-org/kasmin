<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\ListTools;
use App\Filament\Support\Money;
use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\Person;
use App\Support\Finance\Invoices;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListInvoices extends ListRecords
{
    use ListTools;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->listTools(),
            // A single invoice (AD24): a draft, checked and issued like all others.
            Action::make('single')->label(__('admin.finance.single_invoice'))->icon('heroicon-o-plus')->color('gray')->visible(fn () => Money::canManage())
                ->schema([
                    Select::make('payer_id')->label(__('finance.recipient'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Person::query()->whereNull('anonymised_at')->where(fn ($q) => $q->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Person $p) => [$p->id => $p->fullName()])->all())
                        ->getOptionLabelUsing(fn ($value) => Person::query()->whereKey((int) $value)->first()?->fullName()),
                    Repeater::make('lines')->label(__('finance.description'))->minItems(1)->columns(2)->schema([
                        TextInput::make('description')->label(__('finance.description'))->required()->maxLength(250),
                        TextInput::make('amount')->label(__('finance.amount'))->numeric()->minValue(0.01)->suffix('€')->required(),
                    ]),
                ])
                ->action(function (array $data) {
                    $lines = array_map(fn (array $l) => ['description' => (string) $l['description'], 'amount_cents' => Money::toCents($l['amount'])], array_values((array) $data['lines']));
                    $invoice = app(Invoices::class)->single(Person::query()->whereNull('anonymised_at')->findOrFail((int) $data['payer_id']), $lines);
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
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
