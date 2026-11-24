<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Invoice;
use App\Support\Automations\Action;
use App\Support\Finance\Reminders;

/**
 * DU02: payment reminders before and after the due date, as configured in the settings and per event.
 */
class PaymentReminders implements Action
{
    public function __construct(private readonly Reminders $reminders) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        return array_map(fn (array $r) => [
            'key' => $r['kind'].':'.$r['days'].':'.$r['invoice']->id,
            'label' => (string) __('automations.items.reminder', ['number' => $r['invoice']->number, 'name' => $r['invoice']->payer_name]),
            'details' => Invoice::money($r['invoice']->openCents(), $r['invoice']->currency).' · '.__('automations.items.reminder_'.$r['kind'], ['days' => abs($r['days'])]),
        ], $this->reminders->pending());
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        [$kind, $days, $id] = explode(':', $item['key']);
        $invoice = Invoice::query()->with('event', 'payer', 'household')->find((int) $id);

        return $invoice !== null && $this->reminders->send($invoice, $kind, (int) $days) ? 'sent' : 'skipped';
    }
}
