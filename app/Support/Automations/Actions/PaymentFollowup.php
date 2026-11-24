<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Automations\Action;
use App\Support\Events\Registrations;
use App\Support\Settings;

/**
 * DU03, A04: some days after the last reminder, the place of an unpaid invoice is released (proposal).
 */
class PaymentFollowup implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        $days = max([0, ...Settings::intList('invoice_overdue_days')]) + (int) $automation->setting('days', 14);
        $items = [];
        $invoices = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->whereNotNull('event_id')
            ->whereDate('due_on', '<=', now()->subDays($days)->toDateString())->whereHas('event', fn ($q) => $q->where('starts_at', '>', now()))->with('lines.participation.person', 'event')->get();
        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                if ($line->participation !== null && in_array($line->participation->status, ['registered', 'offered', 'requested'], true)) {
                    $items[] = ['key' => 'line:'.$line->id, 'label' => (string) __('automations.items.release', ['name' => $line->participation->person?->fullName(), 'event' => $invoice->event?->title]),
                        'details' => $invoice->number.' · '.Invoice::money($invoice->openCents(), $invoice->currency)];
                }
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $line = InvoiceLine::query()->with('invoice', 'participation.event')->find((int) substr($item['key'], 5));
        $participation = $line?->participation;
        if ($participation === null || ! in_array($line->invoice?->status, ['issued', 'partially_paid'], true) || ! in_array($participation->status, ['registered', 'offered', 'requested'], true)) {
            return 'skipped';
        }
        app(Registrations::class)->cancel($participation, (string) __('automations.items.release_reason'));

        return 'place released';
    }
}
