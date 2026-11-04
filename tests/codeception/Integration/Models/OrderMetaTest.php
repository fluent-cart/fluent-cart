<?php

namespace Tests\Integration\Models;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class OrderMetaTest extends DatabaseTestCase
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
    // Spec 111: Columns
    // =========================================================================

    public function test_order_meta_table_has_expected_columns(): void
    {
        $expected = ['id', 'order_id', 'meta_key', 'meta_value'];
        $this->assertColumnsExist('fct_order_meta', $expected);
    }

    // =========================================================================
    // Spec 112: meta_value JSON handling
    // =========================================================================

    public function test_meta_value_json_encoded_for_arrays(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $meta = OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'complex_data',
            'meta_value' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $fresh = OrderMeta::query()->find($meta->id);
        $this->assertIsArray($fresh->meta_value);
        $this->assertSame('value', $fresh->meta_value['key']);
        $this->assertSame(1, $fresh->meta_value['nested']['a']);
    }

    public function test_meta_value_returns_plain_string_as_is(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $meta = OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'simple_key',
            'meta_value' => 'simple_string',
        ]);

        $fresh = OrderMeta::query()->find($meta->id);
        $this->assertSame('simple_string', $fresh->meta_value);
    }

    // =========================================================================
    // Spec 113: Relationships
    // =========================================================================

    public function test_order_meta_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $meta = OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'test',
            'meta_value' => 'value',
        ]);

        $this->assertInstanceOf(Order::class, $meta->order);
        $this->assertSame($order->id, $meta->order->id);
    }

    // =========================================================================
    // Spec 114: updateMeta
    // =========================================================================

    public function test_order_update_meta_creates_when_key_not_exists(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        // Use Order model's updateMeta (the primary API)
        $order->updateMeta('brand_new_key', 'brand_new_value');

        $found = OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'brand_new_key')
            ->first();

        $this->assertNotNull($found);
        $this->assertSame('brand_new_value', $found->meta_value);
    }

    public function test_order_update_meta_updates_when_key_exists(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        // Create initial value
        $order->updateMeta('existing_key', 'old_value');
        // Update to new value
        $order->updateMeta('existing_key', 'new_value');

        $found = OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'existing_key')
            ->first();

        $this->assertSame('new_value', $found->meta_value);
        // Verify only one row exists
        $count = OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'existing_key')
            ->count();
        $this->assertSame(1, $count);
    }
}
