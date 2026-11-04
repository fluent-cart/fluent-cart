<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class OrderItemTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 71: Columns
    // =========================================================================

    public function test_order_items_table_has_expected_columns(): void
    {
        $expected = [
            'id', 'order_id', 'post_id', 'fulfillment_type', 'fulfilled_quantity',
            'post_title', 'title', 'object_id', 'cart_index', 'quantity',
            'unit_price', 'cost', 'subtotal', 'tax_amount', 'discount_total',
            'refund_total', 'line_total', 'rate', 'other_info', 'line_meta',
            'referrer', 'payment_type', 'created_at',
        ];
        $this->assertColumnsExist('fct_order_items', $expected);
    }

    // =========================================================================
    // Spec 72: Monetary casts
    // =========================================================================

    public function test_monetary_columns_cast_to_double(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'unit_price'     => 2999,
            'subtotal'       => 2999,
            'tax_amount'     => 500,
            'discount_total' => 100,
            'line_total'     => 3399,
            'refund_total'   => 0,
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertIsFloat($fresh->unit_price);
        $this->assertIsFloat($fresh->subtotal);
        $this->assertIsFloat($fresh->tax_amount);
        $this->assertIsFloat($fresh->discount_total);
        $this->assertIsFloat($fresh->line_total);
        $this->assertIsFloat($fresh->refund_total);
    }

    // =========================================================================
    // Spec 73-74: JSON attributes
    // =========================================================================

    public function test_other_info_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'other_info' => ['payment_type' => 'onetime', 'custom_key' => 'custom_value'],
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertIsArray($fresh->other_info);
        $this->assertSame('custom_value', $fresh->other_info['custom_key']);
    }

    public function test_other_info_returns_array_for_any_input(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id);

        // Default factory value is an array — verify it round-trips
        $fresh = OrderItem::query()->find($item->id);
        $this->assertIsArray($fresh->other_info);

        // Even with null set via DB, accessor returns array
        global $wpdb;
        $table = $wpdb->prefix . 'fct_order_items';
        $wpdb->update($table, ['other_info' => null], ['id' => $item->id]);

        $nullFresh = OrderItem::query()->find($item->id);
        $this->assertIsArray($nullFresh->other_info);
        $this->assertEmpty($nullFresh->other_info);
    }

    public function test_line_meta_json_encoded_and_decoded(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'line_meta' => ['key1' => 'val1'],
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertIsArray($fresh->line_meta);
        $this->assertSame('val1', $fresh->line_meta['key1']);
    }

    // =========================================================================
    // Spec 75-79: Appended Attributes
    // =========================================================================

    public function test_formatted_total_returns_decimal_of_line_total_when_line_total_is_set(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'   => 5000,
            'line_total' => 4500,
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertEquals(
            Helper::toDecimal(4500),
            $fresh->formatted_total,
            'formatted_total must format line_total, not subtotal, when line_total is non-zero'
        );
    }

    public function test_formatted_total_falls_back_to_subtotal_when_line_total_and_discount_are_zero(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'       => 5000,
            'line_total'     => 0,
            'discount_total' => 0,
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertEquals(
            Helper::toDecimal(5000),
            $fresh->formatted_total,
            'formatted_total must fall back to subtotal when line_total and discount_total are both zero'
        );
    }

    public function test_formatted_total_shows_zero_line_total_for_fully_discounted_item(): void
    {
        // Discriminates the guard's AND from an OR regression: a 100%-discounted
        // item (line_total 0, discount_total > 0) must display the zero line
        // total — under `line_total == 0 || discount_total == 0` it would show
        // the pre-discount subtotal, a customer-visible money regression.
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'       => 5000,
            'line_total'     => 0,
            'discount_total' => 5000,
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertEquals(
            Helper::toDecimal(0),
            $fresh->formatted_total,
            'a fully discounted item must format its zero line_total, never the pre-discount subtotal'
        );
    }

    public function test_payment_info_returns_empty_for_onetime_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, ['payment_type' => 'onetime']);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertSame('', $fresh->payment_info);
    }

    public function test_setup_info_returns_empty_for_onetime_items(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, ['payment_type' => 'onetime']);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertSame('', $fresh->setup_info);
    }

    public function test_is_custom_returns_value_from_other_info(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);

        $customItem = $this->itemFactory->create($order->id, [
            'other_info' => ['is_custom' => true, 'payment_type' => 'onetime'],
        ]);
        $normalItem = $this->itemFactory->create($order->id);

        $this->assertTrue(OrderItem::query()->find($customItem->id)->is_custom);
        $this->assertFalse(OrderItem::query()->find($normalItem->id)->is_custom);
    }

    public function test_full_name_returns_title_plus_post_title(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'title'      => 'Gold Plan',
            'post_title' => 'Premium Product',
        ]);

        $fresh = OrderItem::query()->find($item->id);
        $this->assertSame('Gold Plan Premium Product', $fresh->full_name);
    }

    // =========================================================================
    // Spec 80-82: Relationships
    // =========================================================================

    public function test_order_item_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id);

        $this->assertInstanceOf(Order::class, $item->order);
        $this->assertSame($order->id, $item->order->id);
    }

    public function test_order_item_belongs_to_product(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, ['post_id' => $product->ID]);

        $this->assertInstanceOf(Product::class, $item->product);
        $this->assertSame($product->ID, $item->product->ID);
    }

    public function test_order_item_belongs_to_product_variation(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = $this->itemFactory->create($order->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
        ]);

        $this->assertInstanceOf(ProductVariation::class, $item->variants);
        $this->assertSame($variant->id, $item->variants->id);
    }

    public function test_charged_total_adds_exclusive_tax_on_top_of_a_checkout_line(): void
    {
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id, 'tax_behavior' => 1]);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'   => 123000,
            'line_total' => 123000,
            'tax_amount' => 12300,
        ]);

        $this->assertSame(135300, $item->chargedTotal($order));
    }

    public function test_charged_total_does_not_add_tax_twice_on_a_renewal_line(): void
    {
        $order = $this->renewalOrder(1);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'   => 123000,
            'line_total' => 135300,
            'tax_amount' => 12300,
            'line_meta'  => [],
            'other_info' => [],
        ]);

        $this->assertSame(135300, $item->chargedTotal($order));
    }

    public function test_charged_total_does_not_add_tax_twice_on_a_discounted_renewal_line(): void
    {
        $order = $this->renewalOrder(1);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'       => 123000,
            'discount_total' => 23000,
            'line_total'     => 110000,
            'tax_amount'     => 10000,
            'line_meta'      => [],
            'other_info'     => [],
        ]);

        $this->assertSame(110000, $item->chargedTotal($order));
    }

    public function test_charged_total_of_a_renewal_line_is_its_line_total_for_every_tax_behavior(): void
    {
        foreach ([1, 2, 3] as $taxBehavior) {
            $order = $this->renewalOrder($taxBehavior);
            $item = $this->itemFactory->create($order->id, [
                'quantity'   => 3,
                'unit_price' => 41000,
                'subtotal'   => 123000,
                'line_total' => 135300,
                'tax_amount' => 12300,
                'line_meta'  => [],
                'other_info' => [],
            ]);

            $this->assertSame(135300, $item->chargedTotal($order), 'tax_behavior ' . $taxBehavior);
        }
    }

    public function test_charged_total_adds_exclusive_tax_when_a_non_renewal_line_has_the_renewal_shape(): void
    {
        $order = $this->orderFactory->create(['customer_id' => $this->customerFactory->create()->id, 'tax_behavior' => 1]);
        $item = $this->itemFactory->create($order->id, [
            'subtotal'   => 123000,
            'line_total' => 135300,
            'tax_amount' => 12300,
            'line_meta'  => [],
            'other_info' => [],
        ]);

        $this->assertSame(147600, $item->chargedTotal($order));
    }

    public function test_charged_total_of_a_trial_subscription_line_on_its_first_order_is_zero(): void
    {
        $order = $this->subscriptionOrder();
        $item = $this->itemFactory->create($order->id, [
            'payment_type' => 'subscription',
            'subtotal'     => 4000,
            'line_total'   => 4000,
            'tax_amount'   => 400,
            'other_info'   => ['payment_type' => 'subscription', 'trial_days' => 7],
        ]);

        $this->assertSame(0, $item->chargedTotal($order));
    }

    public function test_charged_total_of_a_simulated_trial_subscription_line_is_its_full_charge(): void
    {
        $order = $this->subscriptionOrder();
        $product = $this->productFactory->create();
        $item = $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $product->variants->first()->id,
            'payment_type' => 'subscription',
            'subtotal'     => 4000,
            'line_total'   => 4000,
            'tax_amount'   => 400,
            'other_info'   => ['payment_type' => 'subscription', 'trial_days' => 0],
        ]);
        (new SubscriptionFactory())->create([
            'customer_id'     => $order->customer_id,
            'parent_order_id' => $order->id,
            'product_id'      => $item->post_id,
            'variation_id'    => $item->object_id,
            'trial_days'      => 7,
            'status'          => Status::SUBSCRIPTION_TRIALING,
            'config'          => ['is_trial_days_simulated' => 'yes'],
        ]);

        $this->assertSame(4400, $item->chargedTotal($order));
    }

    public function test_charged_total_of_a_non_subscription_line_carrying_trial_days_is_its_full_charge(): void
    {
        $order = $this->subscriptionOrder();
        $item = $this->itemFactory->create($order->id, [
            'payment_type' => 'signup_fee',
            'subtotal'     => 2500,
            'line_total'   => 2500,
            'tax_amount'   => 250,
            'other_info'   => ['payment_type' => 'signup_fee', 'trial_days' => 7],
        ]);

        $this->assertSame(2750, $item->chargedTotal($order));
    }

    public function test_charged_total_of_a_renewal_line_that_keeps_trial_days_is_its_full_charge(): void
    {
        $order = $this->renewalOrder(1);
        $item = $this->itemFactory->create($order->id, [
            'payment_type' => 'subscription',
            'subtotal'     => 40000,
            'line_total'   => 40000,
            'tax_amount'   => 0,
            'line_meta'    => [],
            'other_info'   => ['payment_type' => 'subscription', 'trial_days' => 24],
        ]);

        $this->assertSame(40000, $item->chargedTotal($order));
    }

    private function subscriptionOrder(): Order
    {
        $order = $this->orderFactory->create([
            'customer_id'  => $this->customerFactory->create()->id,
            'type'         => Status::ORDER_TYPE_SUBSCRIPTION,
            'tax_behavior' => 1,
        ]);

        return Order::query()->find($order->id);
    }

    private function renewalOrder(int $taxBehavior): Order
    {
        $order = $this->orderFactory->create([
            'customer_id'  => $this->customerFactory->create()->id,
            'type'         => Status::ORDER_TYPE_RENEWAL,
            'tax_behavior' => $taxBehavior,
        ]);

        return Order::query()->find($order->id);
    }
}
