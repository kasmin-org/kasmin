<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Membership;
use App\Support\Automations\Action;
use App\Support\Finance\Invoices;
use App\Support\Finance\MembershipFees;

/**
 * PR04: invoices for the yearly membership fee per family.
 */
class MembershipInvoices implements Action
{
    public function __construct(private readonly MembershipFees $fees, private readonly Invoices $invoices) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $year = $this->year($automation);
        $items = [];
        foreach ($this->fees->pending($year) as $key => $group) {
            $items[] = ['key' => 'group:'.$key, 'label' => $group->map(fn (Membership $m) => $m->person?->fullName())->implode(', '), 'details' => (string) __('finance.membership_fee', ['year' => $year])];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $group = $this->fees->pending($this->year($automation))->get(substr($item['key'], 6));
        if ($group === null) {
            return 'skipped: already invoiced';
        }
        $invoice = $this->invoices->issue($this->fees->draft($this->year($automation), $group));
        if ((bool) $automation->setting('send', true)) {
            $this->invoices->send($invoice);
        }

        return 'invoice '.$invoice->number;
    }

    private function year(Automation $automation): int
    {
        return (int) ($automation->setting('year') ?: now()->format('Y'));
    }
}
