<?php

namespace Tests\Integration\Events;

use FluentCart\App\Events\EventDispatcher;
use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;

class EventsEdgeCasesTest extends WPTestCase
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

    private function makeOrder(array $overrides = []): Order
    {
        $customer = $this->customerFactory->create();
        return $this->orderFactory->create(array_merge(['customer_id' => $customer->id], $overrides));
    }

    // Spec 154: OrderStatusUpdated same old/new produces no *changed-to* hook fire
    // (Activity listener still runs because shouldCreateActivity() defaults to true and is not overridden.)
    public function test_same_old_new_status_produces_no_hook_fire(): void
    {
        $firedGeneric = 0;
        $firedSpecific = 0;
        add_action('fluent_cart/order_status_changed', function () use (&$firedGeneric) { $firedGeneric++; });
        add_action('fluent_cart/order_status_changed_to_processing', function () use (&$firedSpecific) { $firedSpecific++; });

        $order = $this->makeOrder();

        (new OrderStatusUpdated($order, 'processing', 'processing', true, [], 'order_status'))->dispatch();

        $this->assertSame(0, $firedGeneric);
        $this->assertSame(0, $firedSpecific);
    }

    // Spec 155: duplicate OrderPaid dispatch preserves receipt_number
    public function test_duplicate_order_paid_dispatch_preserves_receipt(): void
    {
        $order = $this->makeOrder();
        (new OrderPaid($order))->dispatch();
        $first = Order::query()->find($order->id)->receipt_number;
        $this->assertNotEmpty($first);

        (new OrderPaid(Order::query()->find($order->id)))->dispatch();
        $second = Order::query()->find($order->id)->receipt_number;

        $this->assertSame($first, $second);
    }

    // Spec 156: OrderRefund with zero newRefundAmount skips refund hook fires (willTrigger=false)
    public function test_zero_refund_amount_skips_refund_hook(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'  => $customer->id,
            'total_paid'   => Helper::toCent(100),
            'total_refund' => Helper::toCent(50),
        ]);
        $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(50),
        ]);

        $fired = 0;
        add_action('fluent_cart/order_refunded', function () use (&$fired) { $fired++; });
        add_action('fluent_cart/order_partially_refunded', function () use (&$fired) { $fired++; });
        add_action('fluent_cart/order_fully_refunded', function () use (&$fired) { $fired++; });

        (new OrderRefund($order, null, [], false))->dispatch();

        $this->assertSame(0, $fired);
    }

    // Spec 157: Activity log default status is 'success'
    public function test_activity_log_defaults_to_success_status(): void
    {
        $order = $this->makeOrder();

        (new \FluentCart\App\Events\Order\OrderCreated($order))->dispatch();

        $log = Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertSame('success', $log->status);
    }

    // Spec 158: do_action payload equals toArray() return value (reference integrity)
    public function test_do_action_payload_matches_to_array(): void
    {
        $order = $this->makeOrder();
        $event = new \FluentCart\App\Events\Order\OrderCreated($order);

        $received = null;
        add_action('fluent_cart/order_created', function ($data) use (&$received) {
            $received = $data;
        });

        $event->dispatch();

        $this->assertIsArray($received);
        $this->assertSame(array_keys($event->toArray()), array_keys($received));
    }

    // Spec 159: empty hook or non-string never triggers do_action
    public function test_empty_hook_never_triggers_do_action(): void
    {
        $event = new class extends EventDispatcher {
            public string $hook = '';
            public bool $autoFireHook = true;
            protected array $listeners = [];

            public function toArray(): array { return ['x' => 1]; }
            public function getActivityEventModel() { return null; }
            public function shouldCreateActivity(): bool { return false; }
        };

        $fired = 0;
        add_action('', function () use (&$fired) { $fired++; });

        $event->dispatch();

        $this->assertSame(0, $fired);
    }
}
