<?php

namespace Tests\Unit\Modules\MCP;

use Codeception\Test\Unit;
use FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator as Calc;

/**
 * The get-product-financials matrix: one-time, finite split-pay, perpetual, mixed,
 * currency isolation, status mix, date basis and as_of shifts. Pure math, no WordPress.
 */
class ProductFinancialsCalculatorTest extends Unit
{
    private const AS_OF = '2026-07-07 00:00:00';

    public function test_one_time_only_product_has_no_subscription_figures(): void
    {
        $r = Calc::compute([], $this->opts(self::AS_OF, ['one_time' => ['units' => 140, 'orders' => 140, 'gross' => 4210000, 'refunds' => 0]]));

        $this->assertSame(0, $r['subscriptions']['recurring']['count'], 'recurring.count');
        $this->assertSame(0, $r['subscriptions']['finite']['count'], 'finite.count');
        $this->assertEqualsWithDelta(0, $r['totals']['mrr'], 0.0001, 'mrr');
        $this->assertSame(4210000, $r['one_time']['net_collected'], 'one_time.net_collected');
        $this->assertSame(4210000, $r['totals']['total_contracted'], 'total_contracted equals one_time.net');
        $this->assertEqualsWithDelta(30071, $r['one_time']['aov'], 0.0001, 'aov is gross / orders');
    }

    public function test_finite_split_pay_sums_remaining_and_contract_value(): void
    {
        $subs = [
            $this->sub('monthly', 9900, 2, 8, 'active', '2026-07-20 00:00:00'),
            $this->sub('monthly', 9900, 8, 8, 'active', '2026-07-20 00:00:00'),
            $this->sub('monthly', 4900, 0, 8, 'active', '2026-07-20 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF));

        $this->assertSame(9900 * 6 + 0 + 4900 * 8, $r['subscriptions']['finite']['scheduled_remaining'], 'scheduled_remaining');
        $this->assertSame(9900 * 8 * 2 + 4900 * 8, $r['subscriptions']['finite']['total_contract_value'], 'total_contract_value');
        $this->assertSame((8 - 2) + (8 - 8) + (8 - 0), $r['subscriptions']['finite']['remaining_installments'], 'remaining_installments');
        $this->assertNotNull($r['totals']['total_contracted'], 'total_contracted is known when no perpetual sub exists');
        $this->assertSame(197600, $r['totals']['total_contracted'], 'total_contracted');
        $this->assertEqualsWithDelta(round((0.25 + 1.0 + 0.0) / 3, 4), $r['subscriptions']['finite']['avg_completion'], 0.0001, 'avg_completion');
    }

    public function test_perpetual_monthly_reports_mrr_and_arr_but_no_contract_total(): void
    {
        $subs = [
            $this->sub('monthly', 3000, 4, 0, 'active', '2026-07-15 00:00:00'),
            $this->sub('monthly', 3000, 4, 0, 'active', '2026-07-15 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF));

        $this->assertNull($r['totals']['total_contracted'], 'a perpetual sub has no contract total');
        $this->assertEqualsWithDelta(6000, $r['subscriptions']['recurring']['mrr'], 0.0001, 'mrr is the sum of recurring_total');
        $this->assertEqualsWithDelta(72000, $r['subscriptions']['recurring']['arr'], 0.0001, 'arr is mrr x 12');
    }

    public function test_perpetual_yearly_normalizes_mrr_and_counts_only_in_window_renewals(): void
    {
        $subs = [
            $this->sub('yearly', 30000, 1, 0, 'active', '2026-07-20 00:00:00'),
            $this->sub('yearly', 30000, 1, 0, 'active', '2027-01-01 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF));

        $this->assertEqualsWithDelta((30000 + 30000) / 12, $r['subscriptions']['recurring']['mrr'], 0.0001, 'mrr is sum / 12');
        $this->assertEqualsWithDelta(30000, $r['subscriptions']['recurring']['next_30d_scheduled'], 0.0001, 'next_30d counts only the in-window renewal');
    }

    public function test_mixed_perpetual_intervals_normalize_to_monthly(): void
    {
        $subs = [
            $this->sub('monthly', 3000, 1, 0, 'active', '2026-07-15 00:00:00'),
            $this->sub('half_yearly', 15000, 1, 0, 'active', '2026-12-01 00:00:00'),
            $this->sub('yearly', 30000, 1, 0, 'active', '2026-08-01 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF));

        $this->assertEqualsWithDelta(3000 + 2500 + 2500, $r['subscriptions']['recurring']['mrr'], 0.0001, 'mrr normalized to 8000');
        $this->assertEqualsWithDelta(3000 + 30000, $r['subscriptions']['recurring']['next_30d_scheduled'], 0.0001, 'next_30d is monthly + yearly');
        $this->assertNotEquals($r['subscriptions']['recurring']['mrr'], $r['subscriptions']['recurring']['next_30d_scheduled'], 'mrr and next_30d are different figures');
        $this->assertCount(3, $r['subscriptions']['recurring']['by_interval'], 'by_interval has one key per interval');
    }

    public function test_mixed_finite_and_perpetual_schedule_adds_up(): void
    {
        $subs = [
            $this->sub('monthly', 9900, 1, 8, 'active', '2026-07-10 00:00:00'),
            $this->sub('yearly', 30000, 1, 0, 'active', '2026-08-01 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF, ['include_schedule' => true]));
        $sumFinite = 0;
        $sumRecur = 0;
        foreach ($r['payment_schedule'] as $b) {
            $sumFinite += $b['finite_installments'];
            $sumRecur += $b['recurring_renewals'];
        }

        $this->assertNull($r['totals']['total_contracted'], 'a perpetual sub makes the total unknown');
        $this->assertSame(9900 * 7, $r['subscriptions']['finite']['scheduled_remaining'], 'finite.scheduled_remaining');
        $this->assertSame(9900 * 7, $sumFinite, 'schedule finite total');
        $this->assertGreaterThan(0, $sumRecur, 'schedule has recurring renewals');
    }

    public function test_finite_quarterly_split_pay_steps_by_quarter_and_stops(): void
    {
        $r = Calc::compute(
            [$this->sub('quarterly', 5000, 1, 4, 'active', '2026-07-10 00:00:00')],
            $this->opts(self::AS_OF, ['include_schedule' => true])
        );
        $finiteBuckets = 0;
        $sumFinite = 0;
        foreach ($r['payment_schedule'] as $b) {
            if ($b['finite_installments'] > 0) {
                $finiteBuckets++;
                $sumFinite += $b['finite_installments'];
            }
        }
        $periods = array_map(function ($b) {
            return $b['period'];
        }, $r['payment_schedule']);

        $this->assertSame(3, $finiteBuckets, 'stops after 3 remaining bills');
        $this->assertSame(15000, $sumFinite, 'total finite is 3 x 5000');
        $this->assertContains('2026-07', $periods, 'quarterly step: July');
        $this->assertContains('2026-10', $periods, 'quarterly step: October');
        $this->assertContains('2027-01', $periods, 'quarterly step: January');
    }

    public function test_interval_switch_uses_the_current_interval_forward_and_bill_count_backward(): void
    {
        $r = Calc::compute([$this->sub('yearly', 30000, 10, 0, 'active', '2026-08-01 00:00:00')], $this->opts(self::AS_OF));

        $this->assertEqualsWithDelta(2500, $r['subscriptions']['recurring']['mrr'], 0.0001, 'forward uses the current yearly interval');
        $this->assertSame(30000 * 10, $r['subscriptions']['recurring']['collected_to_date'], 'collected reflects bill_count');
    }

    public function test_currencies_are_never_summed_across(): void
    {
        $rows = [
            ['currency' => 'USD'] + $this->sub('monthly', 3000, 1, 0, 'active', '2026-07-15 00:00:00'),
            ['currency' => 'usd'] + $this->sub('monthly', 2000, 1, 0, 'active', '2026-07-15 00:00:00'),
            ['currency' => 'EUR'] + $this->sub('monthly', 9999, 1, 0, 'active', '2026-07-15 00:00:00'),
        ];
        list($kept, $others) = Calc::filterByCurrency($rows, 'USD');
        $r = Calc::compute($kept, $this->opts(self::AS_OF));

        $this->assertCount(2, $kept, 'keeps USD rows, case-insensitive');
        $this->assertSame(['EUR'], $others, 'other_currencies lists EUR');
        $this->assertEqualsWithDelta(3000 + 2000, $r['subscriptions']['recurring']['mrr'], 0.0001, 'mrr is USD only');
    }

    public function test_status_mix_counts_forward_only_for_active_and_collected_for_billed(): void
    {
        $subs = [
            $this->sub('monthly', 9900, 2, 8, 'active', '2026-07-20 00:00:00'),
            $this->sub('monthly', 9900, 3, 8, 'past_due', '2026-07-20 00:00:00'),
            $this->sub('yearly', 30000, 1, 0, 'canceled', '2026-07-20 00:00:00'),
            $this->sub('monthly', 3000, 0, 0, 'trialing', '2026-07-20 00:00:00'),
            $this->sub('quarterly', 5000, 1, 4, 'intended', '2026-07-20 00:00:00'),
        ];
        $r = Calc::compute($subs, $this->opts(self::AS_OF));

        $this->assertSame(9900 * 6, $r['subscriptions']['finite']['scheduled_remaining'], 'scheduled_remaining counts active only');
        $this->assertSame(1, $r['subscriptions']['finite']['count'], 'finite.count is active only');
        $this->assertSame(0, $r['subscriptions']['recurring']['count'], 'canceled and trialing are not forward');
        $this->assertEqualsWithDelta(0, $r['subscriptions']['recurring']['next_30d_scheduled'], 0.0001, 'no future renewals from canceled');
        $this->assertNotNull($r['totals']['total_contracted'], 'no active perpetual, so the total is known');
        $this->assertSame(9900 * 2 + 9900 * 3 + 30000 * 1, $r['totals']['collected_to_date'], 'intended is excluded from collected');
        $this->assertSame(1, $r['subscriptions']['status_breakdown']['intended'], 'intended appears in status_breakdown');
        $this->assertSame(1, $r['subscriptions']['status_breakdown']['past_due'], 'past_due appears in status_breakdown');
    }

    public function test_date_basis_shifts_one_time_figures_but_not_subscriptions(): void
    {
        $subs = [$this->sub('monthly', 3000, 4, 0, 'active', '2026-07-15 00:00:00')];
        $created = Calc::compute($subs, $this->opts(self::AS_OF, ['one_time' => ['units' => 10, 'orders' => 10, 'gross' => 100000, 'refunds' => 0]]));
        $paid = Calc::compute($subs, $this->opts(self::AS_OF, ['one_time' => ['units' => 6, 'orders' => 6, 'gross' => 60000, 'refunds' => 0]]));

        $this->assertNotEquals($created['one_time']['gross_collected'], $paid['one_time']['gross_collected'], 'one_time differs by basis');
        $this->assertSame($created['subscriptions'], $paid['subscriptions'], 'subscriptions are identical across basis');
    }

    public function test_as_of_shift_drops_elapsed_buckets_and_keeps_lifetime_figures(): void
    {
        $subs = [$this->sub('monthly', 3000, 4, 0, 'active', '2026-07-15 00:00:00')];
        $early = Calc::compute($subs, $this->opts('2026-07-07 00:00:00', ['include_schedule' => true]));
        $later = Calc::compute($subs, $this->opts('2026-09-01 00:00:00', ['include_schedule' => true]));

        $this->assertSame('2026-07', $early['payment_schedule'][0]['period'], 'early first bucket');
        $this->assertGreaterThanOrEqual('2026-09', $later['payment_schedule'][0]['period'], 'later first bucket drops elapsed months');
        $this->assertSame(
            $early['subscriptions']['recurring']['collected_to_date'],
            $later['subscriptions']['recurring']['collected_to_date'],
            'lifetime collected is unchanged'
        );
    }

    private function sub($interval, $recur, $billCount, $billTimes, $status, $nextBill): array
    {
        return [
            'billing_interval'  => $interval,
            'recurring_total'   => $recur,
            'bill_count'        => $billCount,
            'bill_times'        => $billTimes,
            'status'            => $status,
            'next_billing_date' => $nextBill,
        ];
    }

    private function opts($asOf, array $extra = []): array
    {
        return array_merge(['as_of' => $asOf, 'horizon_months' => 12, 'bucket' => 'month'], $extra);
    }
}
