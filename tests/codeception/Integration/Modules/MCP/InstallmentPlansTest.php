<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\ConfiguresStoreOrderMode;
use Tests\Support\Traits\ReadsMcpRows;

/**
 * Installment / split-pay plans: detection and derived contract fields
 * (bill_times > 0 = finite term; bill_times = 0 = open-ended recurring), plus
 * the expiration cron's handling of lapsed installments.
 *
 * Lifecycle contract since a3029513c ("revert mcp-branch cron changes"):
 * checkAndExpireSubscriptions expires EVERY lapsed billing-status
 * subscription uniformly — installment plans included. The earlier
 * installment-aware resolution (completed / past_due instead of expired)
 * was deliberately reverted; these tests pin the current uniform behavior.
 */
class InstallmentPlansTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use ConfiguresStoreOrderMode;
    use ReadsMcpRows;

    private int $customerId;
    private int $orderId;

    public function setUp(): void
    {
        parent::setUp();
        $this->setStoreOrderMode(Status::ORDER_MODE_LIVE);

        // checkAndExpireSubscriptions and the MCP status aggregate scan the
        // whole fct_subscriptions table, so stray committed rows from other
        // suites would join the cron run and shift the aggregate buckets.
        // Clear the table inside this test's transaction before seeding.
        Subscription::query()->delete();

        $this->customerId = (new CustomerFactory())->create()->id;
        $this->orderId    = (new OrderFactory())->create([
            'customer_id'    => $this->customerId,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'payment_status' => Status::PAYMENT_PAID,
        ])->id;
    }

    // ---------------------------------------------------------------------
    // Detection + total_contract_value for a known split-pay plan
    // ---------------------------------------------------------------------

    public function test_is_installment_and_contract_value_are_correct(): void
    {
        // "5 Sites Lifetime (Split)": $119/mo x 7 = $833 committed.
        $sub = $this->buildInstallmentSubscription($this->customerId, $this->orderId, 7, 2, 119);

        $this->assertTrue($sub->isInstallment(), 'bill_times > 0 marks a finite installment plan');
        $this->assertSame(Helper::toCent(833), $sub->totalContractValue(), '119 x 7 committed');
        $this->assertSame(5, $sub->installmentsRemaining(), '7 scheduled minus 2 collected');
        $this->assertFalse($sub->hasReachedTermEnd(), '2 of 7 collected is not end of term');

        // Exposed on the MCP row in the {amount, amount_cents, display} shape.
        $res = SubscriptionTools::listSubscriptions(['customer_id' => $this->customerId, 'plan_type' => 'installment']);
        $row = $this->subscriptionRowFor($res, $sub->id);
        $this->assertNotNull($row, 'installment plan_type filter returns the split-pay plan');
        $this->assertTrue($row['is_installment'], 'row carries the installment flag');
        $this->assertSame(2, $row['installments_paid'], 'row reports collected installments');
        $this->assertSame(5, $row['installments_remaining'], 'row reports owed installments');
        $this->assertSame(Helper::toCent(833), $row['total_contract_value']['amount_cents'], 'row carries the committed total in cents');
    }

    public function test_open_ended_plan_is_not_an_installment(): void
    {
        $sub = $this->buildInstallmentSubscription($this->customerId, $this->orderId, 0, 0, 49);

        $this->assertFalse($sub->isInstallment(), 'bill_times = 0 is open-ended');
        $this->assertSame(0, $sub->totalContractValue(), 'open-ended plan has no committed total');

        // plan_type=recurring matches it; plan_type=installment does not.
        $recurring = SubscriptionTools::listSubscriptions(['customer_id' => $this->customerId, 'plan_type' => 'recurring']);
        $this->assertNotNull($this->subscriptionRowFor($recurring, $sub->id), 'recurring plan_type filter returns it');
        $installments = SubscriptionTools::listSubscriptions(['customer_id' => $this->customerId, 'plan_type' => 'installment']);
        $this->assertNull($this->subscriptionRowFor($installments, $sub->id), 'installment plan_type filter excludes it');

        $row = $this->subscriptionRowFor($recurring, $sub->id);
        $this->assertFalse($row['is_installment'], 'row is not flagged installment');
        $this->assertNull($row['total_contract_value'], 'open-ended plan has no fixed contract value');
        $this->assertNull($row['installments_paid'], 'an open-ended plan has no installments');
        $this->assertNull($row['installments_remaining'], 'nor installments left to pay');
    }

    // ---------------------------------------------------------------------
    // Expiration cron: lapsed installments expire like any other lapse
    // (uniform contract since a3029513c)
    // ---------------------------------------------------------------------

    public function test_fully_paid_installment_expires_on_lapse(): void
    {
        $sub = $this->buildInstallmentSubscription($this->customerId, $this->orderId, 3, 3, 100, [
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            // 60 days ago — safely beyond the 7-day monthly grace window.
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - (60 * DAY_IN_SECONDS)),
        ]);
        $this->recordSucceededCharges($this->orderId, $sub->id, 3, 100);

        $stats = Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, $fresh->status, 'a lapsed installment expires like any other lapse');
        $this->assertNull($fresh->next_billing_date, 'expiration clears next billing so the row is not re-processed');
        $this->assertContains($sub->id, $stats['expired_ids'], 'the run reports the expired subscription id');
        $this->assertArrayNotHasKey('installment_completed', $stats, 'no installment-aware resolution since the a3029513c revert');

        // The MCP status aggregate reflects the same terminal state.
        $res = ReportTools::querySubscriptions([
            'start_date' => '2000-01-01',
            'end_date'   => '2035-12-31',
            'metrics'    => ['subscription_count'],
            'dimensions' => ['status'],
        ]);
        $byStatus = [];
        foreach ($res['data']['rows'] as $row) {
            $byStatus[$row['status']] = $row['subscription_count'];
        }
        $this->assertSame(1, $byStatus[Status::SUBSCRIPTION_EXPIRED] ?? 0, 'aggregate counts the plan as expired');
        $this->assertArrayNotHasKey(Status::SUBSCRIPTION_COMPLETED, $byStatus, 'nothing is reported completed');
    }

    public function test_unfinished_installment_expires_on_lapse(): void
    {
        // 100-site split pay, 9 of 10 paid, final charge overdue:
        // calculateBillCount() == 9 < 10, but the cron does not special-case it.
        $sub = $this->buildInstallmentSubscription($this->customerId, $this->orderId, 10, 9, 119, [
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - (60 * DAY_IN_SECONDS)),
        ]);
        $this->recordSucceededCharges($this->orderId, $sub->id, 9, 119);

        $stats = Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, $fresh->status, 'an unfinished lapsed installment also expires');
        $this->assertNull($fresh->next_billing_date, 'expiration clears next billing');
        $this->assertContains($sub->id, $stats['expired_ids'], 'the run reports the expired subscription id');
        $this->assertArrayNotHasKey('installment_past_due', $stats, 'no installment-aware resolution since the a3029513c revert');
    }

    public function test_open_ended_subscription_still_expires_on_lapse(): void
    {
        $sub = $this->buildInstallmentSubscription($this->customerId, $this->orderId, 0, 0, 49, [
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() - (60 * DAY_IN_SECONDS)),
        ]);

        $stats = Subscription::checkAndExpireSubscriptions();

        $fresh = Subscription::find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_EXPIRED, $fresh->status, 'open-ended lapse still expires');
        $this->assertContains($sub->id, $stats['expired_ids'], 'the run reports the expired subscription id');
    }
}
