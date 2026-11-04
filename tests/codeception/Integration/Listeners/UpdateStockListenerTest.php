<?php

namespace Tests\Integration\Listeners;

use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Order\OrderUpdated;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Listeners\UpdateStock;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;

class UpdateStockListenerTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->productFactory = new ProductFactory();
    }

    private function makeStockedProduct(array $variantOverrides = []): array
    {
        $product = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes(array_merge([
                'manage_stock' => 1,
                'total_stock'  => 10,
                'available'    => 10,
                'committed'    => 0,
                'on_hold'      => 0,
                'stock_status' => Helper::IN_STOCK,
            ], $variantOverrides))
            ->create();
        return ['product' => $product, 'variant' => $product->variants->first()];
    }

    private function makeOrderWithItem(int $productId, int $variantId, int $qty, string $shippingStatus = Status::SHIPPING_UNSHIPPED): Order
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => $shippingStatus,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'   => $productId,
            'object_id' => $variantId,
            'quantity'  => $qty,
        ]);
        return Order::query()->with('order_items')->find($order->id);
    }

    // Spec 126: OrderCreated + SHIPPING_UNSHIPPED increments on_hold
    public function test_on_order_created_unshipped_status_increments_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderCreated($order));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(3, (int) $fresh->on_hold);
        $this->assertSame(7, (int) $fresh->available);
    }

    // Spec 127: OrderUpdated removed line item → delete branch decrements on_hold
    public function test_on_order_updated_removed_item_produces_delete(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'on_hold' => 3, 'available' => 7, 'committed' => 0,
        ]);

        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($oldOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 3,
        ]);
        $oldOrder = Order::query()->with('order_items')->find($oldOrder->id);

        // New order: same fixture, but no items (item removed) → diff yields a delete.
        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        UpdateStock::handle(new OrderUpdated($newOrder, $oldOrder));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(0, (int) $fresh->on_hold);
        $this->assertSame(10, (int) $fresh->available);
    }

    // Spec 128: OrderUpdated added line item → add branch increments on_hold and decrements available
    public function test_on_order_updated_added_item_produces_add(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 10, 'on_hold' => 0, 'committed' => 0,
        ]);

        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $oldOrder = Order::query()->with('order_items')->find($oldOrder->id);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 4,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        UpdateStock::handle(new OrderUpdated($newOrder, $oldOrder));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(4, (int) $fresh->on_hold);
        $this->assertSame(6, (int) $fresh->available);
    }

    // Spec 129: OrderUpdated quantity change → edit branch applies delta
    public function test_on_order_updated_qty_increase_produces_edit(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0,
        ]);

        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $oldItem = $this->itemFactory->create($oldOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 3,
        ]);
        $oldOrder = Order::query()->with('order_items')->find($oldOrder->id);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 5,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        UpdateStock::handle(new OrderUpdated($newOrder, $oldOrder));

        $fresh = ProductVariation::query()->find($variant->id);
        // quantity went 3 → 5, delta +2 on on_hold; available drops by 2.
        $this->assertSame(5, (int) $fresh->on_hold);
        $this->assertSame(5, (int) $fresh->available);
    }

    public function test_on_order_updated_qty_decrease_produces_edit(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 5, 'on_hold' => 5, 'committed' => 0,
        ]);

        $customer = $this->customerFactory->create();
        $oldOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($oldOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 5,
        ]);
        $oldOrder = Order::query()->with('order_items')->find($oldOrder->id);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 2,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        UpdateStock::handle(new OrderUpdated($newOrder, $oldOrder));

        $fresh = ProductVariation::query()->find($variant->id);
        // quantity went 5 → 2, delta -3 on on_hold; available rises by 3.
        $this->assertSame(2, (int) $fresh->on_hold);
        $this->assertSame(8, (int) $fresh->available);
    }

    // Spec 130: OrderStatusUpdated with manageStock=false short-circuits
    public function test_on_order_status_updated_with_manage_stock_false_is_noop(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct(['on_hold' => 3, 'available' => 7]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderStatusUpdated($order, 'on-hold', 'processing', false, [], 'payment_status'));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(3, (int) $fresh->on_hold);
        $this->assertSame(7, (int) $fresh->available);
    }

    // Coverage gap (Codex): shipping-state transitions UpdateStock branches.
    public function test_status_transition_unshipped_to_shipped_moves_on_hold_to_committed(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_SHIPPED);

        UpdateStock::handle(new OrderStatusUpdated(
            $order,
            Status::SHIPPING_UNSHIPPED,
            Status::SHIPPING_SHIPPED,
            true,
            [],
            'shipping_status'
        ));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(0, (int) $fresh->on_hold, 'on_hold must drop by qty');
        $this->assertSame(3, (int) $fresh->committed, 'committed must rise by qty');
    }

    public function test_status_transition_shipped_to_unshipped_restores_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 0, 'committed' => 3, 'total_stock' => 10,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderStatusUpdated(
            $order,
            Status::SHIPPING_SHIPPED,
            Status::SHIPPING_UNSHIPPED,
            true,
            [],
            'shipping_status'
        ));

        $fresh = ProductVariation::query()->find($variant->id);
        // Source maps shipped->unshipped to status='restore', action='', so status branch hits 'restore':
        // on_hold += qty, committed -= qty.
        $this->assertSame(3, (int) $fresh->on_hold, 'on_hold must rise back by qty');
        $this->assertSame(0, (int) $fresh->committed, 'committed must drop by qty');
    }

    public function test_status_transition_unshipped_to_canceled_releases_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderStatusUpdated(
            $order,
            Status::SHIPPING_UNSHIPPED,
            Status::ORDER_CANCELED,
            true,
            [],
            'order_status'
        ));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(0, (int) $fresh->on_hold, 'on_hold must release on cancel from unshipped');
        $this->assertSame(10, (int) $fresh->available, 'available must rise back to total_stock');
    }

    public function test_status_transition_shipped_to_canceled_releases_committed(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 0, 'committed' => 3, 'total_stock' => 10,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_SHIPPED);

        UpdateStock::handle(new OrderStatusUpdated(
            $order,
            Status::SHIPPING_SHIPPED,
            Status::ORDER_CANCELED,
            true,
            [],
            'order_status'
        ));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(0, (int) $fresh->committed, 'committed must release on cancel from shipped');
        $this->assertSame(10, (int) $fresh->available, 'available must rise back to total_stock');
    }

    // Spec 131: OrderRefund → refund action restores available
    public function test_on_order_refund_restores_available_and_decrements_committed(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 5, 'committed' => 5, 'on_hold' => 0,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_SHIPPED);
        $refundTxn = $this->txnFactory->create($order->id, [
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'status'           => Status::TRANSACTION_REFUNDED,
            'total'            => Helper::toCent(10),
        ]);

        UpdateStock::handle(new OrderRefund($order, $refundTxn, [], true));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(8, (int) $fresh->available);
        $this->assertSame(2, (int) $fresh->committed);
    }

    // Spec 132: skip variants where manage_stock=0
    public function test_skips_variants_with_manage_stock_zero(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'manage_stock' => 0,
            'total_stock'  => 0,
            'available'    => 0,
        ]);
        $order = $this->makeOrderWithItem($product->ID, $variant->id, 3, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderCreated($order));

        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame(0, (int) $fresh->on_hold);
        $this->assertSame(0, (int) $fresh->available);
    }

    // Spec 133: post-handle, ProductDetail.stock_availability MUST equal IN_STOCK after a UNSHIPPED
    // order with positive variant.available. Asserts the storefront-visible contract directly.
    public function test_refreshes_product_detail_stock_availability_in_stock(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        ProductVariation::query()->where('id', $variant->id)->update([
            'manage_stock' => 1,
            'total_stock'  => 10,
            'available'    => 5,
            'committed'    => 0,
            'on_hold'      => 0,
            'stock_status' => Helper::IN_STOCK,
        ]);
        ProductDetail::query()->where('post_id', $product->ID)->update([
            'manage_stock'       => 1,
            'stock_availability' => Helper::OUT_OF_STOCK,
        ]);
        $variant = ProductVariation::query()->find($variant->id);

        $order = $this->makeOrderWithItem($product->ID, $variant->id, 1, Status::SHIPPING_UNSHIPPED);

        UpdateStock::handle(new OrderCreated($order));

        $freshVariant = ProductVariation::query()->find($variant->id);
        // Listener's variant-level math: available = total_stock - committed - (on_hold + qty)
        $this->assertSame(1, (int) $freshVariant->manage_stock);
        $this->assertSame(9, (int) $freshVariant->available);
        $this->assertSame(1, (int) $freshVariant->on_hold);
        $this->assertSame(Helper::IN_STOCK, $freshVariant->stock_status);

        $fresh = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(Helper::IN_STOCK, $fresh->stock_availability,
            'ProductDetail.stock_availability MUST flip to IN_STOCK after UpdateStock writes a positive available variant');
    }
}
