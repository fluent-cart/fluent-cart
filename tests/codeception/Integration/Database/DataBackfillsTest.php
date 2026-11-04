<?php

namespace Tests\Integration\Database;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\Database\DataBackfills;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * DataBackfills — the one-shot repair queue for released data bugs.
 *
 * completed_next_billing_date: released versions stamped next_billing_date
 * with the completion time on completed subscriptions (the pre-guard EOT
 * flow through cancelRemoteSubscription); the backfill clears it so upgraded
 * installs stop reporting a future billing date on paid-in-full subscriptions.
 */
class DataBackfillsTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory     = new CustomerFactory();
        $this->orderFactory        = new OrderFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
    }

    public function test_completed_next_billing_date_backfill_clears_only_stale_completed_rows(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        // The released-version corruption: the EOT flow stamped the COMPLETION
        // time, so real rows awaiting repair carry a PAST timestamp.
        $corrupted = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'bill_times'        => 3,
            'bill_count'        => 3,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);
        $this->assertSame('2025-12-01 09:30:00', $corrupted->next_billing_date, 'precondition: the corrupted row must start with a stale billing date');
        $this->assertLessThan(gmdate('Y-m-d H:i:s'), $corrupted->next_billing_date, 'precondition: the fixture must mirror the real defect — a completion timestamp in the past');

        // Invariant case: a completed subscription never bills again, so ANY
        // non-null date must go — including a future one.
        $corruptedFuture = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'next_billing_date' => '2036-08-22 10:00:00',
        ]);

        // Control: an active subscription's schedule must survive the sweep.
        $active = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => '2026-08-22 10:00:00',
        ]);

        $this->assertContains('completed_next_billing_date', DataBackfills::getPending(), 'precondition: the backfill must be registered and pending');

        $result = DataBackfills::processPending();

        $this->assertSame('completed', $result['status'], 'the backfill queue should finish within one test-sized run');
        $this->assertNull(Subscription::find($corrupted->id)->next_billing_date, 'the historical completion-time date on the completed subscription must be cleared in the database');
        $this->assertNull(Subscription::find($corruptedFuture->id)->next_billing_date, 'every non-null date on a completed subscription must be cleared, future ones included');
        $this->assertSame('2026-08-22 10:00:00', Subscription::find($active->id)->next_billing_date, 'the active subscription must keep its scheduled billing date');
        $this->assertNotContains('completed_next_billing_date', DataBackfills::getPending(), 'the backfill must be marked completed after its final chunk');
    }

    public function test_completed_next_billing_date_backfill_resumes_across_budgeted_runs(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        $corruptedIds = [];
        for ($i = 0; $i < 3; $i++) {
            $corruptedIds[] = $this->subscriptionFactory->create([
                'customer_id'       => $customer->id,
                'parent_order_id'   => $order->id,
                'product_id'        => 1,
                'variation_id'      => 1,
                'status'            => Status::SUBSCRIPTION_COMPLETED,
                'next_billing_date' => '2025-12-01 09:30:00',
            ])->id;
        }
        $sentinelId = $corruptedIds[2];

        // One row per request: the queue must stop at the budget boundary and
        // resume from the cursor — marking the slug completed early would
        // permanently strand every row after the first chunk.
        add_filter('fluent_cart/data_backfills/chunk_budget', function () {
            return ['chunk_size' => 1, 'max_chunks_per_run' => 1];
        });

        $first = DataBackfills::processPending();

        $this->assertSame('running', $first['status'], 'a spent budget with rows remaining must report running, not completed');
        $this->assertContains('completed_next_billing_date', $first['pending'], 'the slug must stay pending while rows remain');
        $this->assertGreaterThan(0, (int)fluent_cart_get_option('_fluent_cart_completed_billing_date_cursor', 0, false), 'the keyset cursor must be persisted at the budget boundary');
        $this->assertSame('2025-12-01 09:30:00', Subscription::find($sentinelId)->next_billing_date, 'rows beyond the spent budget must remain untouched until a later run');

        $runs = 1;
        while ($runs < 10 && DataBackfills::getPending()) {
            DataBackfills::processPending();
            $runs++;
        }

        foreach ($corruptedIds as $id) {
            $this->assertNull(Subscription::find($id)->next_billing_date, 'every corrupted row must be cleared once the resumed runs finish');
        }
        $this->assertNotContains('completed_next_billing_date', DataBackfills::getPending(), 'the slug must be marked completed only after the final chunk');
        $this->assertSame(0, (int)fluent_cart_get_option('_fluent_cart_completed_billing_date_cursor', 0, false), 'the cursor must be removed after completion');
    }

    public function test_row_reactivated_between_selection_and_update_keeps_its_new_schedule(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        $corrupted = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);
        $reactivated = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);

        // Both rows are selected; this production-invoked seam then simulates a
        // reactivation landing between the SELECT and the UPDATE. The UPDATE's
        // status re-check is the only thing protecting the new schedule.
        add_action('fluent_cart/data_backfills/chunk_selected', function ($payload) use ($reactivated) {
            if (in_array($reactivated->id, $payload['ids'], true)) {
                Subscription::query()->where('id', $reactivated->id)->update([
                    'status'            => Status::SUBSCRIPTION_ACTIVE,
                    'next_billing_date' => '2026-09-01 00:00:00',
                ]);
            }
        });

        DataBackfills::processPending();

        $this->assertNull(Subscription::find($corrupted->id)->next_billing_date, 'the still-completed selected row must be cleared');
        $freshReactivated = Subscription::find($reactivated->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $freshReactivated->status, 'the reactivated row must keep its new status');
        $this->assertSame('2026-09-01 00:00:00', $freshReactivated->next_billing_date, 'a row reactivated after selection must keep its new schedule — the UPDATE must re-check status');

        $report = (array)fluent_cart_get_option('_fluent_cart_completed_billing_date_report', [], false);
        $this->assertContains($corrupted->id, (array)($report['cleared_ids'] ?? []), 'the actually-cleared row must appear in the report');
        $this->assertNotContains($reactivated->id, (array)($report['cleared_ids'] ?? []), 'a row the guard skipped must not be reported as cleared');
    }

    public function test_completed_next_billing_date_backfill_rerun_is_a_no_op(): void
    {
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->create(['customer_id' => $customer->id, 'type' => 'subscription']);

        $corrupted = $this->subscriptionFactory->create([
            'customer_id'       => $customer->id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'status'            => Status::SUBSCRIPTION_COMPLETED,
            'next_billing_date' => '2025-12-01 09:30:00',
        ]);

        DataBackfills::processPending();
        $result = DataBackfills::processPending();

        $this->assertSame('completed', $result['status'], 'a second run must report completed, not restart the queue');
        $this->assertNull(Subscription::find($corrupted->id)->next_billing_date, 'the cleared row must stay cleared across re-runs');
    }
}
