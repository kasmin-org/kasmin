<?php

namespace App\Support\Accounting;

use App\Models\AllocationRule;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Support\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Periods of the accounting (E1d.1, E1f.6, E1g): date ranges without gaps or overlaps. A new period takes over the
 * active cost centres and the allocation keys of the one before. The dates of open periods can change; bookings move
 * with them because they belong to a period by their date. Closed periods are changed only after reopening, and
 * reopening also reopens all later ones.
 */
class Periods
{
    public function create(string $name, \DateTimeInterface|string $startsOn, \DateTimeInterface|string $endsOn): Period
    {
        $start = Carbon::parse($startsOn)->startOfDay();
        $end = Carbon::parse($endsOn)->startOfDay();
        if ($end->lt($start)) {
            throw AccountingException::because('period_dates');
        }
        $last = Period::query()->orderByDesc('ends_on')->first();
        $first = Period::query()->orderBy('starts_on')->first();
        if ($last !== null && $first !== null && ! $start->equalTo($last->ends_on->copy()->addDay()) && ! $end->equalTo($first->starts_on->copy()->subDay())) {
            throw AccountingException::because('period_gap', ['date' => $last->ends_on->copy()->addDay()->isoFormat('L')]);
        }

        return DB::transaction(function () use ($name, $start, $end, $last) {
            $period = Period::query()->create(['name' => trim($name), 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'status' => 'open']);
            $previous = $last !== null && $start->greaterThan($last->ends_on) ? $last : null;
            // The active cost centres and the keys of the period before (as they were set, not as cut at the closing).
            $active = $previous?->costCenters()->pluck('cost_centers.id')->all() ?? CostCenter::query()->pluck('id')->all();
            $period->costCenters()->sync(array_values(array_unique([...$active, CostCenter::default()->id])));
            foreach ($previous === null ? [] : $previous->allocationRules as $rule) {
                AllocationRule::query()->create(['period_id' => $period->id] + $rule->only(['source_cost_center_id', 'target_cost_center_id', 'kind', 'value']));
            }
            Audit::record('period.created', $period);

            return $period;
        });
    }

    /**
     * The period for an automatic booking: after the last period the next ones are made with the same length. Null
     * before the first period (the accounting has not started then).
     */
    public function ensureFor(\DateTimeInterface|string $date): ?Period
    {
        $day = Carbon::parse($date)->startOfDay();
        $period = Period::of($day);
        if ($period !== null) {
            return $period;
        }
        $last = Period::query()->orderByDesc('ends_on')->first();
        // Not before the first period, and not more than two years ahead (a typing error would make many periods).
        if ($last === null || $day->lt($last->starts_on) || $day->gt($last->ends_on->copy()->addYears(2))) {
            return null;
        }
        while ($last->ends_on->lt($day)) {
            $start = $last->ends_on->copy()->addDay();
            $yearly = $last->starts_on->diffInDays($last->ends_on) >= 360;
            $end = $yearly ? $start->copy()->addYear()->subDay() : $start->copy()->addDays((int) $last->starts_on->diffInDays($last->ends_on));
            $last = $this->create($this->nextName($last, $start, $end), $start, $end);
        }

        return $last;
    }

    /**
     * Changes the dates of an open period; the neighbour moves along, so there are no gaps. Both must be open.
     *
     * @return array{moved: int} bookings that now belong to another period
     */
    public function move(Period $period, \DateTimeInterface|string $startsOn, \DateTimeInterface|string $endsOn): array
    {
        $start = Carbon::parse($startsOn)->startOfDay();
        $end = Carbon::parse($endsOn)->startOfDay();
        $before = Period::query()->where('ends_on', '<', $period->starts_on)->orderByDesc('ends_on')->first();
        $after = Period::query()->where('starts_on', '>', $period->ends_on)->orderBy('starts_on')->first();
        $affected = collect([$period, $start->notEqualTo($period->starts_on) ? $before : null, $end->notEqualTo($period->ends_on) ? $after : null])->filter();
        if ($end->lt($start) || ($before !== null && $start->lte($before->starts_on)) || ($after !== null && $end->gte($after->ends_on))) {
            throw AccountingException::because('period_dates');
        }
        if ($affected->contains(fn (Period $p) => $p->isClosed())) {
            throw AccountingException::because('closed', ['period' => $affected->first(fn (Period $p) => $p->isClosed())?->name]);
        }
        $moved = $this->movingEntries($period, $start, $end)->count();

        DB::transaction(function () use ($period, $start, $end, $before, $after) {
            $old = [$period->starts_on->toDateString(), $period->ends_on->toDateString()];
            // Free the unique dates first, then set them in an order that never overlaps.
            $period->forceFill(['starts_on' => '1000-01-01', 'ends_on' => '1000-01-02'])->save();
            if ($before !== null && $start->notEqualTo(Carbon::parse($old[0]))) {
                $before->forceFill(['ends_on' => $start->copy()->subDay()->toDateString()])->save();
            }
            if ($after !== null && $end->notEqualTo(Carbon::parse($old[1]))) {
                $after->forceFill(['starts_on' => $end->copy()->addDay()->toDateString()])->save();
            }
            $period->forceFill(['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()])->save();
            Audit::record('period.moved', $period, null, ['from' => $old, 'to' => [$start->toDateString(), $end->toDateString()]]);
        });

        return ['moved' => $moved];
    }

    /** @return Collection<int, JournalEntry> bookings that would belong to another period with the new dates */
    public function movingEntries(Period $period, Carbon $start, Carbon $end): Collection
    {
        $from = min($period->starts_on, $start)->toDateString();
        $to = max($period->ends_on, $end)->toDateString();

        return JournalEntry::query()->whereBetween('date', [$from, $to])->get()
            ->filter(fn (JournalEntry $e) => $e->date->betweenIncluded($period->starts_on, $period->ends_on) !== $e->date->betweenIncluded($start, $end))->values();
    }

    /** @return Collection<int, Period> the period and all later closed ones that would be reopened with it */
    public function reopening(Period $period): Collection
    {
        return Period::query()->where('starts_on', '>=', $period->starts_on)->where('status', 'closed')->orderBy('starts_on')->get();
    }

    /**
     * Reopens the period and all later closed ones; their closing bookings are removed (E1f.6, E1g).
     *
     * @return Collection<int, Period>
     */
    public function reopen(Period $period, string $reason): Collection
    {
        $periods = $this->reopening($period);
        DB::transaction(function () use ($periods, $reason) {
            foreach ($periods as $p) {
                JournalEntry::query()->where('period_closing_id', $p->id)->get()->each(function (JournalEntry $e) {
                    $e->lines()->delete();
                    $e->delete();
                });
                $p->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by_id' => null, 'reopened_reason' => trim($reason)])->save();
                Audit::record('period.reopened', $p, null, ['reason' => trim($reason)]);
            }
        });

        return $periods;
    }

    private function nextName(Period $last, Carbon $start, Carbon $end): string
    {
        // "2027" becomes "2028"; otherwise the years of the dates.
        if (preg_match('/^(.*?)(\d{4})(\D*)$/', $last->name, $m) === 1) {
            return $m[1].((int) $m[2] + 1).$m[3];
        }

        return $start->year === $end->year ? (string) $start->year : $start->year.'/'.$end->year;
    }
}
