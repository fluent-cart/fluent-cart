<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\Framework\Database\QueryException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\ProductFactory;

class OrderResourceTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private OrderTransactionFactory $txnFactory;
    private OrderAddressFactory $addressFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->itemFactory = new OrderItemFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->addressFactory = new OrderAddressFactory();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 152-156: OrderResource::create()
    // =========================================================================

    public function test_create_calculates_totals_from_items(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $orderData = [
            'customer_id'    => $customer->id,
            'order_items'    => [
                [
                    'post_id'      => $product->ID,
                    'object_id'    => $variant->id,
                    'unit_price'   => 5000,
                    'quantity'     => 2,
                    'payment_type' => 'onetime',
                    'other_info'   => ['payment_type' => 'onetime'],
                ],
            ],
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'manual_discount_total' => 0,
        ];

        $order = OrderResource::create($orderData);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals(10000, $order->subtotal);
        $this->assertEquals(10000, $order->total_amount);
    }

    public function test_create_sets_paid_when_total_is_zero(): void
    {
        $customer = $this->customerFactory->create();

        $orderData = [
            'customer_id'           => $customer->id,
            'order_items'           => [],
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'manual_discount_total' => 0,
        ];

        $order = OrderResource::create($orderData);

        $this->assertSame(Status::PAYMENT_PAID, $order->payment_status);
    }

    public function test_create_sets_on_hold_status(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $orderData = [
            'customer_id'           => $customer->id,
            'order_items'           => [
                [
                    'post_id'      => $product->ID,
                    'object_id'    => $variant->id,
                    'unit_price'   => 5000,
                    'quantity'     => 1,
                    'payment_type' => 'onetime',
                    'other_info'   => ['payment_type' => 'onetime'],
                ],
            ],
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'manual_discount_total' => 0,
        ];

        $order = OrderResource::create($orderData);
        $this->assertSame(Status::ORDER_ON_HOLD, $order->status);
    }

    public function test_create_determines_physical_fulfillment_type(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $orderData = [
            'customer_id'           => $customer->id,
            'order_items'           => [
                [
                    'post_id'          => $product->ID,
                    'object_id'        => $variant->id,
                    'unit_price'       => 5000,
                    'quantity'         => 1,
                    'payment_type'     => 'onetime',
                    'fulfillment_type' => 'physical',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'manual_discount_total' => 0,
        ];

        $order = OrderResource::create($orderData);
        $this->assertSame('physical', $order->fulfillment_type);
    }

    // =========================================================================
    // Spec 161-164: OrderResource::update()
    // =========================================================================

    public function test_update_rejects_completed_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_COMPLETED,
        ]);
        $this->itemFactory->create($order->id);

        $result = OrderResource::update([
            'orderData'         => ['total_amount' => 9999],
            'deletedItems'      => [],
            'discount'          => '',
            'shipping'          => '',
            'couponCalculation' => [],
        ], $order->id);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_rejects_canceled_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_CANCELED,
        ]);
        $this->itemFactory->create($order->id);

        $result = OrderResource::update([
            'orderData'         => ['total_amount' => 9999],
            'deletedItems'      => [],
            'discount'          => '',
            'shipping'          => '',
            'couponCalculation' => [],
        ], $order->id);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_returns_error_for_nonexistent_order(): void
    {
        $result = OrderResource::update([
            'orderData'    => [],
            'deletedItems' => [],
            'discount'     => '',
            'shipping'     => '',
        ], 999999);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 165-166: OrderResource::view()
    // =========================================================================

    public function test_view_returns_order_with_relations(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id' => $customer->id,
        ]);
        $this->itemFactory->create($order->id);
        $this->txnFactory->create($order->id);

        $data = OrderResource::view($order->id);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('order', $data);
        $this->assertNotEmpty($data['order']);
    }

    public function test_view_returns_wp_error_for_nonexistent_order(): void
    {
        $result = OrderResource::view(999999);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 167-172: OrderResource::updateStatuses()
    // =========================================================================

    public function test_update_statuses_changes_order_status(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        $result = OrderResource::updateStatuses([
            'order'                 => $order,
            'action'                => 'change_order_status',
            'statuses'              => ['order_status' => Status::ORDER_PROCESSING],
            'statuses.order_status' => Status::ORDER_PROCESSING,
            'manage_stock'          => false,
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $fresh = Order::query()->find($order->id);
        $this->assertSame(Status::ORDER_PROCESSING, $fresh->status);
    }

    public function test_update_statuses_rejects_change_from_canceled(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_CANCELED,
        ]);

        $result = OrderResource::updateStatuses([
            'order'                 => $order,
            'action'                => 'change_order_status',
            'statuses'              => ['order_status' => Status::ORDER_PROCESSING],
            'statuses.order_status' => Status::ORDER_PROCESSING,
            'manage_stock'          => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_statuses_rejects_same_status(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $result = OrderResource::updateStatuses([
            'order'                 => $order,
            'action'                => 'change_order_status',
            'statuses'              => ['order_status' => Status::ORDER_PROCESSING],
            'statuses.order_status' => Status::ORDER_PROCESSING,
            'manage_stock'          => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_statuses_rejects_invalid_status(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        $result = OrderResource::updateStatuses([
            'order'                 => $order,
            'action'                => 'change_order_status',
            'statuses'              => ['order_status' => 'invalid_status'],
            'statuses.order_status' => 'invalid_status',
            'manage_stock'          => false,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 173-178: OrderResource::delete()
    // =========================================================================

    public function test_delete_returns_error_when_not_deletable(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_PROCESSING,
        ]);

        $result = OrderResource::delete($order->id);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_delete_cascades_related_data(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        $this->itemFactory->create($order->id);
        $this->txnFactory->create($order->id);
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'test',
            'meta_value' => 'val',
        ]);
        OrderOperation::query()->create([
            'order_id'    => $order->id,
            'created_via' => 'admin',
        ]);

        $orderId = $order->id;
        $result = OrderResource::delete($orderId);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertNull(Order::query()->find($orderId));
        $this->assertSame(0, OrderItem::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, OrderTransaction::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, OrderAddress::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, OrderMeta::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, OrderOperation::query()->where('order_id', $orderId)->count());
    }

    public function test_delete_subscription_order_cascades_children(): void
    {
        $customer = $this->customerFactory->create();
        $parent = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
            'type'           => 'subscription',
        ]);

        $child = $this->orderFactory->create([
            'customer_id' => $customer->id,
            'parent_id'   => $parent->id,
            'type'        => 'renewal',
            'status'      => Status::ORDER_ON_HOLD,
        ]);

        $sub = Subscription::query()->create([
            'customer_id'      => $customer->id,
            'parent_order_id'  => $parent->id,
            'status'           => Status::SUBSCRIPTION_CANCELED,
            'item_name'        => 'Test',
            'recurring_amount' => 1000,
            'billing_interval' => 'monthly',
            'payment_method'   => 'offline_payment',
        ]);

        $parentId = $parent->id;
        $childId = $child->id;
        $subId = $sub->id;

        OrderResource::delete($parentId);

        $this->assertNull(Order::query()->find($parentId));
        $this->assertNull(Order::query()->find($childId));
        $this->assertNull(Subscription::query()->find($subId));
    }

    public function test_delete_dispatches_events(): void
    {
        $deletingFired = false;
        $deletedFired = false;

        add_action('fluent_cart/order_before_delete', function () use (&$deletingFired) {
            $deletingFired = true;
        });
        add_action('fluent_cart/order_deleted', function () use (&$deletedFired) {
            $deletedFired = true;
        });

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create([
            'customer_id'    => $customer->id,
            'status'         => Status::ORDER_CANCELED,
            'payment_status' => Status::PAYMENT_PENDING,
        ]);

        OrderResource::delete($order->id);

        $this->assertTrue($deletingFired, 'fluent_cart/order_deleting should fire before data removal');
        $this->assertTrue($deletedFired, 'fluent_cart/order_deleted should fire after deletion');
    }

    public function test_delete_returns_error_for_nonexistent_order(): void
    {
        $result = OrderResource::delete(999999);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_updated_place_order_lets_a_database_failure_reach_the_caller(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        // The framework raises a QueryException when the database refuses a read.
        $refused = 0;
        $refuseStockLookup = function ($query) use (&$refused) {
            if (preg_match('/^select \* from `[^`]*fct_product_variations` where `id` in/i', $query)) {
                $refused++;
                throw new QueryException($query, [], new \Exception('simulated database failure'));
            }

            return $query;
        };
        add_filter('query', $refuseStockLookup);

        $thrown = null;
        $result = null;
        try {
            $result = OrderResource::updatedPlaceOrder([
                'order_items' => [
                    ['post_id' => (int) $product->ID, 'object_id' => (int) $variant->id, 'quantity' => 1],
                ],
            ]);
        } catch (QueryException $e) {
            $thrown = $e;
        } finally {
            remove_filter('query', $refuseStockLookup);
        }

        $this->assertSame(1, $refused, 'The stock lookup is the query that failed.');
        $this->assertNotNull(
            $thrown,
            'A database failure is not a stock rejection. The resource answered: ' . wp_json_encode($result)
        );
        $this->assertSame(0, Order::query()->count(), 'No order is created.');
    }
}
