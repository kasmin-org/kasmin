<?php

namespace App\Support\Accounting;

/**
 * The allocation of a cost centre that is spread over others ("wird umgelegt", E1d.8, E1e.2/3, E1f.3, E1h.1).
 *
 * The amount to spread is what the cost centre cost in the period (its loss as a positive number; a surplus is
 * negative). The key of the period gives every receiving cost centre a kind:
 *  1. fixed – a fixed amount, first. If the costs are smaller than all fixed amounts together, every fixed amount is
 *     cut in the same proportion; with a surplus the fixed amounts are 0 (they neither pay nor receive).
 *  2. percent – a share of what is left after the fixed amounts (basis points, 10000 = 100 %).
 *  3. what is left then goes either to the one "rest" cost centre or, in proportion to their net income, to the cost
 *     centres marked "share".
 * Amounts are whole cents; the sum always equals the amount to spread (largest remainder).
 */
final class Allocation
{
    public const KINDS = ['fixed', 'percent', 'share', 'rest'];

    /**
     * Problems of a key, as text keys of lang/*\/accounting.php (empty = fine).
     *
     * @param  list<array{target: int, kind: string, value: int}>  $rules
     * @return list<string>
     */
    public static function validate(array $rules): array
    {
        $problems = [];
        $targets = array_column($rules, 'target');
        if (count($targets) !== count(array_unique($targets))) {
            $problems[] = 'allocation.twice';
        }
        $kinds = array_count_values(array_column($rules, 'kind'));
        $percent = array_sum(array_map(fn (array $r) => $r['kind'] === 'percent' ? $r['value'] : 0, $rules));
        if (array_diff(array_keys($kinds), self::KINDS) !== [] || min([0, ...array_column($rules, 'value')]) < 0) {
            $problems[] = 'allocation.invalid';
        }
        if ($percent > 10000) {
            $problems[] = 'allocation.over_100';
        }
        if (($kinds['rest'] ?? 0) > 1) {
            $problems[] = 'allocation.one_rest';
        }
        if (($kinds['rest'] ?? 0) > 0 && ($kinds['share'] ?? 0) > 0) {
            $problems[] = 'allocation.rest_or_share';
        }
        if ($rules !== [] && $percent < 10000 && ($kinds['rest'] ?? 0) === 0 && ($kinds['share'] ?? 0) === 0) {
            $problems[] = 'allocation.unassigned';
        }

        return $problems;
    }

    /**
     * @param  int  $cost  what the cost centre cost (positive) or earned (negative) in the period, in cents
     * @param  list<array{target: int, kind: string, value: int}>  $rules
     * @param  array<int, int>  $income  net income per cost centre in the period (for "share")
     * @return array{amounts: array<int, int>, cut: array<int, array{0: int, 1: int}>, errors: list<string>}
     *                                                                                                      amounts: cost borne per receiving cost centre (negative = credit); cut: fixed amounts that were cut [configured, used]
     */
    public static function compute(int $cost, array $rules, array $income = []): array
    {
        $errors = self::validate($rules);
        $amounts = array_fill_keys(array_column($rules, 'target'), 0);
        $cut = [];
        if ($errors !== [] || $rules === []) {
            return ['amounts' => $amounts, 'cut' => $cut, 'errors' => $rules === [] && $cost !== 0 ? ['allocation.no_key'] : $errors];
        }

        // 1. Fixed amounts: first; cut in proportion if the costs are smaller; nothing with a surplus.
        $fixed = array_values(array_filter($rules, fn (array $r) => $r['kind'] === 'fixed'));
        $fixedTotal = array_sum(array_column($fixed, 'value'));
        if ($cost > 0 && $fixedTotal > 0) {
            if ($cost >= $fixedTotal) {
                foreach ($fixed as $r) {
                    $amounts[$r['target']] = $r['value'];
                }
            } else {
                $shares = self::spread($cost, array_combine(array_column($fixed, 'target'), array_column($fixed, 'value')));
                foreach ($fixed as $r) {
                    $amounts[$r['target']] = $shares[$r['target']];
                    $cut[$r['target']] = [$r['value'], $shares[$r['target']]];
                }
            }
        }
        $left = $cost - array_sum($amounts);

        // 2. Percentages of what is left, 3. the rest to one cost centre or by net income – together, so the cents add up.
        $weights = [];
        $percent = 0;
        foreach ($rules as $r) {
            if ($r['kind'] === 'percent') {
                $weights[$r['target']] = $r['value'];
                $percent += $r['value'];
            }
        }
        $restWeight = 10000 - $percent;
        if ($restWeight > 0) {
            $rest = array_values(array_filter($rules, fn (array $r) => $r['kind'] === 'rest'));
            $share = array_values(array_filter($rules, fn (array $r) => $r['kind'] === 'share'));
            if ($rest !== []) {
                $weights[$rest[0]['target']] = $restWeight;
            } else {
                $base = [];
                foreach ($share as $r) {
                    $base[$r['target']] = max(0, (int) ($income[$r['target']] ?? 0));
                }
                $total = array_sum($base);
                if ($total <= 0 && $left !== 0) {
                    return ['amounts' => $amounts, 'cut' => $cut, 'errors' => ['allocation.no_income']];
                }
                // Weights for the share part, in proportion to the income, scaled to the rest weight.
                foreach ($base as $target => $value) {
                    $weights[$target] = $total > 0 ? $value * $restWeight : 0;
                }
                foreach ($weights as $target => $value) {
                    if (! array_key_exists($target, $base)) {
                        $weights[$target] = $value * max(1, $total); // percentages on the same scale
                    }
                }
            }
        }
        foreach (self::spread($left, $weights) as $target => $value) {
            $amounts[$target] += $value;
        }

        return ['amounts' => $amounts, 'cut' => $cut, 'errors' => []];
    }

    /**
     * Splits an amount by weights into whole cents that add up exactly (largest remainder; ties by order).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function spread(int $amount, array $weights): array
    {
        $result = array_fill_keys(array_keys($weights), 0);
        $total = array_sum($weights);
        if ($amount === 0 || $total <= 0) {
            return $result;
        }
        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount);
        $remainders = [];
        // Exact arithmetic: weights by income can be large, products would overflow integers.
        foreach ($weights as $key => $weight) {
            $exact = bcmul((string) $abs, (string) $weight);
            $result[$key] = (int) bcdiv($exact, (string) $total, 0);
            $remainders[$key] = bcmod($exact, (string) $total);
        }
        $missing = $abs - array_sum($result);
        uasort($remainders, fn (string $a, string $b) => bccomp($b, $a));
        foreach (array_keys($remainders) as $key) {
            if ($missing <= 0) {
                break;
            }
            $result[$key]++;
            $missing--;
        }

        return array_map(fn (int $v) => $v * $sign, $result);
    }
}
