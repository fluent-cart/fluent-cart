<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderOperation;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class OrderOperationTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
    }

    // =========================================================================
    // Spec 108: Columns
    // =========================================================================

    public function test_order_operations_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'order_id', 'created_via', 'emails_sent', 'sales_recorded',
            'utm_campaign', 'utm_term', 'utm_source', 'utm_content',
            'utm_medium', 'utm_id', 'cart_hash', 'refer_url', 'meta',
        ];
        $this->assertColumnsExist('fct_order_operations', $expected);
    }

    // =========================================================================
    // Spec 109: Relationships
    // =========================================================================

    public function test_order_operation_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $op = OrderOperation::query()->create([
            'order_id'    => $order->id,
            'created_via' => 'admin',
        ]);

        $this->assertInstanceOf(Order::class, $op->order);
        $this->assertSame($order->id, $op->order->id);
    }

    // =========================================================================
    // Spec 110: Meta JSON
    // =========================================================================

    public function test_meta_attribute_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $op = OrderOperation::query()->create([
            'order_id'    => $order->id,
            'created_via' => 'checkout',
        ]);

        // Update meta via direct DB update since the JSON column uses set/get accessors
        $op->meta = ['source' => 'web'];
        $op->save();

        $fresh = OrderOperation::query()->find($op->id);
        $this->assertIsArray($fresh->meta);
        $this->assertArrayHasKey('source', $fresh->meta);
        $this->assertSame('web', $fresh->meta['source']);
    }

    public function test_meta_null_returns_empty_array(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $op = OrderOperation::query()->create([
            'order_id'    => $order->id,
            'created_via' => 'checkout',
        ]);

        $fresh = OrderOperation::query()->find($op->id);
        $this->assertIsArray($fresh->meta);
        $this->assertEmpty($fresh->meta);
    }
}
