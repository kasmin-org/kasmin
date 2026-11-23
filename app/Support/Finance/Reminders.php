<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Support\Mail\Composer;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Payment reminders (RM01–RM04): a friendly reminder some days before the due date (default 3) and reminders
 * after the due date (default after 7 and 21 days). Each reminder is sent once per invoice; paid invoices are skipped.
 */
class Reminders
{
    /** @return int number of reminders queued */
    public function run(): int
    {
        $sent = 0;
        $invoices = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->whereNotNull('due_on')->with('event', 'payer', 'household')->get();
        foreach ($invoices as $invoice) {
            $kind = $this->due($invoice);
            if ($kind === null) {
                continue;
            }
            [$type, $days] = $kind;
            $inserted = DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => $type, 'days' => $days, 'sent_at' => now()]);
            if ($inserted === 0 || $invoice->payer === null) {
                continue;
            }
            $mail = app(Composer::class)->queue($type === 'before_due' ? 'payment_reminder' : 'payment_overdue', $invoice->payer, [
                'household' => $invoice->household, 'event' => $invoice->event, 'invoice' => $invoice,
            ], attachments: [['invoice_id' => $invoice->id, 'paid' => false]]);
            if ($mail !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return array{0: string, 1: int}|null the reminder that is due today (kind, days) */
    public function due(Invoice $invoice): ?array
    {
        $today = now()->startOfDay();
        $dueOn = $invoice->due_on?->copy()->startOfDay();
        if ($dueOn === null) {
            return null;
        }
        $diff = (int) $today->diffInDays($dueOn, false); // positive: days left
        // Days before the due date: per event (list), otherwise the global default. The latest threshold reached is sent;
        // none for invoices issued after that threshold (they were just sent).
        $before = array_map('intval', $invoice->event->reminder_days_before ?? [Settings::int('invoice_reminder_days_before')]);
        $reached = array_filter($before, fn (int $d) => $d > 0 && $diff > 0 && $diff <= $d
            && ($invoice->issued_on === null || $invoice->issued_on->copy()->startOfDay()->lt($dueOn->copy()->subDays($d))));
        if ($reached !== []) {
            return ['before_due', min($reached)];
        }
        $overdue = -$diff;
        $levels = array_values(array_filter(Settings::intList('invoice_overdue_days'), fn (int $d) => $d <= $overdue));
        if ($levels !== []) {
            return ['overdue', -max($levels)];
        }

        return null;
    }
}
