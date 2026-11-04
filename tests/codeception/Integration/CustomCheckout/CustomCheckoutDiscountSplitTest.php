<?php

namespace Tests\Integration\CustomCheckout;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\CustomCheckout\CustomCheckout;
use FluentCart\App\Models\Order;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * FluentCart Ticket #3536: buildOneTimeCartItems() proportionally redistributed
 * order.manual_discount_total across items instead of reading each order
 * item's own stored discount_total/coupon_discount split. When a manual
 * discount was concentrated on one item, the redistribution both kept a
 * share on that item AND relabeled the remainder as a "coupon_discount",
 * double-counting part of the discount on payment-link re-pay.
 */
class CustomCheckoutDiscountSplitTest extends DatabaseTestCase
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

    private function buildItems(Order $order, int $itemManualDiscountTotal): array
    {
        $order = $order->fresh(['order_items']);
        $reflection = new \ReflectionMethod(CustomCheckout::class, 'buildOneTimeCartItems');
        $reflection->setAccessible(true);
        return $reflection->invoke(null, $order, $itemManualDiscountTotal);
    }

    /**
     * Ticket's own reproduction: two equal-price items, a manual discount
     * applied only to the first. The stored split must be preserved exactly
     * instead of proportionally spread across both items.
     */
    public function test_two_equal_items_manual_discount_on_one_item_only(): void
    {
        $customer = $this->customerFactory->create();
        $productA = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();
        $productB = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'subtotal'              => 12000,
            'manual_discount_total' => 1000,
            'coupon_discount_total' => 0,
            'total_amount'          => 11000,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id' => $productA->ID, 'object_id' => $productA->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 1000,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => $productB->ID, 'object_id' => $productB->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 0,
        ]);

        $items = $this->buildItems($order, 1000);

        $this->assertSame(1000, (int) $items[0]['manual_discount']);
        $this->assertSame(0, (int) $items[0]['coupon_discount']);
        $this->assertSame(0, (int) $items[1]['manual_discount']);
        $this->assertSame(0, (int) $items[1]['coupon_discount']);

        $totalDiscount = (int) $items[0]['discount_total'] + (int) $items[1]['discount_total'];
        $this->assertSame(1000, $totalDiscount, 'Effective discount must stay 1000 (10.00), not double-count to 1500');
    }

    /**
     * Unequal subtotals: proportional redistribution still misallocates even
     * when one item happens to reconstruct correctly on its own, because the
     * *other* item's coupon_discount clamp (max(0, ...)) silently absorbs a
     * spurious manual share instead of raising the total.
     */
    public function test_unequal_item_subtotals_preserve_stored_split(): void
    {
        $customer = $this->customerFactory->create();
        $productA = $this->productFactory->withVariantAttributes(['item_price' => 4000])->create();
        $productB = $this->productFactory->withVariantAttributes(['item_price' => 8000])->create();

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'subtotal'              => 12000,
            'manual_discount_total' => 1200,
            'coupon_discount_total' => 0,
            'total_amount'          => 10800,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id' => $productA->ID, 'object_id' => $productA->variants->first()->id,
            'unit_price' => 4000, 'subtotal' => 4000, 'discount_total' => 1200,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => $productB->ID, 'object_id' => $productB->variants->first()->id,
            'unit_price' => 8000, 'subtotal' => 8000, 'discount_total' => 0,
        ]);

        $items = $this->buildItems($order, 1200);

        $this->assertSame(1200, (int) $items[0]['manual_discount']);
        $this->assertSame(0, (int) $items[0]['coupon_discount']);
        $this->assertSame(0, (int) $items[1]['manual_discount']);
        $this->assertSame(0, (int) $items[1]['coupon_discount']);

        $totalDiscount = (int) $items[0]['discount_total'] + (int) $items[1]['discount_total'];
        $this->assertSame(1200, $totalDiscount);
    }

    /**
     * A single item carrying both a manual and a coupon discount must keep
     * that exact split, not have it re-derived from the aggregate.
     */
    public function test_combined_coupon_and_manual_discount_on_same_item(): void
    {
        $customer = $this->customerFactory->create();
        $productA = $this->productFactory->withVariantAttributes(['item_price' => 10000])->create();
        $productB = $this->productFactory->withVariantAttributes(['item_price' => 5000])->create();

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'subtotal'              => 15000,
            'manual_discount_total' => 700,
            'coupon_discount_total' => 300,
            'total_amount'          => 14000,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id' => $productA->ID, 'object_id' => $productA->variants->first()->id,
            'unit_price' => 10000, 'subtotal' => 10000, 'discount_total' => 1000,
            'line_meta' => ['coupon_discount' => 300],
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => $productB->ID, 'object_id' => $productB->variants->first()->id,
            'unit_price' => 5000, 'subtotal' => 5000, 'discount_total' => 0,
        ]);

        $items = $this->buildItems($order, 700);

        $this->assertSame(700, (int) $items[0]['manual_discount']);
        $this->assertSame(300, (int) $items[0]['coupon_discount']);
        $this->assertSame(1000, (int) $items[0]['discount_total']);
        $this->assertSame(0, (int) $items[1]['manual_discount']);
        $this->assertSame(0, (int) $items[1]['coupon_discount']);
    }

    /**
     * Three items whose subtotals don't divide evenly. The old proportional
     * formula divided by order subtotal per item and could sum to more or
     * less than the original manual_discount_total. Reading the stored
     * per-item ints directly cannot drift, by construction.
     */
    public function test_rounding_across_multiple_items_conserves_total(): void
    {
        $customer = $this->customerFactory->create();
        $products = [];
        foreach ([3333, 3333, 3334] as $price) {
            $products[] = $this->productFactory->withVariantAttributes(['item_price' => $price])->create();
        }

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'subtotal'              => 10000,
            'manual_discount_total' => 334,
            'coupon_discount_total' => 0,
            'total_amount'          => 9666,
        ]);

        $manualShares = [111, 111, 112];
        foreach ($products as $i => $product) {
            $this->itemFactory->create($order->id, [
                'post_id' => $product->ID, 'object_id' => $product->variants->first()->id,
                'unit_price' => [3333, 3333, 3334][$i], 'subtotal' => [3333, 3333, 3334][$i],
                'discount_total' => $manualShares[$i],
            ]);
        }

        $items = $this->buildItems($order, 334);

        $sum = 0;
        foreach ($items as $i => $item) {
            $this->assertSame($manualShares[$i], (int) $item['manual_discount']);
            $this->assertSame(0, (int) $item['coupon_discount']);
            $sum += (int) $item['manual_discount'];
        }
        $this->assertSame(334, $sum, 'Per-item manual discounts must sum exactly to the order manual_discount_total');
    }

    /**
     * End-to-end conservation: the reconstructed cart's discount and payable
     * total must match what the original order actually charged.
     */
    public function test_reconstructed_discount_and_total_match_original_order(): void
    {
        $customer = $this->customerFactory->create();
        $productA = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();
        $productB = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'subtotal'              => 12000,
            'manual_discount_total' => 1000,
            'coupon_discount_total' => 0,
            'total_amount'          => 11000,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id' => $productA->ID, 'object_id' => $productA->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 1000,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => $productB->ID, 'object_id' => $productB->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 0,
        ]);

        $items = $this->buildItems($order, 1000);

        $reconstructedTotal = array_sum(array_map(function ($item) {
            return (int) $item['subtotal'] - (int) $item['discount_total'];
        }, $items));

        $this->assertSame((int) $order->total_amount, $reconstructedTotal);
    }

    /**
     * fee and signup_fee order items must stay excluded from the one-time
     * cart rebuild regardless of the discount-split fix, and renewal orders
     * must keep getting the correct per-item split.
     */
    public function test_renewal_order_excludes_fee_and_signup_fee_items(): void
    {
        $customer = $this->customerFactory->create();
        $productA = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();
        $productB = $this->productFactory->withVariantAttributes(['item_price' => 6000])->create();

        $order = $this->orderFactory->create([
            'customer_id'           => $customer->id,
            'type'                  => Status::ORDER_TYPE_RENEWAL,
            'subtotal'              => 12000,
            'manual_discount_total' => 1000,
            'coupon_discount_total' => 0,
            'total_amount'          => 11000,
        ]);

        $this->itemFactory->create($order->id, [
            'post_id' => $productA->ID, 'object_id' => $productA->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 1000,
            'payment_type' => 'onetime',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => $productB->ID, 'object_id' => $productB->variants->first()->id,
            'unit_price' => 6000, 'subtotal' => 6000, 'discount_total' => 0,
            'payment_type' => 'onetime',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => 0, 'object_id' => 0,
            'unit_price' => 500, 'subtotal' => 500, 'discount_total' => 0,
            'payment_type' => 'fee',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id' => 0, 'object_id' => 0,
            'unit_price' => 200, 'subtotal' => 200, 'discount_total' => 0,
            'payment_type' => 'signup_fee',
        ]);

        $items = $this->buildItems($order, 1000);

        $this->assertCount(2, $items, 'fee and signup_fee items must be excluded from the one-time cart rebuild');
        $this->assertSame(1000, (int) $items[0]['manual_discount']);
        $this->assertSame(0, (int) $items[0]['coupon_discount']);
        $this->assertSame(0, (int) $items[1]['manual_discount']);
        $this->assertSame(0, (int) $items[1]['coupon_discount']);
    }
}
