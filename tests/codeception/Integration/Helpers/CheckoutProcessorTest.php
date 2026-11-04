<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;

class CheckoutProcessorTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private ProductFactory $productFactory;
    private CartFactory $cartFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->productFactory = new ProductFactory();
        $this->cartFactory = new CartFactory();
        $this->orderFactory = new OrderFactory();
    }

    /**
     * Build a standard onetime cart item array for the processor.
     */
    private function makeProcessorItem(array $overrides = []): array
    {
        return array_merge([
            'object_id'        => 1,
            'post_id'          => 1,
            'quantity'         => 1,
            'unit_price'       => 5000,
            'price'            => 5000,
            'subtotal'         => 5000,
            'line_total'       => 5000,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'manual_discount'  => 0,
            'tax_amount'       => 0,
            'shipping_charge'  => 0,
            'cost'             => 0,
            'post_title'       => 'Test Product',
            'product_title'    => 'Test Product',
            'title'            => 'Default',
            'variation_title'  => 'Default',
            'fulfillment_type' => 'digital',
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);
    }

    /**
     * Build a subscription cart item for the processor.
     */
    private function makeSubscriptionProcessorItem(array $overrides = []): array
    {
        return $this->makeProcessorItem(array_merge([
            'other_info' => [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 0,
                'recurring_tax'   => 0,
            ],
        ], $overrides));
    }

    // =========================================================================
    // Spec 118-120: prepareOrderData via createDraftOrder
    // =========================================================================

    public function test_draft_order_has_on_hold_status_and_pending_payment(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(Status::ORDER_ON_HOLD, $order->status);
        $this->assertSame(Status::PAYMENT_PENDING, $order->payment_status);
    }

    public function test_draft_order_type_is_subscription_when_subscription_item(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertSame(Status::ORDER_TYPE_SUBSCRIPTION, $order->type);
    }

    public function test_draft_order_type_is_payment_for_onetime_items(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertSame(Status::ORDER_TYPE_PAYMENT, $order->type);
    }

    public function test_draft_order_fulfillment_type_physical_when_physical_item(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem(['fulfillment_type' => 'physical'])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertSame('physical', $order->fulfillment_type);
    }

    // =========================================================================
    // Spec 121-123: Order totals calculation
    // =========================================================================

    public function test_draft_order_subtotal_is_sum_of_item_subtotals(): void
    {
        $customer = $this->customerFactory->create();
        $items = [
            $this->makeProcessorItem(['unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]),
            $this->makeProcessorItem(['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000, 'object_id' => 2, 'post_id' => 2]),
        ];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertEquals(5000, $order->subtotal);
        $this->assertEquals(5000, $order->total_amount);
    }

    public function test_draft_order_total_includes_fees_and_shipping(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'shipping_charge' => 1000,
            'fees'           => [['key' => 'proc', 'label' => 'Processing', 'amount' => 500]],
        ]);
        $order = $processor->createDraftOrder();

        $this->assertEquals(1000, $order->shipping_total);
        $this->assertEquals(500, $order->fee_total);
        // total = subtotal + shipping + fees = 5000 + 1000 + 500 = 6500
        $this->assertEquals(6500, $order->total_amount);
    }

    public function test_draft_order_total_clamps_to_zero(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem([
            'unit_price'      => 100,
            'subtotal'        => 100,
            'coupon_discount' => 99999,
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertGreaterThanOrEqual(0, $order->total_amount);
    }

    // =========================================================================
    // Spec 128: createDraftOrder requires customer_id
    // =========================================================================

    public function test_create_draft_order_requires_customer_id(): void
    {
        $items = [$this->makeProcessorItem()];
        $processor = new CheckoutProcessor($items, []);
        $result = $processor->createDraftOrder();

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 129: createDraftOrder creates Order record
    // =========================================================================

    public function test_create_draft_order_creates_order(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertNotEmpty($order->id);
        $this->assertEquals($customer->id, $order->customer_id);
    }

    // =========================================================================
    // Spec 130-131: createDraftOrder creates OrderItems
    // =========================================================================

    public function test_create_draft_order_creates_order_items(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $items = [$this->makeProcessorItem([
            'object_id'  => $variation->id,
            'post_id'    => $product->ID,
            'unit_price' => 3000,
            'subtotal'   => 3000,
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $orderItems = OrderItem::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $orderItems);
        $this->assertEquals($variation->id, $orderItems->first()->object_id);
        $this->assertEquals(3000, $orderItems->first()->line_total);
    }

    // =========================================================================
    // Spec 132: Creates Subscription for subscription items
    // =========================================================================

    public function test_create_draft_order_creates_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price' => 10000,
            'subtotal'   => 10000,
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();
        $subscription = $processor->getSubscription();

        $this->assertNotNull($subscription);
        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertEquals($order->id, $subscription->parent_order_id);
        $this->assertEquals($customer->id, $subscription->customer_id);
    }

    // =========================================================================
    // Spec 133: Creates OrderTransaction
    // =========================================================================

    public function test_create_draft_order_creates_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();
        $transaction = $processor->getTransaction();

        $this->assertNotNull($transaction);
        $this->assertInstanceOf(OrderTransaction::class, $transaction);
        $this->assertEquals($order->id, $transaction->order_id);
        $this->assertSame(Status::TRANSACTION_TYPE_CHARGE, $transaction->transaction_type);
        $this->assertSame(Status::PAYMENT_PENDING, $transaction->status);
        $this->assertEquals($order->total_amount, $transaction->total);
    }

    // =========================================================================
    // Spec 134: Applied coupons and use_count increment
    // =========================================================================

    public function test_create_draft_order_inserts_applied_coupons(): void
    {
        $customer = $this->customerFactory->create();
        $coupon = Coupon::query()->create([
            'title' => 'Test', 'code' => 'ORDERCOUP', 'status' => 'active',
            'type' => 'fixed', 'amount' => 500, 'stackable' => 1,
            'priority' => 1, 'use_count' => 0, 'conditions' => [],
        ]);

        $items = [$this->makeProcessorItem([
            'unit_price'      => 5000,
            'subtotal'        => 5000,
            'coupon_discount' => 500,
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'applied_coupons' => [
                'ORDERCOUP' => ['id' => $coupon->id, 'code' => 'ORDERCOUP', 'discount' => 500],
            ],
        ]);
        $order = $processor->createDraftOrder();

        $applied = AppliedCoupon::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $applied);

        $coupon = $coupon->fresh();
        $this->assertSame(1, (int) $coupon->use_count);
    }

    // =========================================================================
    // Spec 135: Links Cart to Order
    // =========================================================================

    public function test_create_draft_order_links_cart(): void
    {
        $customer = $this->customerFactory->create();
        $cart = $this->cartFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'cart_hash'      => $cart->cart_hash,
        ]);
        $order = $processor->createDraftOrder();

        $reloadedCart = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertEquals($order->id, $reloadedCart->order_id);
        $this->assertSame('intended', $reloadedCart->stage);
        $this->assertEquals($customer->id, $reloadedCart->customer_id);
    }

    // =========================================================================
    // Spec 117: Fee order items
    // =========================================================================

    public function test_create_draft_order_creates_fee_order_items(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem(['unit_price' => 3000, 'subtotal' => 3000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'fees'           => [
                ['key' => 'processing', 'label' => 'Processing Fee', 'amount' => 500],
            ],
        ]);
        $order = $processor->createDraftOrder();

        $feeItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('payment_type', 'fee')
            ->get();

        $this->assertCount(1, $feeItems);
        $feeItem = $feeItems->first();
        $this->assertEquals(500, $feeItem->unit_price);
        $this->assertSame(1, (int) $feeItem->quantity);
        $this->assertSame('digital', $feeItem->fulfillment_type);
    }

    // =========================================================================
    // Spec 125-126: Subscription data — no subscription / multiple subscriptions
    // =========================================================================

    public function test_no_subscription_created_for_onetime_items(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $order = $processor->createDraftOrder();

        $this->assertNull($processor->getSubscription());
        $subscriptions = Subscription::query()->where('parent_order_id', $order->id)->get();
        $this->assertCount(0, $subscriptions);
    }

    // =========================================================================
    // Spec 141-142: getAdjustedOrder — update existing order
    // =========================================================================

    public function test_create_draft_order_with_prev_order_updates_existing(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'total_amount' => 3000,
        ]);

        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'new_method',
        ]);
        $updatedOrder = $processor->createDraftOrder($existingOrder);

        $this->assertInstanceOf(Order::class, $updatedOrder);
        $this->assertEquals($existingOrder->id, $updatedOrder->id);
        // Total should be recalculated
        $this->assertEquals(5000, $updatedOrder->total_amount);
    }

    public function test_adjusted_order_for_locked_cart_only_updates_limited_fields(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 3000,
            'subtotal'       => 3000,
            'payment_method' => 'old_method',
        ]);

        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'new_method',
            'is_locked'      => true,
        ]);
        $updatedOrder = $processor->createDraftOrder($existingOrder);

        $this->assertInstanceOf(Order::class, $updatedOrder);
        $this->assertSame('new_method', $updatedOrder->payment_method);
    }

    // =========================================================================
    // getAdjustedOrder — re-submit keeps the pending transaction's gateway state
    // =========================================================================

    private function makePendingTransaction(Order $order, array $meta): OrderTransaction
    {
        return OrderTransaction::query()->create([
            'order_id'         => $order->id,
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'payment_method'   => $order->payment_method,
            'payment_mode'     => 'test',
            'status'           => Status::PAYMENT_PENDING,
            'currency'         => 'USD',
            'total'            => 3000,
            'meta'             => $meta,
        ]);
    }

    public function test_resubmit_with_same_gateway_keeps_prepared_gateway_meta(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 3000,
            'payment_method' => 'paddle',
        ]);
        $transaction = $this->makePendingTransaction($existingOrder, [
            'paddle_expected_transaction_id' => 'txn_prepared_earlier',
            'payment_attempt'                => 2,
        ]);

        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];
        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'paddle',
        ]);
        $processor->createDraftOrder($existingOrder);

        $resubmitted = $processor->getTransaction();
        $this->assertSame($transaction->id, $resubmitted->id);
        $this->assertSame('txn_prepared_earlier', Arr::get($resubmitted->meta, 'paddle_expected_transaction_id'));
        $this->assertSame(2, Arr::get($resubmitted->meta, 'payment_attempt'), 'A pending re-submit is a duplicate, not a retry.');
    }

    public function test_resubmit_with_another_gateway_keeps_gateway_meta(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 3000,
            'payment_method' => 'paddle',
        ]);
        $this->makePendingTransaction($existingOrder, [
            'paddle_expected_transaction_id' => 'txn_prepared_earlier',
            'payment_attempt'                => 2,
        ]);

        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];
        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'stripe',
        ]);
        $processor->createDraftOrder($existingOrder);

        $resubmitted = $processor->getTransaction();
        $this->assertSame('stripe', $resubmitted->payment_method);
        // Gateway meta keys are gateway-specific, so they stay if the buyer comes back to Paddle.
        $this->assertSame('txn_prepared_earlier', Arr::get($resubmitted->meta, 'paddle_expected_transaction_id'));
        $this->assertSame(2, Arr::get($resubmitted->meta, 'payment_attempt'));
    }

    public function test_resubmit_after_failure_bumps_attempt_and_keeps_gateway_meta(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 3000,
            'payment_method' => 'paddle',
        ]);
        $transaction = $this->makePendingTransaction($existingOrder, [
            'paddle_expected_transaction_id' => 'txn_prepared_earlier',
        ]);
        $transaction->update(['status' => Status::PAYMENT_FAILED]);

        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];
        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'paddle',
        ]);
        $processor->createDraftOrder($existingOrder);

        $resubmitted = $processor->getTransaction();
        $this->assertSame(1, Arr::get($resubmitted->meta, 'payment_attempt'));
        $this->assertSame('txn_prepared_earlier', Arr::get($resubmitted->meta, 'paddle_expected_transaction_id'));
    }

    // =========================================================================
    // Spec 143: Non-locked adjusted order replaces items
    // =========================================================================

    public function test_adjusted_order_replaces_items_for_non_locked(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id' => $customer->id,
        ]);

        // Create existing order item
        OrderItem::query()->create([
            'order_id'         => $existingOrder->id,
            'post_title'       => 'Old Item',
            'title'            => 'Old',
            'quantity'         => 1,
            'unit_price'       => 1000,
            'subtotal'         => 1000,
            'line_total'       => 1000,
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'digital',
        ]);

        $newItems = [$this->makeProcessorItem([
            'unit_price' => 7000,
            'subtotal'   => 7000,
        ])];

        $processor = new CheckoutProcessor($newItems, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $updatedOrder = $processor->createDraftOrder($existingOrder);

        $orderItems = OrderItem::query()->where('order_id', $updatedOrder->id)->get();
        // Old items (1000 each) should be deleted, only new item (7000) remains
        $this->assertCount(1, $orderItems);
        $this->assertEquals(7000, $orderItems->first()->unit_price);
    }

    // =========================================================================
    // Spec 145: Locked adjusted order preserves applied coupons
    // =========================================================================

    public function test_adjusted_order_locked_preserves_coupons(): void
    {
        $customer = $this->customerFactory->create();
        $coupon = Coupon::query()->create([
            'title' => 'Test', 'code' => 'KEEP', 'status' => 'active',
            'type' => 'fixed', 'amount' => 200, 'stackable' => 1,
            'priority' => 1, 'use_count' => 1, 'conditions' => [],
        ]);

        $existingOrder = $this->orderFactory->create([
            'customer_id' => $customer->id,
        ]);

        AppliedCoupon::query()->create([
            'order_id'    => $existingOrder->id,
            'coupon_id'   => $coupon->id,
            'code'        => 'KEEP',
            'amount'      => 200,
            'customer_id' => $customer->id,
        ]);

        $items = [$this->makeProcessorItem()];
        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'is_locked'      => true,
        ]);
        $processor->createDraftOrder($existingOrder);

        // Coupon should still be there, use_count should not increase
        $applied = AppliedCoupon::query()->where('order_id', $existingOrder->id)->get();
        $this->assertCount(1, $applied);

        $freshCoupon = Coupon::query()->find($coupon->id);
        $this->assertSame(1, (int) $freshCoupon->use_count); // unchanged
    }

    // =========================================================================
    // Spec 146-148: Accessors
    // =========================================================================

    public function test_get_order_returns_created_order(): void
    {
        $customer = $this->customerFactory->create();
        $processor = new CheckoutProcessor(
            [$this->makeProcessorItem()],
            ['customer_id' => $customer->id, 'payment_method' => 'offline_payment']
        );
        $order = $processor->createDraftOrder();

        $this->assertSame($order->id, $processor->getOrder()->id);
    }

    public function test_get_transaction_returns_created_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $processor = new CheckoutProcessor(
            [$this->makeProcessorItem()],
            ['customer_id' => $customer->id, 'payment_method' => 'offline_payment']
        );
        $processor->createDraftOrder();

        $txn = $processor->getTransaction();
        $this->assertInstanceOf(OrderTransaction::class, $txn);
        $this->assertSame((int) $processor->getOrder()->id, (int) $txn->order_id, 'the transaction belongs to the draft order');
    }

    public function test_get_subscription_returns_null_for_onetime(): void
    {
        $customer = $this->customerFactory->create();
        $processor = new CheckoutProcessor(
            [$this->makeProcessorItem()],
            ['customer_id' => $customer->id, 'payment_method' => 'offline_payment']
        );
        $processor->createDraftOrder();

        $this->assertNull($processor->getSubscription());
    }

    public function test_get_subscription_returns_subscription_model(): void
    {
        $customer = $this->customerFactory->create();
        $processor = new CheckoutProcessor(
            [$this->makeSubscriptionProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])],
            ['customer_id' => $customer->id, 'payment_method' => 'offline_payment']
        );
        $processor->createDraftOrder();

        $sub = $processor->getSubscription();
        $this->assertInstanceOf(Subscription::class, $sub);
        $this->assertSame(5000, (int) $sub->recurring_amount, 'the subscription carries the item price in cents');
    }

    // =========================================================================
    // Spec 192: Edge — Fee items are digital with quantity=1
    // =========================================================================

    public function test_fee_order_items_are_digital_with_quantity_one(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem()];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'fees'           => [
                ['key' => 'surcharge', 'label' => 'Surcharge', 'amount' => 300],
            ],
        ]);
        $order = $processor->createDraftOrder();

        $feeItem = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('payment_type', 'fee')
            ->first();

        $this->assertNotNull($feeItem);
        $this->assertSame('digital', $feeItem->fulfillment_type);
        $this->assertSame(1, (int) $feeItem->quantity);
    }

    // =========================================================================
    // Spec 195: Edge — adjusted order deletes orphaned items
    // =========================================================================

    public function test_adjusted_order_deletes_orphaned_items(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id' => $customer->id,
        ]);

        // Create 3 existing items
        for ($i = 0; $i < 3; $i++) {
            OrderItem::query()->create([
                'order_id'         => $existingOrder->id,
                'post_title'       => 'Old Item ' . $i,
                'title'            => 'Variant ' . $i,
                'quantity'         => 1,
                'unit_price'       => 1000,
                'subtotal'         => 1000,
                'line_total'       => 1000,
                'payment_type'     => 'onetime',
                'fulfillment_type' => 'digital',
            ]);
        }

        // Replace with just 1 new item
        $newItems = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];

        $processor = new CheckoutProcessor($newItems, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $processor->createDraftOrder($existingOrder);

        $remainingItems = OrderItem::query()->where('order_id', $existingOrder->id)->get();
        $this->assertCount(1, $remainingItems);
    }

    // =========================================================================
    // Shipping in recurring_total for physical subscriptions
    // =========================================================================

    public function test_physical_sub_recurring_total_adds_shipping_with_exclusive_tax(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'       => 40000,
            'subtotal'         => 40000,
            'fulfillment_type' => 'physical',
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'shipping_charge' => 5000,
            'shipping_tax'    => 500,
            'tax_behavior'    => 1, // exclusive
        ]);
        $processor->createDraftOrder();

        // 40000 subtotal + 5000 shipping + 500 shipping_tax (exclusive) = 45500
        $this->assertEquals(45500, (int) $processor->getSubscription()->recurring_total);
    }

    public function test_physical_sub_recurring_total_adds_shipping_without_tax_when_inclusive(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'       => 40000,
            'subtotal'         => 40000,
            'fulfillment_type' => 'physical',
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'shipping_charge' => 5000,
            'shipping_tax'    => 500,
            'tax_behavior'    => 2, // inclusive — shipping_tax already baked in
        ]);
        $processor->createDraftOrder();

        // 40000 + 5000 shipping; shipping_tax NOT added (inclusive)
        $this->assertEquals(45000, (int) $processor->getSubscription()->recurring_total);
    }

    public function test_inclusive_items_in_exclusive_store_add_shipping_and_fee_tax_to_the_total(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeProcessorItem(['unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'        => $customer->id,
            'payment_method'     => 'offline_payment',
            'shipping_charge'    => 1000,
            'shipping_tax'       => 150,
            'tax_total'          => 139,
            'fee_tax'            => 75,
            'fees'               => [['key' => 'proc', 'label' => 'Processing', 'amount' => 500]],
            'tax_behavior'       => 2, // every line inclusive
            'store_tax_behavior' => 1, // store exclusive: shipping and fee tax go on top
        ]);
        $order = $processor->createDraftOrder();

        $this->assertSame(575, (int) $order->fee_total, 'fee tax is rolled into fee_total');
        $this->assertSame(139, (int) $order->tax_total, 'the included item tax is still stored');
        $this->assertSame(150, (int) $order->shipping_tax);
        // 1000 items + 1000 shipping + 150 shipping tax + 575 fee
        $this->assertSame(2725, (int) $order->total_amount);
    }

    public function test_physical_sub_recurring_total_adds_shipping_tax_when_only_the_store_is_exclusive(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'       => 40000,
            'subtotal'         => 40000,
            'fulfillment_type' => 'physical',
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'        => $customer->id,
            'payment_method'     => 'offline_payment',
            'shipping_charge'    => 5000,
            'shipping_tax'       => 500,
            'tax_behavior'       => 2,
            'store_tax_behavior' => 1,
        ]);
        $processor->createDraftOrder();

        $this->assertEquals(45500, (int) $processor->getSubscription()->recurring_total);
    }

    public function test_digital_sub_recurring_total_excludes_shipping(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price' => 40000,
            'subtotal'   => 40000,
            // fulfillment_type defaults to 'digital'
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'shipping_charge' => 5000,
            'tax_behavior'    => 1,
        ]);
        $processor->createDraftOrder();

        // Digital: shipping not recurring — stays out of recurring_total
        $this->assertEquals(40000, (int) $processor->getSubscription()->recurring_total);
    }

    // =========================================================================
    // A shipping pricing rule discounts the first order's postage, not renewals
    // =========================================================================

    /**
     * $10/year physical subscription, $16.99 shipping, 4.75% tax ($0.48 item, $0.81 shipping).
     * The args mirror what CheckoutApi::placeOrder() passes after a shipping rule.
     */
    private function placePhysicalYearlySubscription(array $args, array $otherInfo = []): array
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'       => 1000,
            'subtotal'         => 1000,
            'tax_amount'       => 48,
            'fulfillment_type' => 'physical',
            'other_info'       => array_merge([
                'payment_type'    => 'subscription',
                'repeat_interval' => 'yearly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 0,
                'recurring_tax'   => 48,
            ], $otherInfo),
        ])];

        $processor = new CheckoutProcessor($items, array_merge([
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'tax_behavior'   => 1,
            'tax_total'      => 48,
            'shipping_tax'   => 81,
        ], $args));
        $order = $processor->createDraftOrder();

        return [$order, $processor->getSubscription()];
    }

    /**
     * What a gateway takes up front: the signup fee alone during a (real or
     * simulated) trial, otherwise one cycle plus the signup fee.
     */
    private function firstPayment(Subscription $subscription): int
    {
        if ((int) $subscription->trial_days > 0) {
            return (int) $subscription->signup_fee;
        }

        return (int) $subscription->recurring_total + (int) $subscription->signup_fee;
    }

    public function test_renewals_bill_full_shipping_after_a_shipping_rule_discount(): void
    {
        [$order, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge'   => 850,
            'shipping_discount' => 849,
        ]);

        // The first order keeps the discounted postage: 1000 + 850 + 48 + 81.
        $this->assertEquals(850, (int) $order->shipping_total);
        $this->assertEquals(1979, (int) $order->total_amount);
        $this->assertEquals(1979, $this->firstPayment($subscription), 'the first payment must stay what the order charges');

        // Renewals: 1000 + 1699 + 48 + 81, the same as with no rule.
        $this->assertEquals(2828, (int) $subscription->recurring_total);
        $this->assertEquals(2699, (int) $subscription->recurring_amount);

        // The lower first cycle is billed as a simulated one-cycle trial.
        $this->assertEquals('yes', Arr::get($subscription->config, 'is_trial_days_simulated'));
        $this->assertGreaterThan(0, (int) $subscription->trial_days);
    }

    public function test_free_shipping_rule_with_exclusive_tax_bills_full_shipping_and_its_tax_on_renewals(): void
    {
        // Shipping tax is still worked out on the full postage, so 81 arrives with a 0 charge.
        [, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge'   => 0,
            'shipping_discount' => 1699,
        ]);

        // 1000 + 1699 + 48 + 81: the shipping tax left out of the free first cycle comes back.
        $this->assertEquals(2828, (int) $subscription->recurring_total);
        $this->assertEquals(2699, (int) $subscription->recurring_amount);
    }

    public function test_renewals_without_a_shipping_rule_are_unchanged(): void
    {
        [$order, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge' => 1699,
        ]);

        $this->assertEquals(2828, (int) $order->total_amount);
        $this->assertEquals(2828, (int) $subscription->recurring_total);
        $this->assertEquals(2699, (int) $subscription->recurring_amount);
        $this->assertEquals(0, (int) $subscription->trial_days);
        $this->assertEquals(0, (int) $subscription->signup_fee);
        $this->assertEquals('no', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
    }

    public function test_shipping_rule_discount_comes_off_the_signup_fee_when_it_covers_it(): void
    {
        [$order, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge'   => 850,
            'shipping_discount' => 849,
            'tax_total'         => 0,
            'shipping_tax'      => 0,
        ], ['signup_fee' => 2000, 'recurring_tax' => 0]);

        // 1000 + 2000 signup fee + 850 shipping; taxes zeroed for this case.
        $this->assertEquals(3850, (int) $order->total_amount);
        $this->assertEquals(0, (int) $subscription->trial_days);
        $this->assertEquals('no', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
        $this->assertEquals(2699, (int) $subscription->recurring_total);
        $this->assertEquals(1151, (int) $subscription->signup_fee);
        $this->assertEquals(3850, $this->firstPayment($subscription));
    }

    public function test_shipping_rule_discount_keeps_a_real_trial(): void
    {
        [, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge'   => 850,
            'shipping_discount' => 849,
        ], ['trial_days' => 14]);

        $this->assertEquals(14, (int) $subscription->trial_days);
        $this->assertEquals('no', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
        $this->assertEquals(0, (int) $subscription->signup_fee);
        $this->assertEquals(2828, (int) $subscription->recurring_total);
    }

    public function test_free_shipping_rule_still_bills_shipping_on_renewals(): void
    {
        [$order, $subscription] = $this->placePhysicalYearlySubscription([
            'shipping_charge'   => 0,
            'shipping_discount' => 1699,
            'tax_total'         => 0,
            'shipping_tax'      => 0,
        ], ['recurring_tax' => 0]);

        $this->assertEquals(1000, (int) $order->total_amount);
        $this->assertEquals(1000, $this->firstPayment($subscription));
        $this->assertEquals(2699, (int) $subscription->recurring_total);
        $this->assertEquals(2699, (int) $subscription->recurring_amount);
    }

    // =========================================================================
    // Spec 196: Edge — locked adjusted order syncs fee items
    // =========================================================================

    public function test_adjusted_order_locked_syncs_fee_items(): void
    {
        $customer = $this->customerFactory->create();
        $existingOrder = $this->orderFactory->create([
            'customer_id' => $customer->id,
        ]);

        // Add an old fee item
        OrderItem::query()->create([
            'order_id'         => $existingOrder->id,
            'post_title'       => '',
            'title'            => 'Old Fee',
            'quantity'         => 1,
            'unit_price'       => 100,
            'subtotal'         => 100,
            'line_total'       => 100,
            'payment_type'     => 'fee',
            'fulfillment_type' => 'digital',
        ]);

        // Process with new fees
        $items = [$this->makeProcessorItem(['unit_price' => 5000, 'subtotal' => 5000])];
        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
            'is_locked'      => true,
            'fees'           => [
                ['key' => 'new_fee', 'label' => 'New Fee', 'amount' => 750],
            ],
        ]);
        $processor->createDraftOrder($existingOrder);

        $feeItems = OrderItem::query()
            ->where('order_id', $existingOrder->id)
            ->where('payment_type', 'fee')
            ->get();

        // Old fee should be replaced by new fee
        $this->assertCount(1, $feeItems);
        $this->assertEquals(750, $feeItems->first()->unit_price);
    }

    // =========================================================================
    // Recurring coupon — the discount repeats, so no simulated trial
    // =========================================================================

    public function test_recurring_coupon_does_not_simulate_a_trial(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'          => 10000,
            'subtotal'            => 10000,
            'discount_total'      => 2000,
            'coupon_discount'     => 2000,
            'recurring_discounts' => ['signup' => 0, 'amount' => 2000],
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $processor->createDraftOrder();
        $subscription = $processor->getSubscription();

        $this->assertEquals('no', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
        $this->assertEquals(0, (int) $subscription->trial_days);
        $this->assertEquals(0, (int) $subscription->signup_fee);
        $this->assertEquals(8000, (int) $subscription->recurring_amount);
        $this->assertEquals(8000, (int) $subscription->recurring_total);
    }

    public function test_recurring_coupon_with_signup_fee_charges_the_discounted_fee_up_front(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'          => 10000,
            'subtotal'            => 10000,
            // 20% off the whole first cycle: 20% of (10000 + 5000)
            'discount_total'      => 3000,
            'coupon_discount'     => 3000,
            'recurring_discounts' => ['signup' => 0, 'amount' => 2000],
            'other_info'          => [
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'times'           => 0,
                'trial_days'      => 0,
                'signup_fee'      => 5000,
                'recurring_tax'   => 0,
            ],
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $processor->createDraftOrder();
        $subscription = $processor->getSubscription();

        // First cycle (12000) is dearer than a cycle (8000), so the excess is the
        // discounted signup fee — never a trial.
        $this->assertEquals('no', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
        $this->assertEquals(0, (int) $subscription->trial_days);
        $this->assertEquals(4000, (int) $subscription->signup_fee);
        $this->assertEquals(8000, (int) $subscription->recurring_amount);
    }

    public function test_first_cycle_only_coupon_still_simulates_a_trial(): void
    {
        $customer = $this->customerFactory->create();
        $items = [$this->makeSubscriptionProcessorItem([
            'unit_price'          => 10000,
            'subtotal'            => 10000,
            'discount_total'      => 2000,
            'coupon_discount'     => 2000,
            'recurring_discounts' => ['signup' => 0, 'amount' => 0],
        ])];

        $processor = new CheckoutProcessor($items, [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]);
        $processor->createDraftOrder();
        $subscription = $processor->getSubscription();

        $this->assertEquals('yes', Arr::get($subscription->config, 'is_trial_days_simulated', 'no'));
        $this->assertEquals(8000, (int) $subscription->signup_fee);
        $this->assertEquals(10000, (int) $subscription->recurring_amount);
        $this->assertEquals(10000, (int) $subscription->recurring_total);
    }
}
