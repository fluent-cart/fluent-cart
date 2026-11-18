<?php

namespace Tests\Unit\Modules\MCP;

use Codeception\Test\Unit;
use FluentCart\App\Modules\MCP\Support\PaymentProjector;

/**
 * The forward billing walk shared by get-product-financials and get-upcoming-payments.
 * Pure date math on plain arrays, no WordPress.
 */
class PaymentProjectorTest extends Unit
{
    public function test_finite_subscriptions_on_one_date_anchor_to_one_bucket(): void
    {
        $subs = [
            $this->sub('finite', 'monthly', 9900, 7, '2026-07-20 00:00:00'),
            $this->sub('finite', 'monthly', 9900, 7, '2026-07-20 00:00:00'),
            $this->sub('finite', 'monthly', 9900, 7, '2026-07-20 00:00:00'),
        ];
        $r = PaymentProjector::project($subs, $this->ts('2026-07-01 00:00:00'), $this->ts('2026-07-31 23:59:59'), 'day');

        $this->assertArrayHasKey('2026-07-20', $r['buckets'], 'the three charges land on their shared anchor day');
        $this->assertSame(3 * 9900, $r['buckets']['2026-07-20']['finite'], 'finite amount is 3 x recur on that date');
        $this->assertSame(3, $r['buckets']['2026-07-20']['finite_count'], 'finite_count is 3');
        $this->assertCount(1, $r['buckets'], 'a one-month window holds one bucket; the next cycle is August');
    }

    public function test_finite_subscription_stops_after_its_remaining_bills(): void
    {
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 5000, 3, '2026-07-10 00:00:00')],
            $this->ts('2026-07-01 00:00:00'),
            $this->ts('2027-12-31 23:59:59'),
            'month'
        );
        $sum = 0;
        $count = 0;
        foreach ($r['buckets'] as $b) {
            $sum += $b['finite'];
            $count += $b['finite_count'];
        }

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_keys($r['buckets']), 'monthly steps stop after 3 remaining');
        $this->assertSame(3, $count, 'exactly 3 finite charges');
        $this->assertSame(3 * 5000, $sum, 'finite total is 3 x recur');
    }

    public function test_perpetual_subscription_runs_to_the_horizon(): void
    {
        $r = PaymentProjector::project(
            [$this->sub('perpetual', 'monthly', 100, -1, '2026-07-15 00:00:00')],
            $this->ts('2026-07-01 00:00:00'),
            $this->ts('2026-10-31 23:59:59'),
            'month'
        );
        $count = 0;
        foreach ($r['buckets'] as $b) {
            $count += $b['recurring_count'];
        }

        $this->assertSame(4, $count, 'Jul, Aug, Sep, Oct: 4 recurring charges');
        $this->assertSame(100, $r['recurring_next_30d'], 'only the first charge falls within 30 days of as_of');
    }

    public function test_a_past_anchor_is_fast_forwarded_and_never_billed_behind_as_of(): void
    {
        $r = PaymentProjector::project(
            [$this->sub('perpetual', 'monthly', 100, -1, '2026-01-10 00:00:00')],
            $this->ts('2026-07-01 00:00:00'),
            $this->ts('2026-07-31 23:59:59'),
            'month'
        );
        $count = 0;
        foreach ($r['buckets'] as $b) {
            $count += $b['recurring_count'];
        }

        $this->assertSame(1, $count, 'the January anchor advances to 2026-07-10: one charge');
        $this->assertSame(['2026-07'], array_keys($r['buckets']), 'billed in July, not in the past');
    }

    public function test_month_steps_clamp_to_the_month_end_as_billing_does(): void
    {
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 100, 3, '2027-01-31 10:00:00')],
            $this->ts('2027-01-01 00:00:00'),
            $this->ts('2027-12-31 23:59:59'),
            'day'
        );

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-28'], array_keys($r['buckets']), 'Jan 31 bills Feb 28, not March');
    }

    public function test_a_past_due_installment_is_counted_at_as_of(): void
    {
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 100, 3, '2026-06-20 00:00:00')],
            $this->ts('2026-07-01 00:00:00'),
            $this->ts('2026-12-31 23:59:59'),
            'month'
        );

        $this->assertSame(['2026-07' => 2, '2026-08' => 1], array_map(function ($b) {
            return $b['finite_count'];
        }, $r['buckets']), 'June is still owed: it sits in July with July\'s own payment');
    }

    public function test_a_window_that_starts_later_leaves_out_installments_due_before_it(): void
    {
        // Today is 2026-11-17. The window is June 2027: Dec to May are due before it and are not overdue.
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 100, 12, '2026-12-01 00:00:00')],
            $this->ts('2027-06-01 00:00:00'),
            $this->ts('2027-06-30 23:59:59'),
            'month',
            null,
            $this->ts('2026-11-17 00:00:00')
        );

        $this->assertSame(['2027-06' => 1], array_map(function ($b) {
            return $b['finite_count'];
        }, $r['buckets']), 'Only June\'s own payment, and the six before it still use up the plan');
    }

    public function test_a_future_installment_before_a_window_that_starts_soon_is_left_out(): void
    {
        // Now is Jan 1 noon. The last installment is due Jan 1 18:00: not overdue, and before the Jan 2 window.
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 10000, 1, '2027-01-01 18:00:00')],
            $this->ts('2027-01-02 00:00:00'),
            $this->ts('2027-01-03 23:59:59'),
            'day',
            null,
            $this->ts('2027-01-01 12:00:00')
        );

        $this->assertSame([], $r['buckets']);
    }

    public function test_buckets_use_the_given_timezone(): void
    {
        // 20:00 UTC on Nov 30 is Dec 1 in Dhaka (+06:00)
        $r = PaymentProjector::project(
            [$this->sub('finite', 'monthly', 100, 1, '2026-11-30 20:00:00')],
            $this->ts('2026-11-01 00:00:00'),
            $this->ts('2026-12-31 23:59:59'),
            'month',
            new \DateTimeZone('Asia/Dhaka')
        );

        $this->assertSame(['2026-12'], array_keys($r['buckets']));
    }

    private function sub($settlement, $interval, $recur, $remaining, $anchor): array
    {
        return [
            'settlement'      => $settlement,
            'interval'        => $interval,
            'recur'           => $recur,
            'remaining_bills' => $remaining,
            'anchor'          => $anchor,
            'status'          => 'active',
        ];
    }

    private function ts($s): int
    {
        return strtotime($s . ' UTC');
    }
}
