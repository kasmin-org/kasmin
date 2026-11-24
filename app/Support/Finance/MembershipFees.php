<?php

namespace App\Support\Finance;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Membership;
use App\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Yearly membership fees (PR04), one invoice per family. A fee that was already invoiced as the deposit of an event of the
 * same year is not invoiced again.
 */
class MembershipFees
{
    /** @return Collection<string, Collection<int, Membership>> */
    public function pending(int $year): Collection
    {
        $memberships = Membership::query()->with('type', 'person.households', 'person.guardians')
            ->whereDate('starts_on', '<=', $year.'-12-31')->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $year.'-01-01'))
            ->whereHas('type', fn ($q) => $q->where('fee_cents', '>', 0)->where('pays_fee', true))
            ->whereHas('person', fn ($q) => $q->whereNull('anonymised_at'))->get()->unique('person_id')
            ->reject(fn (Membership $m) => InvoiceLine::query()->where('person_id', $m->person_id)->where('membership_type_id', $m->membership_type_id)->where('period', $year)
                ->whereHas('invoice', fn ($q) => $q->where('type', 'invoice')->whereNotIn('status', ['cancelled', 'corrected']))->exists());

        $groups = [];
        foreach ($memberships as $m) {
            $groups[(string) ($m->person?->households->first()->id ?? 'p'.$m->person_id)][] = $m;
        }

        return collect($groups)->mapWithKeys(fn (array $g, int|string $key) => [(string) $key => collect($g)]);
    }

    /** @param  Collection<int, Membership>  $group */
    public function draft(int $year, Collection $group): Invoice
    {
        /** @var Membership $first */
        $first = $group->first();
        $household = $first->person?->households->first();
        $payer = $this->payer($household, $first->person);
        $locale = $payer->locale ?? app()->getLocale();

        return DB::transaction(function () use ($year, $group, $household, $payer, $locale) {
            $invoice = Invoice::query()->create([
                'household_id' => $household?->id, 'payer_id' => $payer?->id, 'installment_key' => 'membership-'.$year, 'created_by_id' => auth()->id(),
                'payer_name' => $payer?->fullName(), 'payer_address' => $household ? trim(implode("\n", array_filter([$household->getAttribute('street'), trim($household->getAttribute('postal_code').' '.$household->getAttribute('city'))]))) : null,
            ]);
            $sort = 0;
            foreach ($group as $m) {
                $invoice->lines()->create([
                    'person_id' => $m->person_id, 'membership_type_id' => $m->membership_type_id, 'period' => $year, 'sort' => $sort++,
                    'description' => trans('finance.membership_line', ['year' => $year, 'name' => $m->person?->fullName()], $locale),
                    'amount_cents' => (int) $m->type?->fee_cents, 'discount_cents' => 0,
                ]);
            }
            $invoice->forceFill(['total_cents' => (int) $invoice->lines()->sum('amount_cents')])->save();

            return $invoice;
        });
    }

    private function payer(?Household $household, ?Person $person): ?Person
    {
        return $household->invoiceRecipient ?? $household?->guardians()->first() ?? $person?->guardians->first() ?? $person;
    }
}
