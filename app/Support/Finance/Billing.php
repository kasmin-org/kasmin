<?php

namespace App\Support\Finance;

use App\Models\Event;
use App\Models\EventInstallment;
use App\Models\EventPrice;
use App\Models\EventRole;
use App\Models\Household;
use App\Models\InvoiceLine;
use App\Models\Participation;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * What a family has to pay for one instalment of an event: the single source for new invoices and corrections.
 */
class Billing
{
    /** Participants that are invoiced. */
    public const BILLED = ['registered', 'confirmed', 'offered', 'attended', 'no_show'];

    public function __construct(private readonly SiblingDiscounts $discounts) {}

    /** The family that pays; null if the person pays itself: registered itself, or of legal age (PE05, PE05a). */
    public static function household(Participation $participation): ?Household
    {
        $person = $participation->person;
        $adult = ($person?->ageAt($participation->event?->starts_at) ?? 0) >= Settings::int('guardian_until_age');

        return $person === null || $participation->isOwn() || $adult ? null : $person->households->first();
    }

    /** Price of a participant before discounts (PR01, PR02). */
    public function price(Event $event, Participation $participation): int
    {
        if ($participation->price_cents !== null) {
            return $participation->price_cents;
        }
        $age = $participation->person?->ageAt($event->ageReferenceDate());
        /** @var EventPrice|null $rule */
        $rule = $event->prices->first(fn (EventPrice $p) => $p->applies($participation->role_key, $age, $participation->registered_at));
        /** @var EventRole|null $role */
        $role = $event->roles->firstWhere('role_key', $participation->role_key);

        return $rule->price_cents ?? $role->price_cents ?? 0;
    }

    /** Amount of one instalment before discounts. */
    public function base(Event $event, EventInstallment $installment, Participation $participation): int
    {
        $left = $this->price($event, $participation);
        $capped = $participation->price_cents !== null; // a reduced place never costs more than its price
        foreach ($event->installments as $other) {
            if ($other->kind === 'rest') {
                continue;
            }
            $amount = $this->fixed($other, $participation);
            // A fixed instalment is a part of the price, never more: who takes part for free (e.g. the team) owes nothing,
            // and a reduced place never costs more than its price. A membership fee is owed by its own rules.
            $amount = $capped || $other->kind !== 'member' ? min($amount, max(0, $left)) : $amount;
            if ($other->is($installment)) {
                return $amount;
            }
            $left -= $amount;
        }

        return max(0, $left);
    }

    /**
     * Lines of a family for an instalment. Cancelled participants only appear if they were invoiced before and the
     * amount is not refunded (RF01).
     *
     * @param  Collection<int, Participation>  $participations  all of the family in this event
     * @return list<array{participation_id: int, person_id: int, description: string, amount_cents: int, discount_cents: int, discount_kind: string|null, discount_label: string|null, discount_position: int|null, membership_type_id: int|null, period: int|null}>
     */
    public function lines(Event $event, EventInstallment $installment, Collection $participations, string $locale): array
    {
        $event->loadMissing('roles', 'installments', 'prices');
        $participations->each(fn (Participation $p) => $p->loadMissing('person'));
        $active = $participations->whereIn('status', self::BILLED)->values();
        $discounts = $installment->discounts_apply ? $this->discounts->compute($event, $active->whereNull('price_cents')->values()) : [];
        $result = [];
        foreach ($active->sortBy(fn (Participation $p) => $discounts[$p->id]['position'] ?? 99) as $p) {
            if ($installment->kind === 'member' && $this->feeAlreadyBilled($event, $installment, $p)) {
                continue;
            }
            $base = $this->base($event, $installment, $p);
            if ($base === 0 && $p->price_cents === null) {
                continue; // nothing to pay in this instalment; a reduced place stays visible with 0
            }
            $d = $discounts[$p->id] ?? ['discount' => 0, 'kind' => null, 'position' => 0];
            $discount = min($base, $d['discount']);
            $description = trans('finance.line', ['installment' => $installment->labelText($locale), 'name' => $p->person?->fullName()], $locale);
            // The discount is its own position on the invoice (B10).
            $label = $discount > 0 ? (string) trans('finance.discount_'.$d['kind'], ['position' => $d['position']], $locale) : null;
            $result[] = $this->line($event, $installment, $p, $description, $base - $discount, $discount, $discount > 0 ? $d['kind'] : null, $label, $discount > 0 && $d['kind'] === 'sibling' ? (int) $d['position'] : null);
        }
        foreach ($participations->where('status', 'cancelled') as $p) {
            $billed = $this->billedLine($event, $installment, $p);
            if ($billed === null) {
                continue;
            }
            if (! $this->refunded($event, $installment, $p)) {
                $result[] = $this->line($event, $installment, $p, $billed->description, $billed->amount_cents, $billed->discount_cents, $billed->discount_kind, $billed->discount_label, $billed->discount_position);
            } elseif ($event->cancellation_fee_cents > 0 && $this->feeInstallment($event)?->is($installment)) {
                $result[] = $this->line($event, $installment, $p, trans('finance.cancellation_fee', ['name' => $p->person?->fullName()], $locale), $event->cancellation_fee_cents, 0, null);
            }
        }

        return $result;
    }

    /** Is the instalment paid back after a cancellation (RF01)? */
    public function refunded(Event $event, EventInstallment $installment, Participation $participation): bool
    {
        if (! $installment->refundable) {
            return false;
        }
        if ($installment->refund_until_days === null) {
            return true;
        }
        $cancelled = $participation->cancelled_at ?? now();

        return $cancelled->toDateString() <= $event->starts_at->copy()->subDays($installment->refund_until_days)->toDateString();
    }

    /** The cancellation fee is charged once, with the last refundable instalment. */
    private function feeInstallment(Event $event): ?EventInstallment
    {
        return $event->installments->where('refundable', true)->last();
    }

    private function fixed(EventInstallment $installment, Participation $participation): int
    {
        return $installment->kind === 'member' ? (int) $installment->membershipType?->fee_cents : (int) $installment->amount_cents;
    }

    private function feeAlreadyBilled(Event $event, EventInstallment $installment, Participation $p): bool
    {
        return InvoiceLine::query()->where('person_id', $p->person_id)->where('membership_type_id', $installment->membership_type_id)
            ->where('period', (int) $event->starts_at->format('Y'))
            ->whereHas('invoice', fn ($q) => $q->where('type', 'invoice')->whereNotIn('status', ['cancelled', 'corrected'])
                ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', '!=', $event->id)))->exists();
    }

    /** The line of the valid invoice, if the participant was invoiced for this instalment. */
    private function billedLine(Event $event, EventInstallment $installment, Participation $p): ?InvoiceLine
    {
        return InvoiceLine::query()->where('participation_id', $p->id)
            ->whereHas('invoice', fn ($q) => $q->where('event_id', $event->id)->where('installment_key', $installment->key)->where('type', 'invoice')->whereNotIn('status', ['cancelled', 'corrected']))
            ->latest('id')->first();
    }

    /** @return array{participation_id: int, person_id: int, description: string, amount_cents: int, discount_cents: int, discount_kind: string|null, discount_label: string|null, discount_position: int|null, membership_type_id: int|null, period: int|null} */
    private function line(Event $event, EventInstallment $installment, Participation $p, string $description, int $amount, int $discount, ?string $kind, ?string $label = null, ?int $position = null): array
    {
        $member = $installment->kind === 'member';

        return [
            'participation_id' => $p->id, 'person_id' => $p->person_id, 'description' => $description, 'amount_cents' => $amount,
            'discount_cents' => $discount, 'discount_kind' => $kind, 'discount_label' => $label, 'discount_position' => $position, 'membership_type_id' => $member ? $installment->membership_type_id : null,
            'period' => $member ? (int) $event->starts_at->format('Y') : null,
        ];
    }
}
