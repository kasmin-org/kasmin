<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Money;
use App\Models\Invoice;
use App\Support\Finance\Invoices;
use App\Support\Finance\Payments;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    private function invoice(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return $invoice;
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => in_array($this->invoice()->status, ['issued', 'partially_paid'], true);

        return [
            Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(fn () => route('admin.invoices.pdf', $this->invoice()))->openUrlInNewTab(),
            Action::make('issue')->label(__('admin.finance.issue_and_send'))->icon('heroicon-o-paper-airplane')
                ->visible(fn () => Money::canManage() && $this->invoice()->status === 'draft')->requiresConfirmation()
                ->action(function () {
                    $service = app(Invoices::class);
                    $service->send($service->issue($this->invoice()));
                    Notification::make()->title(__('admin.finance.issued_count', ['count' => 1]))->success()->send();
                    $this->refreshFormData(['number', 'status', 'reference', 'issued_on', 'due_on']);
                }),
            Action::make('resend')->label(__('admin.finance.resend'))->icon('heroicon-o-envelope')->color('gray')
                ->visible(fn () => Money::canManage() && $this->invoice()->status !== 'draft')->requiresConfirmation()
                ->action(fn () => app(Invoices::class)->send($this->invoice()) ? Notification::make()->title(__('admin.finance.queued'))->success()->send() : null),
            Action::make('payment')->label(__('admin.finance.record_payment'))->icon('heroicon-o-banknotes')
                ->visible(fn () => Money::canManage() && $open())
                ->schema([
                    TextInput::make('amount')->label(__('finance.amount'))->numeric()->required()->suffix('€')
                        ->default(fn () => number_format($this->invoice()->openCents() / 100, 2, '.', '')),
                    DatePicker::make('paid_on')->label(__('admin.finance.paid_on'))->default(now())->required(),
                    Select::make('method')->label(__('admin.finance.method'))->options(__('admin.finance.methods'))->default('bank')->required(),
                    TextInput::make('note')->label(__('admin.finance.note'))->maxLength(250),
                ])
                ->action(function (array $data) {
                    try {
                        app(Payments::class)->record($this->invoice(), Money::toCents($data['amount']), Carbon::parse((string) $data['paid_on']), (string) $data['method'], $data['note'] ?? null);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshFormData(['status', 'paid_cents']);
                }),
            Action::make('extend')->label(__('admin.finance.extend'))->icon('heroicon-o-calendar')->color('gray')
                ->visible(fn () => Money::canManage() && $open())
                ->schema([DatePicker::make('due_on')->label(__('finance.due_on'))->required()->default(fn () => $this->invoice()->due_on)])
                ->action(function (array $data) {
                    $this->invoice()->forceFill(['due_on' => $data['due_on']])->save();
                    $this->refreshFormData(['due_on']);
                }),
            Action::make('cancel')->label(__('admin.finance.cancel'))->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => Money::canManage() && $this->invoice()->type === 'invoice' && in_array($this->invoice()->status, ['issued', 'partially_paid', 'paid'], true))
                ->requiresConfirmation()->modalDescription(__('admin.finance.cancel_hint'))
                ->schema([TextInput::make('reason')->label(__('admin.finance.reason'))->required()->maxLength(120)])
                ->action(function (array $data) {
                    $credit = app(Invoices::class)->cancel($this->invoice(), (string) $data['reason']);
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $credit]));
                }),
            Action::make('delete')->label(__('admin.finance.delete_draft'))->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => Money::canManage() && $this->invoice()->status === 'draft')->requiresConfirmation()
                ->action(function () {
                    $this->invoice()->delete();
                    $this->redirect(InvoiceResource::getUrl('index'));
                }),
        ];
    }
}
