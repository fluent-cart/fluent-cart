<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class StatusHelperTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;
    private ProductFactory $productFactory;
    private SubscriptionFactory $subscriptionFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->productFactory = new ProductFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
    }

    // =========================================================================
    // Spec 117: changeOrderStatus sets completed_at
    // =========================================================================

    public function test_change_order_status_to_completed_sets_completed_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        (new StatusHelper($order))->changeOrderStatus(
            Status::ORDER_COMPLETED, Status::PAYMENT_PAID, 'Offline', 'offline_payment'
        );

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    // =========================================================================
    // Spec 118: changeOrderStatus sets refunded_at for refunded
    // =========================================================================

    public function test_change_order_status_to_refunded_sets_refunded_at(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        (new StatusHelper($order))->changeOrderStatus(
            Status::ORDER_CANCELED, Status::PAYMENT_REFUNDED, 'Offline', 'offline_payment'
        );

        $fresh = Order::query()->find($order->id);
        $this->assertNotNull($fresh->refunded_at);
    }

    // =========================================================================
    // Spec 119: changeOrderStatus dispatches OrderStatusUpdated
    // =========================================================================

    public function test_change_order_status_updates_observable_fields(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        (new StatusHelper($order))->changeOrderStatus(
            Status::ORDER_PROCESSING, Status::PAYMENT_PAID, 'Offline', 'offline_payment'
        );

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_PROCESSING, $fresh->status);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame('offline_payment', $fresh->payment_method);
        $this->assertSame('Offline', $fresh->payment_method_title);
    }

    // =========================================================================
    // Spec 120: updateTotalPaid sets PAID when total_paid >= total_amount
    // =========================================================================

    public function test_update_total_paid_sets_paid_when_fully_covered(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->updateTotalPaid(10000);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame(10000, (int) $fresh->total_paid);
    }

    // =========================================================================
    // Spec 121: updateTotalPaid sets PARTIALLY_PAID when insufficient
    // =========================================================================

    public function test_update_total_paid_sets_partially_paid_when_insufficient(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        (new StatusHelper($order))->updateTotalPaid(3000);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PARTIALLY_PAID, $fresh->payment_status);
        $this->assertSame(3000, (int) $fresh->total_paid);
    }

    // =========================================================================
    // Spec 122: triggerPaymentStatusActions dispatches OrderPaid
    // =========================================================================

    public function test_trigger_payment_status_actions_dispatches_order_paid(): void
    {
        $fired = false;
        add_action('fluent_cart/order_paid', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->triggerPaymentStatusActions($order, Status::PAYMENT_PAID);

        remove_all_actions('fluent_cart/order_paid');
        $this->assertTrue($fired);
    }

    // =========================================================================
    // Spec 123: updateTransactionData updates given transaction
    // =========================================================================

    public function test_update_transaction_data_updates_given_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $txn = $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_PENDING,
        ]);

        (new StatusHelper($order))->updateTransactionData(
            ['status' => Status::TRANSACTION_SUCCEEDED],
            $txn
        );

        $fresh = \FluentCart\App\Models\OrderTransaction::query()->find($txn->id);
        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $fresh->status);
    }

    // =========================================================================
    // Spec 124: updateTransactionData bulk-updates payment transactions when no transaction given
    // =========================================================================

    public function test_update_transaction_data_bulk_updates_when_no_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_PAYMENT,
        ]);
        $a = $this->txnFactory->create($order->id, [
            'status'     => Status::TRANSACTION_PENDING,
            'order_type' => Status::ORDER_TYPE_PAYMENT,
        ]);
        $b = $this->txnFactory->create($order->id, [
            'status'     => Status::TRANSACTION_PENDING,
            'order_type' => Status::ORDER_TYPE_PAYMENT,
        ]);

        (new StatusHelper($order))->updateTransactionData(
            ['status' => Status::TRANSACTION_FAILED]
        );

        $freshA = \FluentCart\App\Models\OrderTransaction::query()->find($a->id);
        $freshB = \FluentCart\App\Models\OrderTransaction::query()->find($b->id);
        $this->assertSame(Status::TRANSACTION_FAILED, $freshA->status);
        $this->assertSame(Status::TRANSACTION_FAILED, $freshB->status);
    }

    // =========================================================================
    // Spec 125-127: syncOrderStatuses sums and sets payment_status
    // =========================================================================

    public function test_sync_order_statuses_sets_paid_when_charges_cover_total(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
        $this->assertSame(10000, (int) $fresh->total_paid);
    }

    public function test_sync_order_statuses_sets_partially_refunded_when_refunds_present(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 5000,
            'payment_status'   => Status::PAYMENT_PARTIALLY_PAID,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 2000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PARTIALLY_REFUNDED, $fresh->payment_status);
        $this->assertSame(2000, (int) $fresh->total_refund);
    }

    // =========================================================================
    // Spec 129: syncOrderStatuses sets order_status=PROCESSING when PAID
    // =========================================================================

    public function test_sync_order_statuses_transitions_physical_to_processing_not_completed(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'physical',
            'completed_at'     => null,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_PROCESSING, $fresh->status, 'physical orders must stop at processing, not auto-complete');
        $this->assertContains(
            (string) $fresh->completed_at,
            ['', '0000-00-00 00:00:00'],
            'physical orders must not set completed_at on paid transition; got: ' . var_export($fresh->completed_at, true)
        );
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
    }

    // =========================================================================
    // Spec 130: atomic guard prevents duplicate OrderPaid dispatch
    // =========================================================================

    public function test_sync_order_statuses_guards_duplicate_paid_transition(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 10000,
            'payment_status'   => Status::PAYMENT_PAID,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        $count = 0;
        add_action('fluent_cart/order_paid', function () use (&$count) { $count++; });

        (new StatusHelper($order))->syncOrderStatuses();
        (new StatusHelper($order))->syncOrderStatuses();

        remove_all_actions('fluent_cart/order_paid');
        $this->assertSame(0, $count, 'Already-paid order should not re-dispatch OrderPaid');
    }

    // =========================================================================
    // Spec 131: auto-complete digital orders
    // =========================================================================

    public function test_sync_order_statuses_auto_completes_digital_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'fulfillment_type' => 'digital',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_COMPLETED, $fresh->status);
    }

    // =========================================================================
    // Spec 132-135: cart completion + on_success actions + OrderPaid dispatch
    // =========================================================================

    public function test_sync_order_statuses_marks_related_cart_completed(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        Cart::query()->create([
            'order_id'  => $order->id,
            'stage'     => 'pending',
            'cart_hash' => 'cart-sync-hash',
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $cart = Cart::query()->where('order_id', $order->id)->first();
        $this->assertSame('completed', $cart->stage);
    }

    public function test_sync_order_statuses_fires_cart_completed_action(): void
    {
        $fired = false;
        add_action('fluent_cart/cart_completed', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        Cart::query()->create([
            'order_id'  => $order->id,
            'stage'     => 'pending',
            'cart_hash' => 'cart-completed-hook',
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        remove_all_actions('fluent_cart/cart_completed');
        $this->assertTrue($fired);
    }

    public function test_sync_order_statuses_dispatches_order_paid_for_non_renewal(): void
    {
        $fired = false;
        add_action('fluent_cart/order_paid', function () use (&$fired) { $fired = true; });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'status'           => Status::ORDER_ON_HOLD,
            'type'             => Status::ORDER_TYPE_PAYMENT,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        remove_all_actions('fluent_cart/order_paid');
        $this->assertTrue($fired);
    }

    public function test_sync_order_statuses_executes_on_success_actions(): void
    {
        $customActionFired = false;
        add_action('test_on_success_action_handle', function () use (&$customActionFired) {
            $customActionFired = true;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 10000,
            'total_paid'       => 0,
            'payment_status'   => Status::PAYMENT_PENDING,
            'status'           => Status::ORDER_ON_HOLD,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        Cart::query()->create([
            'order_id'      => $order->id,
            'stage'         => 'pending',
            'cart_hash'     => 'on-success-hash',
            'checkout_data' => [
                '__on_success_actions__' => ['test_on_success_action_handle'],
            ],
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        remove_all_actions('test_on_success_action_handle');
        $this->assertTrue($customActionFired);
    }

    // =========================================================================
    // Spec 128: partially refunded applies when refunds without full paid
    // =========================================================================

    public function test_sync_order_statuses_sets_paid_when_fully_paid_after_partial_refund(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'      => $customer->id,
            'total_amount'     => 6000,
            'total_paid'       => 10000,
            'payment_status'   => Status::PAYMENT_PAID,
            'status'           => Status::ORDER_PROCESSING,
            'fulfillment_type' => 'physical',
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 10000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 4000,
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::PAYMENT_PAID, $fresh->payment_status);
    }

    // =========================================================================
    // Regression: initial manual-subscription activation must anchor
    // next_billing_date on when the order was actually paid, not on when the
    // (possibly long-pending) order row was created.
    // =========================================================================

    public function test_manual_subscription_activation_anchors_next_billing_on_paid_date_not_order_created_at(): void
    {
        $customer = $this->customerFactory->create();
        $product  = $this->productFactory->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();

        // Order placed ~229 days ago (e.g. 2026-01-08), left pending until now.
        $backdated = gmdate('Y-m-d H:i:s', time() - (229 * DAY_IN_SECONDS));
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => Status::ORDER_TYPE_SUBSCRIPTION,
            'status'         => Status::ORDER_ON_HOLD,
            'payment_status' => Status::PAYMENT_PENDING,
            'total_amount'   => 10000,
            'total_paid'     => 0,
            'created_at'     => $backdated,
        ]);

        $sub = $this->subscriptionFactory->create([
            'customer_id'        => $customer->id,
            'parent_order_id'    => $order->id,
            'product_id'         => $product->ID,
            'variation_id'       => $variant->id,
            'billing_interval'   => 'yearly',
            'trial_days'         => 0,
            'status'             => Status::SUBSCRIPTION_PENDING,
            'collection_method'  => 'manual',
        ]);

        // Money actually moves now, via Stripe, settled "now".
        $now = gmdate('Y-m-d H:i:s');
        $this->txnFactory->create($order->id, [
            'payment_method' => 'stripe',
            'status'         => Status::TRANSACTION_SUCCEEDED,
            'total'          => 10000,
            'meta'           => ['settled_at' => $now],
        ]);

        (new StatusHelper($order))->syncOrderStatuses();

        $freshSub = Subscription::query()->find($sub->id);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $freshSub->status);

        $nextBilling = strtotime($freshSub->next_billing_date);
        $expected    = strtotime($now) + (365 * DAY_IN_SECONDS);

        $this->assertEqualsWithDelta(
            $expected,
            $nextBilling,
            60,
            'next_billing_date must be anchored on when the order was actually paid (settled_at), not on the order row created_at from 229 days earlier'
        );
    }
}
