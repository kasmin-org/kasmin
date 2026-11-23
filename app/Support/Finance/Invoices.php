<?php

namespace App\Support\Finance;

use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\EventRole;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Mail\Composer;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invoice runs per family and instalment (IN01, IN02), issuing with number and RF reference (IN03, IN05),
 * cancellation by credit note (IN10, RF02).
 */
class Invoices
{
    public function __construct(private readonly SiblingDiscounts $discounts) {}

    /**
     * Creates draft invoices for all families with occupying participations that have no invoice for this
     * instalment yet. Returns the number of invoices created.
     */
    public function draftRun(Event $event, EventInstallment $installment): int
    {
        $event->loadMissing('roles', 'installments');
        $participations = Participation::query()->where('event_id', $event->id)
            ->whereIn('status', ['registered', 'confirmed', 'offered', 'attended'])->with(['person.guardians', 'person.households'])->get();

        $byHousehold = $participations->groupBy(fn (Participation $p) => $p->person?->households->first()->id ?? 'p'.$p->person_id);
        $created = 0;
        foreach ($byHousehold as $group) {
            /** @var Participation $first */
            $first = $group->first();
            $household = $first->person?->households->first();
            $exists = Invoice::query()->where('event_id', $event->id)->where('installment_key', $installment->key)->where('type', 'invoice')
                ->whereNotIn('status', ['cancelled', 'corrected'])
                ->where(fn ($q) => $household ? $q->where('household_id', $household->id) : $q->whereHas('lines', fn ($l) => $l->whereIn('person_id', $group->pluck('person_id'))))->exists();
            if ($exists) {
                continue;
            }
            $this->draftFor($event, $installment, $group, $household);
            $created++;
        }

        return $created;
    }

    /**
     * @param  Collection<int, Participation>  $participations
     */
    public function draftFor(Event $event, EventInstallment $installment, Collection $participations, ?Household $household): Invoice
    {
        $roles = $event->roles->keyBy('role_key');
        $discounts = $installment->discounts_apply ? $this->discounts->compute($event, $participations) : [];
        $fixedOthers = $event->installments->where('kind', 'fixed')->where('key', '!=', $installment->key)->sum('amount_cents');
        $payer = $this->payer($household, $participations);

        return DB::transaction(function () use ($event, $installment, $participations, $household, $roles, $discounts, $fixedOthers, $payer) {
            $invoice = Invoice::query()->create([
                'household_id' => $household?->id, 'payer_id' => $payer?->id, 'event_id' => $event->id,
                'installment_key' => $installment->key, 'currency' => $event->currency, 'created_by_id' => auth()->id(),
                'payer_name' => $payer?->fullName(), 'payer_address' => $household ? trim(implode("\n", array_filter([$household->getAttribute('street'), trim($household->getAttribute('postal_code').' '.$household->getAttribute('city'))]))) : null,
            ]);
            $total = 0;
            $sort = 0;
            $locale = $payer->locale ?? app()->getLocale();
            foreach ($participations->sortBy(fn (Participation $p) => $discounts[$p->id]['position'] ?? 99) as $p) {
                /** @var EventRole|null $role */
                $role = $roles[$p->role_key] ?? null;
                $base = $installment->kind === 'fixed' ? (int) $installment->amount_cents : max(0, ($role->price_cents ?? 0) - (int) $fixedOthers);
                $d = $discounts[$p->id] ?? ['discount' => 0, 'kind' => null, 'position' => 0];
                $discount = min($base, $d['discount']);
                $name = $p->person?->fullName() ?? '';
                $description = trans('finance.line', ['installment' => $installment->labelText($locale), 'name' => $name], $locale);
                if ($discount > 0) {
                    $description .= ' – '.trans('finance.discount_'.$d['kind'], ['position' => $d['position']], $locale);
                }
                $invoice->lines()->create([
                    'participation_id' => $p->id, 'person_id' => $p->person_id, 'description' => $description,
                    'amount_cents' => $base - $discount, 'discount_cents' => $discount, 'discount_kind' => $discount > 0 ? $d['kind'] : null, 'sort' => $sort++,
                ]);
                $total += $base - $discount;
            }
            $invoice->forceFill(['total_cents' => $total])->save();

            return $invoice;
        });
    }

    /** Assigns number, reference, dates; status issued. */
    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Only drafts can be issued.');
        }

        return DB::transaction(function () use ($invoice) {
            DB::table('system_meta')->insertOrIgnore(['key' => 'invoice_next_number', 'value' => Settings::get('invoice_next_number'), 'created_at' => now(), 'updated_at' => now()]);
            $next = max(1, (int) DB::table('system_meta')->where('key', 'invoice_next_number')->lockForUpdate()->value('value'));
            Settings::set('invoice_next_number', (string) ($next + 1));
            $number = str_replace(['{number}', '{year}'], [(string) $next, now()->format('Y')], (string) Settings::get('invoice_number_format'));

            $installment = $invoice->event?->installments()->where('key', $invoice->installment_key)->first();
            $due = $installment->due_date ?? now()->addDays($installment->due_days ?? Settings::int('invoice_due_days'));
            $invoice->forceFill([
                'number' => $number, 'reference' => self::creditorReference($number), 'status' => $invoice->total_cents > 0 ? 'issued' : 'paid',
                'issued_on' => now()->toDateString(), 'due_on' => $due->toDateString() < now()->toDateString() ? now()->addDays(14)->toDateString() : $due->toDateString(),
            ])->save();

            return $invoice;
        });
    }

    /** Queues the invoice mail with the PDF to the payer (IN06). */
    public function send(Invoice $invoice): bool
    {
        if ($invoice->payer === null || $invoice->status === 'draft') {
            return false;
        }

        return app(Composer::class)->queue('invoice', $invoice->payer, [
            'household' => $invoice->household, 'event' => $invoice->event, 'invoice' => $invoice,
        ], attachments: [['invoice_id' => $invoice->id, 'paid' => false]]) !== null;
    }

    /** Cancels an issued invoice with a credit note (IN10). */
    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        $credit = $invoice->replicate(['number', 'reference', 'issued_on', 'due_on', 'paid_cents']);
        $credit->fill(['type' => 'credit_note', 'status' => 'draft', 'total_cents' => -$invoice->total_cents, 'corrects_id' => $invoice->id])->save();
        foreach ($invoice->lines as $line) {
            $credit->lines()->create(['person_id' => $line->person_id, 'participation_id' => $line->participation_id, 'description' => $line->description.' ('.$reason.')', 'amount_cents' => -$line->amount_cents, 'sort' => $line->getAttribute('sort')]);
        }
        $this->issue($credit);
        $invoice->forceFill(['status' => 'cancelled'])->save();

        return $credit;
    }

    /**
     * Structured creditor reference (ISO 11649), e.g. "RF18 R123" → "RF18R123", used in the EPC QR code.
     */
    public static function creditorReference(string $number): string
    {
        $ref = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
        $numeric = '';
        foreach (str_split($ref.'RF00') as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $check = 98 - (int) bcmod($numeric, '97');

        return 'RF'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$ref;
    }

    /** @param Collection<int, Participation> $participations */
    private function payer(?Household $household, Collection $participations): ?Person
    {
        if ($household !== null) {
            $recipient = $household->invoiceRecipient;
            if ($recipient !== null) {
                return $recipient;
            }
            $guardian = $household->guardians()->first();
            if ($guardian !== null) {
                return $guardian;
            }
        }
        $person = $participations->first()?->person;

        return $person?->guardians->first() ?? $person;
    }
}
