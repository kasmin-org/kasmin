<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Invoice;
use App\Support\Automations\Action;
use App\Support\Finance\Corrections;
use App\Support\Finance\Invoices;

/**
 * SD07, RF03: invoices that no longer fit (cancellation, siblings, prices) are corrected with credit note and new invoice.
 */
class InvoiceCorrections implements Action
{
    public function __construct(private readonly Corrections $corrections, private readonly Invoices $invoices) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        foreach ($this->events($event) as $e) {
            foreach ($this->corrections->proposals($e) as $p) {
                $items[] = [
                    'key' => $e->id.':'.$p['invoice']->id,
                    'label' => (string) __('automations.items.correction', ['number' => $p['invoice']->number, 'name' => $p['invoice']->payer_name]),
                    'details' => Invoice::money($p['old'], $p['invoice']->currency).' → '.Invoice::money($p['new'], $p['invoice']->currency),
                ];
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        [$eventId, $invoiceId] = array_map('intval', explode(':', $item['key']));
        $e = Event::query()->find($eventId);
        $invoice = Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'paid'])->find($invoiceId);
        if ($e === null || $invoice === null) {
            return 'skipped';
        }
        $new = $this->corrections->correct($e, $invoice, (string) __('finance.correction_reason'));
        $credit = Invoice::query()->where('corrects_id', $invoice->id)->latest('id')->first();
        if ((bool) $automation->setting('send', true)) {
            foreach (array_filter([$credit, $new]) as $document) {
                $this->invoices->send($document);
            }
        }

        return 'credit note '.($credit->number ?? '–').($new !== null ? ', invoice '.$new->number : '');
    }

    /** @return iterable<Event> */
    private function events(?Event $event): iterable
    {
        return $event !== null ? [$event] : Event::query()->whereNotIn('status', ['draft', 'archived'])->where('ends_at', '>=', now()->subMonths(6))->get();
    }
}
