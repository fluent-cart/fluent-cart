<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\PaymentScheduleTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * get-upcoming-payments: the forward billing projection.
 *
 * Asserts the two rules the shared PaymentProjector encodes — anchor on
 * next_billing_date and stop finite plans at their remaining bills — end to end
 * through the tool, plus the at_risk derivation from past_due subscriptions and
 * the historical renewal success rate.
 */
class UpcomingPaymentsTest extends DatabaseTestCase
{
    private int $cid;
    private int $oid;

    private const PID = 9501;
    private const VID = 95011;

    public function setUp(): void
    {
        parent::setUp();
        $this->cid = (new CustomerFactory())->create()->id;
        $this->oid = (new OrderFactory())->create(['customer_id' => $this->cid])->id;
    }

    private function sub(array $overrides)
    {
        return (new SubscriptionFactory())->create(array_merge([
            'customer_id'     => $this->cid,
            'parent_order_id' => $this->oid,
            'product_id'      => self::PID,
            'variation_id'    => self::VID,
            'billing_interval' => 'monthly',
            'config'          => ['currency' => 'USD'],
        ], $overrides));
    }

    private function bucketByPeriod(array $schedule): array
    {
        $out = [];
        foreach ($schedule as $b) {
            $out[$b['period']] = $b;
        }
        return $out;
    }

    public function test_n_split_pay_subs_on_one_date_bucket_to_n_times_recurring(): void
    {
        // Three finite split-pay subs, all billing 2026-07-20, $99/mo, remaining bills.
        for ($i = 0; $i < 3; $i++) {
            $this->sub([
                'status'            => 'active',
                'recurring_total'   => Helper::toCent(99),
                'bill_times'        => 8,
                'bill_count'        => 1,
                'next_billing_date' => '2026-07-20 00:00:00',
            ]);
        }

        $res = PaymentScheduleTools::getUpcomingPayments([
            'product_id' => self::PID,
            'currency'   => 'USD',
            'date_from'  => '2026-07-01',
            'date_to'    => '2026-07-31',
            'bucket'     => 'day',
        ]);

        $buckets = $this->bucketByPeriod($res['data']['schedule']);
        $this->assertArrayHasKey('2026-07-20', $buckets, 'the shared bill date has its own day bucket');
        $day = $buckets['2026-07-20'];
        $this->assertSame(3, $day['finite_count']);
        $this->assertSame(0, $day['recurring_count']);
        $this->assertSame(3 * Helper::toCent(99), $day['finite_installments']['amount_cents'], 'N x recurring_total on the date');
        $this->assertSame(3 * Helper::toCent(99), $day['total_expected']['amount_cents']);
    }

    public function test_finite_plan_stops_at_remaining_bills(): void
    {
        // Remaining 3 of 4 installments, $50/mo from 2026-07-10, over a long window.
        $this->sub([
            'status'            => 'active',
            'recurring_total'   => Helper::toCent(50),
            'bill_times'        => 4,
            'bill_count'        => 1,
            'next_billing_date' => '2026-07-10 00:00:00',
        ]);

        $res = PaymentScheduleTools::getUpcomingPayments([
            'product_id' => self::PID,
            'currency'   => 'USD',
            'date_from'  => '2026-07-01',
            'date_to'    => '2027-12-31',
            'bucket'     => 'month',
        ]);

        $schedule = $res['data']['schedule'];
        $finiteBuckets = 0;
        $sumFinite = 0;
        $periods = [];
        foreach ($schedule as $b) {
            if ($b['finite_count'] > 0) {
                $finiteBuckets++;
                $sumFinite += $b['finite_installments']['amount_cents'];
                $periods[] = $b['period'];
            }
        }
        $this->assertSame(3, $finiteBuckets, 'exactly 3 remaining installments — not more, over 18 months');
        $this->assertSame(3 * Helper::toCent(50), $sumFinite);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $periods, 'monthly steps from the anchor');
        $this->assertSame(3 * Helper::toCent(50), $res['data']['totals']['total_expected']['amount_cents']);
    }

    public function test_at_risk_is_the_full_amount_the_overdue_plans_owe(): void
    {
        // Renewal history: 3 succeeded + 1 failed -> success rate 0.75.
        $txn = new OrderTransactionFactory();
        foreach ([Status::TRANSACTION_SUCCEEDED, Status::TRANSACTION_SUCCEEDED, Status::TRANSACTION_SUCCEEDED, Status::TRANSACTION_FAILED] as $st) {
            $txn->create($this->oid, [
                'order_type'       => Status::ORDER_TYPE_RENEWAL,
                'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                'status'           => $st,
            ]);
        }

        // One past_due perpetual sub, $100/mo, billing once in the window.
        $this->sub([
            'status'            => 'past_due',
            'recurring_total'   => Helper::toCent(100),
            'bill_times'        => 0,
            'next_billing_date' => '2026-07-15 00:00:00',
        ]);

        $res = PaymentScheduleTools::getUpcomingPayments([
            'currency'  => 'USD',
            'date_from' => '2026-07-01',
            'date_to'   => '2026-07-31',
            'bucket'    => 'month',
        ]);

        $atRisk = $res['data']['at_risk'];
        $this->assertSame(0.75, $atRisk['historical_success_rate']);
        $this->assertSame(Helper::toCent(100), $atRisk['from_past_due_expected']['amount_cents'], 'one $100 charge in the window');
        $this->assertSame(1, $atRisk['past_due_subscriptions']);
        // The store-wide rate covers healthy renewals too. Applied to plans that already
        // missed a payment it left almost nothing at risk (100 * 0.25 here, near 0 on a real store).
        $this->assertSame(Helper::toCent(100), $atRisk['amount']['amount_cents']);
    }

    public function test_expiring_and_failing_plans_are_projected_and_at_risk_like_past_due(): void
    {
        // The installments report counts all three statuses as overdue
        foreach (['past_due', 'expiring', 'failing'] as $status) {
            $this->sub([
                'status'            => $status,
                'recurring_total'   => Helper::toCent(100),
                'bill_times'        => 0,
                'next_billing_date' => '2026-07-15 00:00:00',
            ]);
        }

        $res = PaymentScheduleTools::getUpcomingPayments([
            'currency'  => 'USD',
            'date_from' => '2026-07-01',
            'date_to'   => '2026-07-31',
            'bucket'    => 'month',
        ]);

        $this->assertSame(3, $res['data']['at_risk']['past_due_subscriptions']);
        $this->assertSame(Helper::toCent(300), $res['data']['at_risk']['from_past_due_expected']['amount_cents']);
        $this->assertSame(Helper::toCent(300), $res['data']['totals']['total_expected']['amount_cents'], 'An overdue plan is still expected to bill');
    }

    public function test_a_billing_schedule_steps_as_the_renewal_engine_does(): void
    {
        // Every 2 weeks on a weekly base slug: Jul 1, 15, 29. The slug alone would bill 5 times.
        $this->sub([
            'status'            => 'active',
            'recurring_total'   => Helper::toCent(10),
            'billing_interval'  => 'weekly',
            'bill_times'        => 0,
            'next_billing_date' => '2026-07-01 00:00:00',
            'config'            => ['currency' => 'USD', 'billing_schedule' => ['period' => 'week', 'interval' => 2]],
        ]);

        $res = PaymentScheduleTools::getUpcomingPayments([
            'product_id' => self::PID,
            'currency'   => 'USD',
            'date_from'  => '2026-07-01T00:00:00Z',
            'date_to'    => '2026-07-31T23:59:59Z',
            'bucket'     => 'day',
        ]);

        $this->assertSame(['2026-07-01', '2026-07-15', '2026-07-29'], array_keys($this->bucketByPeriod($res['data']['schedule'])));
    }
}
