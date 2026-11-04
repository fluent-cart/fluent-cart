<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Payments\PaymentInstance;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class PaymentInstanceTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderItemFactory $itemFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->itemFactory = new OrderItemFactory();
    }

    // =========================================================================
    // Spec 109: Constructor loads latest charge transaction
    // =========================================================================

    public function test_constructor_loads_latest_charge_transaction(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total'            => 1000,
            'created_at'       => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        $latest = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
            'total'            => 2000,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);

        $instance = new PaymentInstance($order);
        $this->assertSame($latest->id, $instance->transaction->id);
    }

    // =========================================================================
    // Spec 110: Load subscription by parent_order_id for subscription orders
    // =========================================================================

    public function test_constructor_loads_subscription_for_subscription_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $instance = new PaymentInstance($order);
        $this->assertInstanceOf(Subscription::class, $instance->subscription);
        $this->assertSame($sub->id, $instance->subscription->id);
    }

    // =========================================================================
    // Spec 111: Renewal order loads subscription by parent_id
    // =========================================================================

    public function test_constructor_loads_subscription_for_renewal_order(): void
    {
        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        $renewal = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => Status::ORDER_TYPE_RENEWAL,
            'parent_id'   => $parent->id,
        ]);
        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $parent->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $instance = new PaymentInstance($renewal);
        $this->assertSame($sub->id, $instance->subscription->id);
    }

    // =========================================================================
    // Spec 112: Subscription null when no matching row
    // =========================================================================

    public function test_subscription_is_null_for_plain_payment_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $instance = new PaymentInstance($order);
        $this->assertNull($instance->subscription);
    }

    // =========================================================================
    // Spec 113: getExtraAddonAmount returns 0 when no subscription
    // =========================================================================

    public function test_get_extra_addon_amount_returns_zero_when_no_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $instance = new PaymentInstance($order);
        $this->assertSame(0, $instance->getExtraAddonAmount());
    }

    // =========================================================================
    // Spec 114: getExtraAddonAmount sums non-subscription items
    // =========================================================================

    public function test_get_extra_addon_amount_sums_non_subscription_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'shipping_total' => 0,
        ]);
        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $this->itemFactory->create($order->id, [
            'payment_type' => 'subscription',
            'line_total'   => 5000,
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type' => 'signup_fee',
            'line_total'   => 1000,
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type' => 'fee',
            'line_total'   => 500,
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type' => 'onetime',
            'line_total'   => 2500,
        ]);

        $instance = new PaymentInstance($order);
        $this->assertSame(2500.0, (float) $instance->getExtraAddonAmount());
    }

    // =========================================================================
    // Spec 115: getExtraAddonAmount adds shipping_total
    // =========================================================================

    public function test_get_extra_addon_amount_includes_shipping_total(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'shipping_total' => 700,
        ]);
        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type' => 'onetime',
            'line_total'   => 1000,
        ]);

        $instance = new PaymentInstance($order);
        $this->assertSame(1700.0, (float) $instance->getExtraAddonAmount());
    }

    // =========================================================================
    // Shipping in getExtraAddonAmount — physical vs digital subscription
    // =========================================================================

    public function test_get_extra_addon_amount_skips_shipping_for_physical_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'shipping_total' => 5000,
        ]);
        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Physical Sub',
            'recurring_amount' => 40000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type'     => 'subscription',
            'fulfillment_type' => 'physical',
            'line_total'       => 40000,
        ]);

        $instance = new PaymentInstance($order);
        // Physical sub: shipping already in recurring_total — must not be double-counted here
        $this->assertSame(0, $instance->getExtraAddonAmount());
    }

    public function test_get_extra_addon_amount_includes_shipping_when_subscription_is_digital(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'type'           => 'subscription',
            'shipping_total' => 5000,
        ]);
        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Digital Sub',
            'recurring_amount' => 40000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type'     => 'subscription',
            'fulfillment_type' => 'digital',
            'line_total'       => 40000,
        ]);
        $this->itemFactory->create($order->id, [
            'payment_type' => 'onetime',
            'line_total'   => 3000,
        ]);

        $instance = new PaymentInstance($order);
        // Digital sub + onetime addon (3000) + shipping (5000) = 8000 on first invoice
        $this->assertSame(8000.0, (float) $instance->getExtraAddonAmount());
    }

    // =========================================================================
    // Spec 116: getSubscriptionCancelAtTimestamp maps billing_interval
    // =========================================================================

    public function test_cancel_at_timestamp_is_null_without_subscription(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $instance = new PaymentInstance($order);
        $this->assertNull($instance->getSubscriptionCancelAtTimeStamp());
    }

    public function test_cancel_at_timestamp_computes_future_offset_for_monthly(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
        ]);
        Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $order->id,
            'status'           => Status::SUBSCRIPTION_ACTIVE,
            'item_name'        => 'Sub',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'bill_times'       => 6,
            'bill_count'       => 0,
            'payment_method'   => 'offline_payment',
        ]);

        $instance = new PaymentInstance($order);
        $ts = $instance->getSubscriptionCancelAtTimeStamp();

        $this->assertGreaterThan(time(), $ts);
        $this->assertLessThan(strtotime('+7 months'), $ts);
    }
}
