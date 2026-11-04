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
