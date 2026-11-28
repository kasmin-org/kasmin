<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\Allocation;
use PHPUnit\Framework\TestCase;

/** The examples of the documentation are tests (E1i). Cost centres: 1 Summer camp, 2 Weekend, 3 Youth group. */
class AllocationTest extends TestCase
{
    /** @return list<array{target: int, kind: string, value: int}> */
    private function fixedAndRest(): array
    {
        return [['target' => 2, 'kind' => 'fixed', 'value' => 30000], ['target' => 3, 'kind' => 'fixed', 'value' => 10000], ['target' => 1, 'kind' => 'rest', 'value' => 0]];
    }

    public function test_fixed_amounts_first_and_the_rest_to_the_camp(): void
    {
        $this->assertSame([2 => 30000, 3 => 10000, 1 => 160000], Allocation::compute(200000, $this->fixedAndRest())['amounts']);
        $this->assertSame([2 => 30000, 3 => 10000, 1 => 0], Allocation::compute(40000, $this->fixedAndRest())['amounts']);
    }

    public function test_fixed_amounts_larger_than_the_costs_are_cut_in_proportion(): void
    {
        $result = Allocation::compute(20000, $this->fixedAndRest());
        $this->assertSame([2 => 15000, 3 => 5000, 1 => 0], $result['amounts']);
        $this->assertSame([2 => [30000, 15000], 3 => [10000, 5000]], $result['cut']);
        $this->assertSame([], $result['errors']);
    }

    public function test_a_surplus_goes_to_the_percentages_and_the_rest_fixed_amounts_pay_nothing(): void
    {
        $this->assertSame([2 => 0, 3 => 0, 1 => -50000], Allocation::compute(-50000, $this->fixedAndRest())['amounts']);
    }

    public function test_100_and_0_percent(): void
    {
        $rules = [['target' => 1, 'kind' => 'percent', 'value' => 10000], ['target' => 2, 'kind' => 'percent', 'value' => 0]];
        $this->assertSame([1 => 61000, 2 => 0], Allocation::compute(61000, $rules)['amounts']);
    }

    public function test_share_of_the_net_income(): void
    {
        // Administration −2,119.55; income: camp 16,640, weekend 2,280 → 88 % / 12 %.
        $rules = [['target' => 1, 'kind' => 'share', 'value' => 0], ['target' => 2, 'kind' => 'share', 'value' => 0]];
        $result = Allocation::compute(211955, $rules, [1 => 1664000, 2 => 228000]);
        $this->assertSame(211955, array_sum($result['amounts']));
        $this->assertSame([1 => 186413, 2 => 25542], $result['amounts']);
        $this->assertSame(['allocation.no_income'], Allocation::compute(100, $rules, [1 => 0, 2 => -5])['errors']);
    }

    public function test_percent_then_rest_and_the_cents_add_up(): void
    {
        $rules = [['target' => 1, 'kind' => 'percent', 'value' => 3333], ['target' => 2, 'kind' => 'percent', 'value' => 3333], ['target' => 3, 'kind' => 'rest', 'value' => 0]];
        $result = Allocation::compute(100, $rules);
        $this->assertSame(100, array_sum($result['amounts']));
        $this->assertSame([1 => 33, 2 => 33, 3 => 34], $result['amounts']);
        $odd = Allocation::compute(-1001, $rules);
        $this->assertSame(-1001, array_sum($odd['amounts']));
    }

    public function test_keys_are_checked(): void
    {
        $this->assertSame(['allocation.over_100'], Allocation::validate([['target' => 1, 'kind' => 'percent', 'value' => 6000], ['target' => 2, 'kind' => 'percent', 'value' => 5000]]));
        $this->assertSame(['allocation.unassigned'], Allocation::validate([['target' => 1, 'kind' => 'percent', 'value' => 6000]]));
        $this->assertSame(['allocation.one_rest'], Allocation::validate([['target' => 1, 'kind' => 'rest', 'value' => 0], ['target' => 2, 'kind' => 'rest', 'value' => 0]]));
        $this->assertSame(['allocation.rest_or_share'], Allocation::validate([['target' => 1, 'kind' => 'rest', 'value' => 0], ['target' => 2, 'kind' => 'share', 'value' => 0]]));
        $this->assertSame(['allocation.twice'], Allocation::validate([['target' => 1, 'kind' => 'rest', 'value' => 0], ['target' => 1, 'kind' => 'fixed', 'value' => 5]]));
        $this->assertSame([], Allocation::validate([['target' => 1, 'kind' => 'fixed', 'value' => 100], ['target' => 2, 'kind' => 'rest', 'value' => 0]]));
        $this->assertSame(['allocation.no_key'], Allocation::compute(500, [])['errors']);
    }

    public function test_large_amounts_do_not_overflow(): void
    {
        $rules = [['target' => 1, 'kind' => 'share', 'value' => 0], ['target' => 2, 'kind' => 'share', 'value' => 0]];
        $result = Allocation::compute(9_000_000_00, $rules, [1 => 7_000_000_00, 2 => 2_000_000_00]);
        $this->assertSame([1 => 7_000_000_00, 2 => 2_000_000_00], $result['amounts']);
    }
}
