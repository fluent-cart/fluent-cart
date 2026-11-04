<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\SubscriptionFactory;

/**
 * Optional fields[] projection on the heavy list/detail endpoints. When omitted,
 * the full record is returned unchanged; when present, only the requested
 * top-level keys survive (plus the record id, always). Unknown keys are ignored.
 */
class FieldSelectionTest extends DatabaseTestCase
{
    private int $orderId;

    public function setUp(): void
    {
        parent::setUp();
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'total_amount'   => Helper::toCent(120),
            'total_paid'     => Helper::toCent(120),
        ]);
        $this->orderId = (int) $order->id;

        (new SubscriptionFactory())->create([
            'customer_id'     => $customer->id,
            'parent_order_id' => $order->id,
            'product_id'      => 9701,
            'variation_id'    => 97011,
            'status'          => 'active',
            'recurring_total' => Helper::toCent(19),
        ]);
    }

    public function test_list_orders_projects_to_requested_keys_plus_id(): void
    {
        $row = OrderTools::listOrders(['fields' => ['status', 'total']])['data']['orders'][0];
        // order_id is always kept; keys preserved in record order.
        $this->assertSame(['order_id', 'status', 'total'], array_keys($row));
        $this->assertSame(Status::ORDER_COMPLETED, $row['status']);
    }

    public function test_list_orders_unknown_field_is_ignored(): void
    {
        $row = OrderTools::listOrders(['fields' => ['status', 'does_not_exist']])['data']['orders'][0];
        $this->assertSame(['order_id', 'status'], array_keys($row));
    }

    public function test_list_orders_without_fields_is_unchanged(): void
    {
        $row = OrderTools::listOrders([])['data']['orders'][0];
        // Full row still carries the heavier keys.
        foreach (['order_id', 'number', 'label', 'payment_status', 'customer', 'items', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_list_subscriptions_projects_to_requested_keys(): void
    {
        $row = SubscriptionTools::listSubscriptions(['fields' => ['status', 'recurring_total']])['data']['subscriptions'][0];
        $this->assertSame(['subscription_id', 'status', 'recurring_total'], array_keys($row));
    }

    public function test_get_order_projects_after_include(): void
    {
        $data = OrderTools::getOrder([
            'order_id' => $this->orderId,
            'fields'   => ['payment_status', 'totals'],
        ])['data'];
        $this->assertSame(['order_id', 'payment_status', 'totals'], array_keys($data));
        $this->assertSame(Status::PAYMENT_PAID, $data['payment_status']);
    }

    public function test_get_order_without_fields_is_unchanged(): void
    {
        $data = OrderTools::getOrder(['order_id' => $this->orderId])['data'];
        foreach (['order_id', 'uuid', 'status', 'totals', 'customer', 'items'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
