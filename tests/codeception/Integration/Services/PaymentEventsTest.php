<?php

namespace Tests\Integration\Services;

use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderPaymentFailed;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class PaymentEventsTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderTransactionFactory $txnFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->txnFactory = new OrderTransactionFactory();
    }

    // =========================================================================
    // Spec 197: OrderPaid hook
    // =========================================================================

    public function test_order_paid_hook_value(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $event = new OrderPaid($order);
        $this->assertSame('fluent_cart/order_paid', $event->hook);
    }

    // =========================================================================
    // Spec 198: beforeDispatch generates receipt number
    // =========================================================================

    public function test_order_paid_before_dispatch_generates_receipt_number(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'receipt_number' => null,
            'total_amount'   => 5000,
            'total_paid'     => 5000,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $this->txnFactory->create($order->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => 5000,
        ]);

        $event = new OrderPaid($order);
        $event->beforeDispatch();

        $this->assertNotEmpty($event->order->receipt_number);
    }

    // =========================================================================
    // Spec 199: afterDispatch enqueues async action
    // =========================================================================

    public function test_order_paid_after_dispatch_schedules_async_action(): void
    {

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 5000,
            'total_paid'   => 5000,
        ]);

        $event = new OrderPaid($order);
        $event->afterDispatch();

        $actionId = $event->order->getMeta('action_scheduler_id');
        $this->assertNotEmpty($actionId);
    }

    // =========================================================================
    // Spec 200: OrderRefund hook
    // =========================================================================

    public function test_order_refund_hook_value(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 5000,
            'total_paid'     => 5000,
            'total_refund'   => 0,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 1000,
        ]);

        $event = new OrderRefund($order);
        $this->assertSame('fluent_cart/order_refunded', $event->hook);
    }

    // =========================================================================
    // Spec 201: Short-circuits when no new refund
    // =========================================================================

    public function test_order_refund_short_circuits_when_no_new_refund_amount(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 5000,
            'total_paid'   => 5000,
            'total_refund' => 1000,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 1000,
        ]);

        $event = new OrderRefund($order);
        $this->assertFalse($event->willTrigger);
    }

    // =========================================================================
    // Spec 202: fully_refunded fires when refund >= paid
    // =========================================================================

    public function test_order_refund_fires_fully_refunded_action(): void
    {
        $fullyFired = false;
        add_action('fluent_cart/order_fully_refunded', function () use (&$fullyFired) {
            $fullyFired = true;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 5000,
            'total_paid'     => 5000,
            'total_refund'   => 0,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 5000,
        ]);

        (new OrderRefund($order))->dispatch();

        remove_all_actions('fluent_cart/order_fully_refunded');
        $this->assertTrue($fullyFired);
    }

    // =========================================================================
    // Spec 203: partially_refunded fires when refund < paid
    // =========================================================================

    public function test_order_refund_fires_partially_refunded_action(): void
    {
        $partFired = false;
        add_action('fluent_cart/order_partially_refunded', function () use (&$partFired) {
            $partFired = true;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'total_amount'   => 10000,
            'total_paid'     => 10000,
            'total_refund'   => 0,
            'payment_status' => Status::PAYMENT_PAID,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 3000,
        ]);

        (new OrderRefund($order))->dispatch();

        remove_all_actions('fluent_cart/order_partially_refunded');
        $this->assertTrue($partFired);
    }

    // =========================================================================
    // Spec 204: OrderPaymentFailed hook
    // =========================================================================

    public function test_order_payment_failed_hook_value(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $event = new OrderPaymentFailed($order);
        $this->assertSame('fluent_cart/order_payment_failed', $event->hook);
    }

    // =========================================================================
    // Spec 205: OrderPaid listener
    // =========================================================================

    public function test_order_paid_has_correct_listener(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $event = new OrderPaid($order);

        $reflection = new \ReflectionClass($event);
        $prop = $reflection->getProperty('listeners');
        $prop->setAccessible(true);
        $listeners = $prop->getValue($event);

        $this->assertContains(Listeners\Order\OrderPaid::class, $listeners);
    }

    // =========================================================================
    // Spec 206: OrderRefund listener
    // =========================================================================

    public function test_order_refund_has_correct_listener(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_amount' => 5000,
            'total_paid'   => 5000,
            'total_refund' => 0,
        ]);
        $this->txnFactory->create($order->id, [
            'status'           => Status::TRANSACTION_REFUNDED,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'total'            => 1000,
        ]);
        $event = new OrderRefund($order);

        $reflection = new \ReflectionClass($event);
        $prop = $reflection->getProperty('listeners');
        $prop->setAccessible(true);
        $listeners = $prop->getValue($event);

        $this->assertContains(Listeners\Order\OrderRefunded::class, $listeners);
    }
}
