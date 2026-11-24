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
    /** @return int number of reminders queued (everything that is due) */
    public function run(): int
    {
        return collect($this->pending())->filter(fn (array $r) => $this->send($r['invoice'], $r['kind'], $r['days']))->count();
    }

    /**
     * Reminders due today that were not sent yet.
     *
     * @return list<array{invoice: Invoice, kind: string, days: int}>
     */
    public function pending(): array
    {
        $result = [];
        $invoices = Invoice::query()->where('type', 'invoice')->whereIn('status', ['issued', 'partially_paid'])->whereNotNull('due_on')->with('event', 'payer', 'household')->get();
        foreach ($invoices as $invoice) {
            $due = $this->due($invoice);
            if ($due !== null && $invoice->payer !== null
                && ! DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('kind', $due[0])->where('days', $due[1])->exists()) {
                $result[] = ['invoice' => $invoice, 'kind' => $due[0], 'days' => $due[1]];
            }
        }

        return $result;
    }

    public function send(Invoice $invoice, string $kind, int $days): bool
    {
        $payer = $invoice->payer;
        if ($payer === null || ! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
            return false;
        }
        if (DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $days, 'sent_at' => now()]) === 0) {
            return false;
        }

        $attachments = [['invoice_id' => $invoice->id, 'paid' => false]];
        $fee = $kind === 'overdue' ? $this->fee($invoice, abs($days)) : null;
        if ($fee !== null) {
            $attachments[] = ['invoice_id' => $fee->id, 'paid' => false];
        }

        return app(Composer::class)->queue($kind === 'before_due' ? 'payment_reminder' : 'payment_overdue', $payer, [
            'household' => $invoice->household, 'event' => $invoice->event, 'invoice' => $invoice,
        ], attachments: $attachments) !== null;
    }

    /** Reminder fee of a level in cents; the fees are listed in the same order as the days (DU01a, off by default). */
    public function feeCents(int $days): int
    {
        $levels = Settings::intList('invoice_overdue_days');
        sort($levels);
        $position = array_search($days, $levels, true);
        $fees = array_map('trim', explode(';', (string) Settings::get('invoice_overdue_fees')));

        return $position === false ? 0 : (int) round(((float) str_replace(',', '.', $fees[$position] ?? '0')) * 100);
    }

    /** Issued invoices never change, so the fee is an own invoice; there is no fee on a fee. */
    private function fee(Invoice $invoice, int $days): ?Invoice
    {
        $cents = $invoice->fee_for_id === null ? $this->feeCents($days) : 0;
        if ($cents <= 0) {
            return null;
        }
        $locale = $invoice->payer->locale ?? app()->getLocale();

        return DB::transaction(function () use ($invoice, $cents, $locale) {
            $fee = Invoice::query()->create([
                'household_id' => $invoice->household_id, 'payer_id' => $invoice->payer_id, 'currency' => $invoice->currency, 'total_cents' => $cents,
                'payer_name' => $invoice->payer_name, 'payer_address' => $invoice->payer_address, 'fee_for_id' => $invoice->id, 'created_by_id' => auth()->id(),
            ]);
            $fee->lines()->create(['description' => trans('finance.reminder_fee', ['number' => $invoice->number], $locale), 'amount_cents' => $cents, 'discount_cents' => 0, 'sort' => 0]);

            return app(Invoices::class)->issue($fee);
        });
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
