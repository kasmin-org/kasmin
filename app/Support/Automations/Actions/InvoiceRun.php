<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Support\Automations\Action;
use App\Support\Automations\Families;
use App\Support\Automations\InstallmentPosition;
use App\Support\Finance\Invoices;

/**
 * A02/A07: invoices for one instalment per family, issued and sent with the PDF. The setting "installment" is the
 * position of the instalment in the payment plan of the event (pos:1, pos:2 … pos:last, see InstallmentPosition).
 */
class InvoiceRun implements Action
{
    public function __construct(private readonly Invoices $invoices) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $installment = InstallmentPosition::resolve($event, $automation->setting('installment'));
        if ($event === null || $installment === null) {
            return [];
        }
        $items = [];
        foreach ($this->invoices->pendingGroups($event, $installment) as $key => $group) {
            $items[] = ['key' => 'group:'.$key, 'label' => Families::label(Families::group($group)->first() ?? ['household' => null, 'children' => [], 'recipients' => []]), 'details' => $installment->labelText()];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $installment = InstallmentPosition::resolve($event, $automation->setting('installment'));
        if ($event === null || $installment === null) {
            return 'skipped: no instalment';
        }
        $group = $this->invoices->pendingGroups($event, $installment)->get(substr($item['key'], 6));
        if ($group === null) {
            return 'skipped: already invoiced';
        }
        $invoice = $this->invoices->issue($this->invoices->draftFor($event, $installment, $group, Invoices::household($group)));
        if ((bool) $automation->setting('send', true)) {
            $this->invoices->send($invoice);
        }

        return 'invoice '.$invoice->number;
    }
}
