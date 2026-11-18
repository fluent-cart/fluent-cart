<?php

namespace Tests\Integration\Helpers;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Helpers\AdminOrderProcessor;
use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BuildsOnetimeBundles;

/**
 * One-time bundle orders must save a child line per bundle item, or the
 * customer gets no licenses or downloads for them.
 */
class OnetimeBundleOrderItemsTest extends DatabaseTestCase
{
    use BuildsOnetimeBundles;

    public function test_new_order_saves_children_when_variation_lacks_bundle_flag(): void
    {
        $childIds = $this->createBundleChildVariations();
        $variation = $this->createUnflaggedOnetimeBundle($childIds);
        $customer = (new CustomerFactory())->create();

        $order = (new CheckoutProcessor([$this->bundleCheckoutItem($variation)], [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]))->createDraftOrder();

        $parent = OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first();
        $children = OrderItem::query()->where('order_id', $order->id)->where('payment_type', 'bundle')->get();

        $this->assertNotNull($parent, 'The bundle line is saved.');
        $this->assertEqualsCanonicalizing($childIds, array_map('intval', $children->pluck('object_id')->all()), 'One child line is saved per bundle item.');
        foreach ($children as $child) {
            $this->assertEquals($parent->id, Arr::get($child->line_meta, 'bundle_parent_item_id'), 'Each child points to the bundle line.');
        }
        $this->assertEqualsCanonicalizing($children->pluck('id')->all(), Arr::get($parent->line_meta, 'bundle_item_ids', []), 'The bundle line lists its children.');
    }

    public function test_retried_checkout_saves_children_once(): void
    {
        $childIds = $this->createBundleChildVariations();
        $variation = $this->createUnflaggedOnetimeBundle($childIds);
        $customer = (new CustomerFactory())->create();
        $existingOrder = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $args = ['customer_id' => $customer->id, 'payment_method' => 'offline_payment'];

        (new CheckoutProcessor([$this->bundleCheckoutItem($variation)], $args))->createDraftOrder($existingOrder);
        (new CheckoutProcessor([$this->bundleCheckoutItem($variation)], $args))->createDraftOrder($existingOrder);

        $parent = OrderItem::query()->where('order_id', $existingOrder->id)->where('object_id', $variation->id)->first();
        $children = OrderItem::query()->where('order_id', $existingOrder->id)->where('payment_type', 'bundle')->get();

        $this->assertNotNull($parent, 'The bundle line is saved on the reused order.');
        $this->assertEqualsCanonicalizing($childIds, array_map('intval', $children->pluck('object_id')->all()), 'The reused order has one child line per bundle item.');
        foreach ($children as $child) {
            $this->assertEquals($parent->id, Arr::get($child->line_meta, 'bundle_parent_item_id'), 'Each child points to the bundle line.');
        }
        $this->assertSame(3, OrderItem::query()->where('order_id', $existingOrder->id)->count(), 'A second sync replaces the children instead of adding more.');
    }

    public function test_admin_order_saves_children_when_variation_lacks_bundle_flag(): void
    {
        $childIds = $this->createBundleChildVariations();
        $variation = $this->createUnflaggedOnetimeBundle($childIds);
        $customer = (new CustomerFactory())->create();

        $order = (new AdminOrderProcessor([[
            'post_id'    => $variation->post_id,
            'object_id'  => $variation->id,
            'quantity'   => 1,
            'unit_price' => 5000,
            'other_info' => $variation->other_info,
        ]], [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]))->createDraftOrder();

        $parent = OrderItem::query()->where('order_id', $order->id)->where('object_id', $variation->id)->first();
        $children = OrderItem::query()->where('order_id', $order->id)->where('payment_type', 'bundle')->get();

        $this->assertNotNull($parent, 'The bundle line is saved on the admin order.');
        $this->assertEqualsCanonicalizing($childIds, array_map('intval', $children->pluck('object_id')->all()), 'The admin order has one child line per bundle item.');
        foreach ($children as $child) {
            $this->assertEquals($parent->id, Arr::get($child->line_meta, 'bundle_parent_item_id'), 'Each child points to the bundle line.');
        }
    }

    public function test_onetime_variation_added_to_bundle_saves_children_on_checkout(): void
    {
        $childIds = $this->createBundleChildVariations();
        $bundle = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->withoutVariant()
            ->create(['post_title' => 'Bundle']);
        $customer = (new CustomerFactory())->create();

        // ProductVariationController::store() sends the flag; saveBundleInfo() adds the children later.
        $created = ProductVariationResource::create([
            'post_id'         => $bundle->ID,
            'variation_title' => 'Lifetime',
            'item_price'      => 5000,
            'other_info'      => ['payment_type' => 'onetime', 'is_bundle_product' => 'yes'],
        ]);
        $this->assertNotWPError($created, 'The one-time variation is created.');

        $variation = ProductVariation::query()->find($created['data']->id);
        $otherInfo = $variation->other_info;
        $otherInfo['bundle_child_ids'] = $childIds;
        $variation->other_info = $otherInfo;
        $variation->save();

        $order = (new CheckoutProcessor([$this->bundleCheckoutItem($variation)], [
            'customer_id'    => $customer->id,
            'payment_method' => 'offline_payment',
        ]))->createDraftOrder();

        $children = OrderItem::query()->where('order_id', $order->id)->where('payment_type', 'bundle')->get();

        $this->assertSame('yes', Arr::get($variation->fresh()->other_info, 'is_bundle_product'), 'The one-time variation keeps the bundle flag.');
        $this->assertEqualsCanonicalizing($childIds, array_map('intval', $children->pluck('object_id')->all()), 'Checkout saves one child line per bundle item.');
    }
}
