<?php

namespace Tests\Integration\Modules\StockManagement;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\StockManagement\StockManagement;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

class StockManagementTest extends WPTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private ProductFactory $productFactory;
    private StockManagement $module;

    private const STOCK_HOOKS = [
        'fluent_cart/order_created',
        'fluent_cart/shipping_status_changed',
        'fluent_cart/order_status_changed',
        'fluent_cart/order_refunded',
        'fluent_cart/order_paid',
        'fluent_cart/order_updated',
        'fluent_cart/variation/can_purchase_bundle',
    ];

    public function setUp(): void
    {
        parent::setUp();
        // fct_* tables are NOT transaction-rolled-back by WPTestCase. Stale rows from
        // prior runs re-use today's post_ids via wp_posts's transactional rollback, so
        // Product->variants eager loads can surface unrelated old variants. Truncate
        // the tables this suite touches.
        global $wpdb;
        foreach ([
            'fct_product_variations',
            'fct_product_details',
            'fct_order_items',
            'fct_order_meta',
            'fct_order_transactions',
            'fct_order_addresses',
            'fct_orders',
        ] as $table) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $table . '`');
        }

        // Strip any StockManagement instance callbacks left attached by earlier tests
        // that called register() to exercise the active-module code path. WP has no
        // reliable matching-removal for instance-bound callbacks across objects, so
        // walk $wp_filter directly.
        $this->stripStockManagementCallbacks();

        // Force fresh read of stock_management option (defeats ModuleSettings' static cache).
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);

        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        $this->itemFactory     = new OrderItemFactory();
        $this->productFactory  = new ProductFactory();
        $this->module          = new StockManagement();
    }

    public function tearDown(): void
    {
        $this->stripStockManagementCallbacks();
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
        parent::tearDown();
    }

    private function stripStockManagementCallbacks(): void
    {
        global $wp_filter;
        foreach (self::STOCK_HOOKS as $hook) {
            if (empty($wp_filter[$hook])) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $id => $callback) {
                    $fn = $callback['function'];
                    if (is_array($fn) && isset($fn[0]) && $fn[0] instanceof StockManagement) {
                        unset($wp_filter[$hook]->callbacks[$priority][$id]);
                        continue;
                    }
                    if ($fn instanceof \Closure) {
                        // bundle filter uses a closure defined inside StockManagement::register
                        $reflection = new \ReflectionFunction($fn);
                        $closureThis = $reflection->getClosureThis();
                        if ($closureThis instanceof StockManagement) {
                            unset($wp_filter[$hook]->callbacks[$priority][$id]);
                        }
                    }
                }
                if (empty($wp_filter[$hook]->callbacks[$priority])) {
                    unset($wp_filter[$hook]->callbacks[$priority]);
                }
            }
        }
    }

    // =========================================================================
    // Helper: build a stocked product + variation
    // =========================================================================

    private function makeStockedProduct(array $variantOverrides = [], array $detailOverrides = []): array
    {
        $product = $this->productFactory
            ->withDetailAttributes(array_merge([
                'manage_stock'       => 1,
                'stock_availability' => Helper::IN_STOCK,
            ], $detailOverrides))
            ->withVariantAttributes(array_merge([
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'total_stock'      => 10,
                'available'        => 10,
                'committed'        => 0,
                'on_hold'          => 0,
                'stock_status'     => Helper::IN_STOCK,
            ], $variantOverrides))
            ->create();

        return ['product' => $product, 'variant' => $product->variants->first()];
    }

    private function makeOrderWithItem(int $customerId, int $postId, int $variantId, int $qty, array $orderOverrides = [], array $itemOverrides = []): Order
    {
        $order = $this->orderFactory->create(array_merge([
            'customer_id'     => $customerId,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ], $orderOverrides));

        $this->itemFactory->create($order->id, array_merge([
            'post_id'   => $postId,
            'object_id' => $variantId,
            'quantity'  => $qty,
        ], $itemOverrides));

        return Order::query()->with('order_items')->find($order->id);
    }

    private function freshVariant(int $id): ProductVariation
    {
        return ProductVariation::query()->find($id);
    }

    private function stockMovement(int $orderId): array
    {
        $row = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->first();

        if (!$row) {
            return [];
        }

        $value = $row->meta_value;
        return is_array($value) ? $value : (json_decode($value, true) ?: []);
    }

    // =========================================================================
    // Spec 1-5: module bootstrap / hook registration
    // =========================================================================

    // Spec 1
    public function test_registers_module_setting_field(): void
    {
        $this->assertNotFalse(
            has_filter('fluent_cart/module_setting/fields'),
            'stock_management field filter should be registered at plugin boot'
        );
        $fields = apply_filters('fluent_cart/module_setting/fields', [], []);
        $this->assertArrayHasKey('stock_management', $fields);
        $this->assertSame('component', $fields['stock_management']['type']);
        $this->assertSame('ModuleSettings', $fields['stock_management']['component']);
    }

    // Spec 2
    public function test_registers_default_value_no(): void
    {
        $this->assertNotFalse(has_filter('fluent_cart/module_setting/default_values'));
        $defaults = apply_filters('fluent_cart/module_setting/default_values', [], []);
        $this->assertSame('no', $defaults['stock_management']['active']);
    }

    // Spec 3
    public function test_registers_shop_query_filter_always(): void
    {
        // shop_query filter is registered unconditionally in register(), before the isActive() gate.
        $this->assertNotFalse(
            has_filter('fluent_cart/shop_query'),
            'shop_query filter should be registered regardless of module active state'
        );
    }

    // Spec 4 — inactive module must NOT attach any stock-mutation handlers
    public function test_does_not_register_order_handlers_when_inactive(): void
    {
        // setUp already cleared option + cache + stripped residue. Confirm inactive state.
        ModuleSettings::getAllSettings(false);
        $this->assertFalse(ModuleSettings::isActive('stock_management'));

        $module = new StockManagement();
        $module->register(fluentCart());

        foreach ([
            'fluent_cart/order_created',
            'fluent_cart/shipping_status_changed',
            'fluent_cart/order_status_changed',
            'fluent_cart/order_refunded',
            'fluent_cart/order_paid',
            'fluent_cart/order_updated',
        ] as $hook) {
            $this->assertFalse(
                $this->hasStockManagementCallback($hook, $module),
                "{$hook} must NOT have StockManagement callback when module inactive"
            );
        }

        $this->assertFalse(
            $this->hasStockManagementCallback('fluent_cart/variation/can_purchase_bundle', $module),
            'bundle purchase filter must not be attached when module inactive'
        );
    }

    // Spec 5 — active module attaches every stock hook + bundle filter
    public function test_registers_order_handlers_when_active(): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);
        $this->assertTrue(ModuleSettings::isActive('stock_management'));

        $module = new StockManagement();
        $module->register(fluentCart());

        foreach ([
            'fluent_cart/order_created'            => 'manageStockOnOrderCreated',
            'fluent_cart/shipping_status_changed'  => 'manageStockOnShippingStatusChanged',
            'fluent_cart/order_status_changed'     => 'manageStockOnOrderStatusChanged',
            'fluent_cart/order_refunded'           => 'manageStockOnOrderRefunded',
            'fluent_cart/order_paid'               => 'manageStockOnOrderPaid',
            'fluent_cart/order_updated'            => 'manageStockOnOrderUpdated',
        ] as $hook => $method) {
            $this->assertTrue(
                $this->hasStockManagementCallback($hook, $module, $method),
                "{$hook} must have StockManagement::{$method} attached when module active"
            );
        }

        $this->assertTrue(
            $this->hasStockManagementCallback('fluent_cart/variation/can_purchase_bundle', $module),
            'bundle purchase filter must be attached when module active'
        );
    }

    private function hasStockManagementCallback(string $hook, StockManagement $module, ?string $method = null): bool
    {
        global $wp_filter;
        if (empty($wp_filter[$hook])) {
            return false;
        }
        foreach ($wp_filter[$hook]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_array($fn) && isset($fn[0]) && $fn[0] === $module) {
                    if ($method === null || $fn[1] === $method) {
                        return true;
                    }
                }
                if ($fn instanceof \Closure) {
                    $closureThis = (new \ReflectionFunction($fn))->getClosureThis();
                    if ($closureThis === $module) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // =========================================================================
    // Spec 6-9: filterShopQuery
    // =========================================================================

    // Spec 6
    public function test_shop_query_without_selected_status_returns_query_unchanged(): void
    {
        $fake = new class {
            public $called = false;
            public function when($cond, $cb) { if ($cond) { $this->called = true; $cb($this); } return $this; }
            public function where($col, $op = null, $val = null) { $this->called = true; return $this; }
            public function whereHas($rel, $cb) { $this->called = true; return $this; }
            public function search(...$args) { $this->called = true; return $this; }
        };

        $result = $this->module->filterShopQuery($fake, ['selected_status' => null]);
        $this->assertSame($fake, $result);
        $this->assertFalse($fake->called, 'No query mutations when selected_status is absent');
    }

    // Spec 7
    public function test_shop_query_with_selected_status_and_active_module_applies_in_stock_filter(): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);

        $inner = new class {
            public $whereHasCalled = false;
            public $whereHasRelation = null;
            public function search(...$args) { return $this; }
            public function whereHas($relation, $cb) {
                $this->whereHasCalled = true;
                $this->whereHasRelation = $relation;
                return $this;
            }
        };
        $fake = new class($inner) {
            public $inner;
            public function __construct($inner) { $this->inner = $inner; }
            public function when($cond, $cb) { if ($cond) { $cb($this); } return $this; }
            public function where(...$args) {
                // Source calls where(Closure) — invoke closure with inner so whereHas/search land there.
                foreach ($args as $arg) {
                    if (is_callable($arg)) { $arg($this->inner); }
                }
                return $this;
            }
        };

        $this->module->filterShopQuery($fake, [
            'selected_status'    => 'visible',
            'status'             => 'visible',
            'allow_out_of_stock' => false,
        ]);

        $this->assertTrue($inner->whereHasCalled, 'whereHas must be invoked when module active + !allow_out_of_stock');
        $this->assertSame('detail', $inner->whereHasRelation);

        // cleanup
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
    }

    // Spec 8
    public function test_shop_query_with_allow_out_of_stock_skips_stock_filter(): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);

        $inner = new class {
            public $whereHasCalled = false;
            public function search(...$args) { return $this; }
            public function whereHas($relation, $cb) { $this->whereHasCalled = true; return $this; }
        };
        $fake = new class($inner) {
            public $inner;
            public function __construct($inner) { $this->inner = $inner; }
            public function when($cond, $cb) { if ($cond) { $cb($this); } return $this; }
            public function where(...$args) {
                foreach ($args as $arg) { if (is_callable($arg)) { $arg($this->inner); } }
                return $this;
            }
        };

        $this->module->filterShopQuery($fake, [
            'selected_status'    => 'visible',
            'status'             => 'visible',
            'allow_out_of_stock' => true,
        ]);

        $this->assertFalse($inner->whereHasCalled);

        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
    }

    // Spec 9
    public function test_shop_query_with_module_inactive_skips_stock_filter(): void
    {
        // Fresh static cache reset ensures module reads current (=inactive) state
        ModuleSettings::getAllSettings(false);

        $inner = new class {
            public $whereHasCalled = false;
            public function search(...$args) { return $this; }
            public function whereHas($relation, $cb) { $this->whereHasCalled = true; return $this; }
        };
        $fake = new class($inner) {
            public $inner;
            public function __construct($inner) { $this->inner = $inner; }
            public function when($cond, $cb) { if ($cond) { $cb($this); } return $this; }
            public function where(...$args) {
                foreach ($args as $arg) { if (is_callable($arg)) { $arg($this->inner); } }
                return $this;
            }
        };

        $this->module->filterShopQuery($fake, [
            'selected_status'    => 'visible',
            'status'             => 'visible',
            'allow_out_of_stock' => false,
        ]);

        $this->assertFalse(
            $inner->whereHasCalled,
            'Inactive module must not inject stock_availability whereHas even when allow_out_of_stock=false'
        );
    }

    // =========================================================================
    // Spec 10-14: bundle purchase filter (fluent_cart/variation/can_purchase_bundle)
    //
    // Handler is attached only when module is active. We invoke it via direct
    // apply_filters AFTER enabling + reinstantiating, then restore.
    // =========================================================================

    private function withActiveBundleFilter(callable $test): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);

        $module = new StockManagement();
        $module->register(fluentCart());

        try {
            $test();
        } finally {
            remove_all_filters('fluent_cart/variation/can_purchase_bundle');
            delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
            ModuleSettings::getAllSettings(false);
        }
    }

    // Spec 10
    public function test_bundle_filter_passes_through_for_non_bundle_variation(): void
    {
        $this->withActiveBundleFilter(function () {
            $product = $this->productFactory->create();
            $variant = $product->variants->first();
            $result = apply_filters('fluent_cart/variation/can_purchase_bundle', true, [
                'variation' => $variant,
                'quantity'  => 1,
            ]);
            $this->assertTrue($result);
        });
    }

    // Spec 12
    public function test_bundle_filter_returns_error_when_child_stock_insufficient(): void
    {
        $this->withActiveBundleFilter(function () {
            $child = $this->productFactory
                ->withDetailAttributes(['manage_stock' => 1])
                ->withVariantAttributes([
                    'manage_stock' => 1,
                    'available'    => 1,
                    'total_stock'  => 1,
                    'stock_status' => Helper::IN_STOCK,
                ])
                ->create();
            $childId = $child->variants->first()->id;

            $bundle = $this->productFactory->createBundle([$childId]);
            $bundleVariant = ProductVariation::query()
                ->where('post_id', $bundle->ID)->first();

            $result = apply_filters('fluent_cart/variation/can_purchase_bundle', true, [
                'variation' => $bundleVariant,
                'quantity'  => 5,
            ]);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('insufficient_stock', $result->get_error_code());
        });
    }

    // Spec 13
    public function test_bundle_filter_skips_child_without_manage_stock(): void
    {
        $this->withActiveBundleFilter(function () {
            $child = $this->productFactory
                ->withDetailAttributes(['manage_stock' => 1])
                ->withVariantAttributes([
                    'manage_stock' => 0, // child variant does not manage stock
                    'available'    => 0,
                    'stock_status' => Helper::IN_STOCK,
                ])
                ->create();
            $childId = $child->variants->first()->id;

            $bundle = $this->productFactory->createBundle([$childId]);
            $bundleVariant = ProductVariation::query()
                ->where('post_id', $bundle->ID)->first();

            $result = apply_filters('fluent_cart/variation/can_purchase_bundle', true, [
                'variation' => $bundleVariant,
                'quantity'  => 5,
            ]);
            $this->assertTrue($result, 'Manage_stock=0 child should not block purchase');
        });
    }

    // Spec 14
    public function test_bundle_filter_returns_true_when_all_children_pass(): void
    {
        $this->withActiveBundleFilter(function () {
            $child = $this->productFactory
                ->withDetailAttributes(['manage_stock' => 1])
                ->withVariantAttributes([
                    'manage_stock' => 1,
                    'available'    => 10,
                    'stock_status' => Helper::IN_STOCK,
                ])
                ->create();
            $childId = $child->variants->first()->id;

            $bundle = $this->productFactory->createBundle([$childId]);
            $bundleVariant = ProductVariation::query()
                ->where('post_id', $bundle->ID)->first();

            $result = apply_filters('fluent_cart/variation/can_purchase_bundle', true, [
                'variation' => $bundleVariant,
                'quantity'  => 3,
            ]);
            $this->assertTrue($result);
        });
    }

    // =========================================================================
    // Spec 15-26: manageStockOnOrderCreated
    // =========================================================================

    // Spec 15
    public function test_on_order_created_retry_with_no_earlier_hold_reserves_the_order(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 2);

        $this->module->manageStockOnOrderCreated([
            'order'      => $order,
            'prev_order' => $order, // a retry whose earlier attempt held nothing
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(8, $fresh->available, 'retry with no earlier hold → the 2 ordered units leave the shelf');
        $this->assertSame(2, $fresh->on_hold);
    }

    // Spec 16
    public function test_on_order_created_no_op_when_order_items_empty(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order = Order::query()->with('order_items')->find($order->id);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $this->assertSame(0, OrderMeta::query()->where('order_id', $order->id)->count());
    }

    // Spec 17
    public function test_on_order_created_filters_signup_fee_and_fee_items(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'quantity'     => 2,
            'payment_type' => 'signup_fee',
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'quantity'     => 3,
            'payment_type' => 'fee',
        ]);
        $order = Order::query()->with('order_items')->find($order->id);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(10, $fresh->available, 'signup_fee/fee items must not touch stock');
        $this->assertSame(0, $fresh->on_hold);
    }

    // Spec 18
    public function test_on_order_created_skips_variations_with_manage_stock_zero(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct(['manage_stock' => 0]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(10, $fresh->available);
        $this->assertSame(0, $fresh->on_hold);
    }

    // Spec 19
    public function test_on_order_created_deducts_available_and_adds_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(6, $fresh->available);
        $this->assertSame(4, $fresh->on_hold);
        $this->assertSame(Helper::IN_STOCK, $fresh->stock_status);
    }

    // canPurchase() is the primary over-reservation guard — oversell should never
    // reach the handler. Validate that contract explicitly.
    public function test_can_purchase_rejects_oversell_before_handler_runs(): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);

        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct(['available' => 3, 'total_stock' => 3]);
        $fresh = ProductVariation::query()->find($variant->id);

        $result = $fresh->canPurchase(5);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('insufficient_stock', $result->get_error_code());
    }

    // Spec 20
    public function test_on_order_created_skips_zero_quantity_items(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 0);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(10, $fresh->available);
        $this->assertSame(0, $fresh->on_hold);
    }

    // Spec 22
    public function test_on_order_created_propagates_to_bundle_children(): void
    {
        $child = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 10,
                'total_stock'  => 10,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $childVariantId = $child->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childVariantId]);
        ProductDetail::query()->where('post_id', $bundle->ID)->update(['manage_stock' => 1]);
        ProductVariation::query()->where('post_id', $bundle->ID)->update([
            'manage_stock' => 1,
            'total_stock'  => 5,
            'available'    => 5,
            'stock_status' => Helper::IN_STOCK,
        ]);
        $bundleVariant = ProductVariation::query()->where('post_id', $bundle->ID)->first();

        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $bundle->ID, $bundleVariant->id, 2);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $freshBundle = $this->freshVariant($bundleVariant->id);
        $freshChild  = $this->freshVariant($childVariantId);

        $this->assertSame(3, $freshBundle->available, 'Bundle parent available deducted by qty');
        $this->assertSame(2, $freshBundle->on_hold);
        $this->assertSame(8, $freshChild->available, 'Child variant available deducted by same qty');
        $this->assertSame(2, $freshChild->on_hold);
    }

    // Spec 23
    public function test_on_order_created_skips_bundle_child_without_manage_stock(): void
    {
        $child = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock' => 0,
                'available'    => 10,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $childVariantId = $child->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childVariantId]);
        ProductDetail::query()->where('post_id', $bundle->ID)->update(['manage_stock' => 1]);
        ProductVariation::query()->where('post_id', $bundle->ID)->update([
            'manage_stock' => 1,
            'total_stock'  => 5,
            'available'    => 5,
            'stock_status' => Helper::IN_STOCK,
        ]);
        $bundleVariant = ProductVariation::query()->where('post_id', $bundle->ID)->first();

        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $bundle->ID, $bundleVariant->id, 2);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $freshChild = $this->freshVariant($childVariantId);
        $this->assertSame(10, $freshChild->available, 'Unmanaged child must stay untouched');
    }

    // Spec 24
    public function test_on_order_created_writes_stock_movement_meta(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4);
        $orderItemId = $order->order_items->first()->id;

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $movement = $this->stockMovement($order->id);
        $this->assertArrayHasKey($orderItemId, $movement);
        $this->assertSame(4, (int) $movement[$orderItemId]['on_hold']);
    }

    // Spec 26
    public function test_on_order_created_recomputes_product_detail_stock_availability(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct(
            ['available' => 3, 'total_stock' => 3],
            ['stock_availability' => Helper::IN_STOCK]
        );
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 5);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('out-of-stock', $detail->stock_availability);
    }

    // =========================================================================
    // Spec 27-32: manageStockOnShippingStatusChanged
    // =========================================================================

    // Spec 27 + 28
    public function test_shipping_status_delivered_moves_on_hold_to_committed(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_UNSHIPPED,
            'new_status' => Status::SHIPPING_DELIVERED,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(0, $fresh->on_hold);
        $this->assertSame(3, $fresh->committed);

        $movement = $this->stockMovement($order->id);
        $itemId = $order->order_items->first()->id;
        $this->assertSame(3, (int) $movement[$itemId]['committed']);
        $this->assertSame(0, (int) $movement[$itemId]['on_hold']);
    }

    // Spec 29
    public function test_shipping_status_away_from_delivered_reverts_committed_to_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 0, 'committed' => 3, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_DELIVERED,
            'new_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(3, $fresh->on_hold);
        $this->assertSame(0, $fresh->committed);

        $movement = $this->stockMovement($order->id);
        $itemId = $order->order_items->first()->id;
        $this->assertSame(0, (int) $movement[$itemId]['committed']);
        $this->assertSame(3, (int) $movement[$itemId]['on_hold']);
    }

    public function test_shipping_status_moves_only_the_units_left_after_a_refund(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 8, 'on_hold' => 1, 'committed' => 0, 'total_stock' => 9,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 2, [], [
            'line_meta' => ['refunded_quantity' => 1],
        ]);
        $itemId = $order->order_items->first()->id;

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_UNSHIPPED,
            'new_status' => Status::SHIPPING_DELIVERED,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(0, $fresh->on_hold);
        $this->assertSame(1, $fresh->committed);
        $this->assertSame(1, (int) $this->stockMovement($order->id)[$itemId]['committed']);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_DELIVERED,
            'new_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(1, $fresh->on_hold);
        $this->assertSame(0, $fresh->committed);
    }

    public function test_refunding_the_unshipped_units_that_completed_a_delivery_releases_their_hold(): void
    {
        // 4 ordered, 2 shipped and delivered, all 4 still on hold (order is partially shipped).
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 6, 'on_hold' => 4, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4, [
            'shipping_status' => Status::SHIPPING_PARTIALLY_SHIPPED,
        ], [
            'fulfilled_quantity' => 2,
            'line_meta'          => ['refunded_quantity' => 2],
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 4, 'committed' => 0]],
        ]);

        // The refund of the 2 unshipped units makes the order delivered before the restock runs.
        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_PARTIALLY_SHIPPED,
            'new_status' => Status::SHIPPING_DELIVERED,
        ]);
        $order->shipping_status = Status::SHIPPING_DELIVERED;

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [[
                'id' => $itemId, 'variation_id' => $variant->id, 'restore_quantity' => 2,
                'restock_type' => 'return', 'shipped_qty' => 0,
            ]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(8, $fresh->available, 'the 2 unshipped units return to the shelf');
        $this->assertSame(0, $fresh->on_hold, 'no unit may stay on hold: the order has nothing left to ship');
        $this->assertSame(2, $fresh->committed, 'the 2 delivered units stay committed');
        $movement = $this->stockMovement($order->id);
        $this->assertSame(0, (int) $movement[$itemId]['on_hold']);
        $this->assertSame(2, (int) $movement[$itemId]['committed']);
    }

    public function test_refund_return_takes_shipped_units_from_committed_and_the_rest_from_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 6, 'on_hold' => 2, 'committed' => 2, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4, [
            'shipping_status' => Status::SHIPPING_PARTIALLY_SHIPPED,
        ], ['fulfilled_quantity' => 2]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 2, 'committed' => 2]],
        ]);

        // 3 refunded: 1 had shipped, 2 never shipped.
        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [[
                'id' => $itemId, 'variation_id' => $variant->id, 'restore_quantity' => 3,
                'restock_type' => 'return', 'shipped_qty' => 1,
            ]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(9, $fresh->available);
        $this->assertSame(0, $fresh->on_hold, 'the 2 unshipped units come off on hold');
        $this->assertSame(1, $fresh->committed, 'only the 1 shipped unit comes off committed');
    }

    // Spec 30
    public function test_shipping_status_pending_to_shipped_does_not_mutate_stock(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => 'pending',
            'new_status' => 'shipped',
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(3, $fresh->on_hold, 'Non-delivered ↔ non-delivered transition must not move stock');
        $this->assertSame(0, $fresh->committed);
    }

    // Spec 31
    public function test_shipping_status_delivered_propagates_to_bundle_children(): void
    {
        $child = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'available'        => 7,
                'total_stock'      => 10,
                'on_hold'          => 3,
                'committed'        => 0,
                'stock_status'     => Helper::IN_STOCK,
            ])
            ->create();
        $childVariantId = $child->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childVariantId]);
        ProductDetail::query()->where('post_id', $bundle->ID)->update(['manage_stock' => 1]);
        ProductVariation::query()->where('post_id', $bundle->ID)->update([
            'manage_stock'     => 1,
            'fulfillment_type' => 'physical',
            'available'        => 5, 'total_stock' => 5,
            'on_hold'          => 3, 'committed' => 0,
            'stock_status'     => Helper::IN_STOCK,
        ]);
        $bundleVariant = ProductVariation::query()->where('post_id', $bundle->ID)->first();

        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $bundle->ID, $bundleVariant->id, 3);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_UNSHIPPED,
            'new_status' => Status::SHIPPING_DELIVERED,
        ]);

        $freshChild = $this->freshVariant($childVariantId);
        $this->assertSame(0, $freshChild->on_hold);
        $this->assertSame(3, $freshChild->committed);
    }

    // Spec 32 — combined with 27 already skips signup_fee, verify explicitly here
    public function test_shipping_status_filters_signup_fee_items(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'quantity'     => 3,
            'payment_type' => 'signup_fee',
        ]);
        $order = Order::query()->with('order_items')->find($order->id);

        $this->module->manageStockOnShippingStatusChanged([
            'order'      => $order,
            'old_status' => Status::SHIPPING_UNSHIPPED,
            'new_status' => Status::SHIPPING_DELIVERED,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(3, $fresh->on_hold, 'signup_fee excluded from delivered shift');
        $this->assertSame(0, $fresh->committed);
    }

    // =========================================================================
    // Spec 33-41: manageStockOnOrderStatusChanged (cancel)
    // =========================================================================

    // Spec 33
    public function test_order_status_non_canceled_is_noop(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'completed',
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(7, $fresh->available);
        $this->assertSame(3, $fresh->on_hold);
    }

    public function test_order_canceled_after_a_refund_restocks_only_the_units_left(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 9, 'on_hold' => 1, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 2, [], [
            'line_meta' => ['refunded_quantity' => 1],
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 1, 'committed' => 0]],
        ]);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'canceled',
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(10, $fresh->available);
        $this->assertSame(0, $fresh->on_hold);
        $this->assertSame(0, (int) $this->stockMovement($order->id)[$itemId]['on_hold']);
    }

    // Spec 37 + 38 — cancel from non-delivered → on_hold drained first (two-bucket fixture
    // forces a partial drain; swapping the priority would land in different buckets).
    public function test_order_canceled_from_unshipped_drains_on_hold_first(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 6, 'on_hold' => 2, 'committed' => 2, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $itemId = $order->order_items->first()->id;
        // Seed ledger with the same 2/2 split so the handler has something to drain from each bucket.
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 2, 'committed' => 2]],
        ]);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'canceled',
        ]);

        $fresh = $this->freshVariant($variant->id);
        // Non-delivered priority: drain 2 on_hold (exhausts bucket), then 1 committed. If priority
        // swapped to committed-first we'd land at on_hold=1, committed=0.
        $this->assertSame(9, $fresh->available, 'available += cancelled qty (6+3)');
        $this->assertSame(0, $fresh->on_hold, 'on_hold drained to zero first (2 removed)');
        $this->assertSame(1, $fresh->committed, 'committed drained for remainder (1 of 3)');

        $movement = $this->stockMovement($order->id);
        $this->assertSame(0, (int) $movement[$itemId]['on_hold']);
        $this->assertSame(1, (int) $movement[$itemId]['committed']);
    }

    // Spec 37 — cancel while delivered → committed drained first. Partial drain exercises
    // the full bucket-priority path.
    public function test_order_canceled_from_delivered_drains_committed_first(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 6, 'on_hold' => 2, 'committed' => 2, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_DELIVERED,
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 2, 'committed' => 2]],
        ]);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'canceled',
        ]);

        $fresh = $this->freshVariant($variant->id);
        // Delivered priority: drain 2 committed first, then 1 on_hold. Swap-priority outcome
        // would be committed=1, on_hold=0.
        $this->assertSame(9, $fresh->available);
        $this->assertSame(0, $fresh->committed, 'committed drained to zero first');
        $this->assertSame(1, $fresh->on_hold, 'on_hold absorbs the remainder');

        $movement = $this->stockMovement($order->id);
        $this->assertSame(0, (int) $movement[$itemId]['committed']);
        $this->assertSame(1, (int) $movement[$itemId]['on_hold']);
    }

    // Spec 36 / 41
    public function test_order_canceled_recomputes_product_detail_stock_availability(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 0, 'on_hold' => 3, 'total_stock' => 3, 'stock_status' => 'out-of-stock',
        ]);
        ProductDetail::query()->where('post_id', $product->ID)->update(['stock_availability' => 'out-of-stock']);

        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 3]],
        ]);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'canceled',
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('in-stock', $detail->stock_availability,
            'After cancel restores stock, ProductDetail.stock_availability must flip to in-stock');
    }

    // =========================================================================
    // Spec 42-50: manageStockOnOrderRefunded
    // =========================================================================

    // Spec 42
    public function test_refund_skips_when_manage_stock_flag_false(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);
        $itemId = $order->order_items->first()->id;

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => false,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 3, 'id' => $itemId]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(7, $fresh->available);
        $this->assertSame(3, $fresh->on_hold);
    }

    // Spec 43 + 45 + 46 — refund on delivered/paid order drains committed first, falls
    // through to on_hold for remainder. Two-bucket partial-drain fixture.
    public function test_refund_restocks_available_with_committed_priority(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 3, 'committed' => 5, 'on_hold' => 2, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 7, [
            'shipping_status' => Status::SHIPPING_DELIVERED,
            'payment_status'  => Status::PAYMENT_PAID,
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['committed' => 5, 'on_hold' => 2]],
        ]);

        // Restore 6: committed first (drains 5 → 0), on_hold absorbs remainder (2 → 1).
        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 6, 'id' => $itemId]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(9, $fresh->available, 'available += restore_quantity (3+6)');
        $this->assertSame(0, $fresh->committed, 'committed drained first (5 removed)');
        $this->assertSame(1, $fresh->on_hold, 'on_hold absorbs remainder (2-1=1)');

        $movement = $this->stockMovement($order->id);
        $this->assertSame(0, (int) $movement[$itemId]['committed']);
        $this->assertSame(1, (int) $movement[$itemId]['on_hold']);
    }

    // Mirror for non-delivered path: on_hold drained first, committed absorbs remainder.
    public function test_refund_unshipped_drains_on_hold_first(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 3, 'committed' => 2, 'on_hold' => 5, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 7, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
            'payment_status'  => Status::PAYMENT_PENDING,
        ]);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 5, 'committed' => 2]],
        ]);

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 6, 'id' => $itemId]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(9, $fresh->available);
        $this->assertSame(0, $fresh->on_hold, 'on_hold drained first');
        $this->assertSame(1, $fresh->committed, 'committed absorbs remainder');
    }

    public function test_refund_restores_no_more_than_the_order_holds(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 99, 'on_hold' => 1, 'total_stock' => 100,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 1, 'committed' => 0]],
        ]);

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 4, 'id' => $itemId]],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(100, $fresh->available, 'only the 1 held unit may return, not the 4 asked for');
        $this->assertSame(0, $fresh->on_hold);
    }

    public function test_cancel_restores_no_more_than_the_order_holds(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 99, 'on_hold' => 1, 'total_stock' => 100,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 4);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 1, 'committed' => 0]],
        ]);

        $this->module->manageStockOnOrderStatusChanged([
            'order'      => $order,
            'new_status' => 'canceled',
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(100, $fresh->available, 'only the 1 held unit may return, not the 4 ordered');
        $this->assertSame(0, $fresh->on_hold);
    }

    public function test_refund_signup_fee_row_does_not_cancel_its_parent_restock(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 9, 'on_hold' => 1, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 1);
        $itemId = $order->order_items->first()->id;
        $feeItem = $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'quantity'     => 1,
            'payment_type' => 'signup_fee',
        ]);
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 1]],
        ]);

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [
                ['variation_id' => $variant->id, 'restore_quantity' => 1, 'id' => $itemId, 'restock_type' => 'cancel'],
                ['variation_id' => $variant->id, 'restore_quantity' => 0, 'id' => $feeItem->id, 'restock_type' => 'no_restock'],
            ],
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(10, $fresh->available);
        $this->assertSame(0, $fresh->on_hold);
    }

    // Spec 48
    public function test_refund_deletes_order_items_fully_restored(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 5, 'on_hold' => 5, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 3]],
        ]);

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 3, 'id' => $itemId]],
        ]);

        $this->assertNull(OrderItem::query()->find($itemId), 'Fully-refunded item must be deleted');
    }

    // Spec 49
    public function test_refund_updates_order_item_quantity_for_partial(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 5, 'on_hold' => 5, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 5);
        $itemId = $order->order_items->first()->id;
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$itemId => ['on_hold' => 5]],
        ]);

        $this->module->manageStockOnOrderRefunded([
            'order'              => $order,
            'manage_stock'       => true,
            'new_refunded_items' => [['variation_id' => $variant->id, 'restore_quantity' => 2, 'id' => $itemId]],
        ]);

        $fresh = OrderItem::query()->find($itemId);
        $this->assertNotNull($fresh);
        $this->assertSame(3, (int) $fresh->quantity, 'Item qty must drop by refund qty');
    }

    // =========================================================================
    // Spec 51-56: manageStockOnOrderPaid
    // =========================================================================

    // Spec 51 + 52 + 53
    public function test_order_paid_moves_on_hold_to_committed_for_digital(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'fulfillment_type' => 'digital',
            'available'        => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderPaid(['order' => $order]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(0, $fresh->on_hold);
        $this->assertSame(3, $fresh->committed);

        $movement = $this->stockMovement($order->id);
        $itemId = $order->order_items->first()->id;
        $this->assertSame(3, (int) $movement[$itemId]['committed']);
        $this->assertSame(0, (int) $movement[$itemId]['on_hold']);
    }

    // Spec 51 — physical variant skipped
    public function test_order_paid_skips_physical_variants(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'fulfillment_type' => 'physical',
            'available'        => 7, 'on_hold' => 3, 'committed' => 0,
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderPaid(['order' => $order]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(3, $fresh->on_hold, 'physical variants wait for shipping; paid must not move stock');
        $this->assertSame(0, $fresh->committed);
    }

    // Spec 55
    public function test_order_paid_no_op_when_no_managed_digital_items(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'manage_stock'     => 0,
            'fulfillment_type' => 'digital',
        ]);
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderPaid(['order' => $order]);

        $this->assertSame(0, OrderMeta::query()->where('order_id', $order->id)->count());
    }

    // =========================================================================
    // Spec 57-66: manageStockOnOrderUpdated
    // =========================================================================

    // Spec 57 + 60 + 61
    public function test_order_updated_diff_unshipped_deducts_from_on_hold(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();

        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);

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

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(5, $fresh->available, 'available = 7 - (5-3)');
        $this->assertSame(5, $fresh->on_hold, 'on_hold = 3 + (5-3)');
    }

    // Spec 58
    public function test_order_updated_zero_diff_skips_variation(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0,
        ]);
        $customer = $this->customerFactory->create();
        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 3,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(7, $fresh->available);
        $this->assertSame(3, $fresh->on_hold);
    }

    // Spec 61 — delivered → committed column affected
    public function test_order_updated_delivered_order_affects_committed_column(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 0, 'committed' => 3, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();

        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_DELIVERED,
        ]);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_DELIVERED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 5,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(5, $fresh->available);
        $this->assertSame(0, $fresh->on_hold);
        $this->assertSame(5, $fresh->committed, 'committed bumped by +2 diff');
    }

    // Spec 59
    public function test_order_updated_skips_variant_with_manage_stock_zero(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'manage_stock' => 0,
            'available'    => 7, 'on_hold' => 3,
        ]);
        $customer = $this->customerFactory->create();
        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

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

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $fresh = $this->freshVariant($variant->id);
        $this->assertSame(7, $fresh->available);
        $this->assertSame(3, $fresh->on_hold);
    }

    // Spec 122 — mixed managed + unmanaged variations
    public function test_mixed_managed_variants_only_touches_managed(): void
    {
        $managedProduct = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 10,
                'total_stock'  => 10,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $unmanagedProduct = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 0])
            ->withVariantAttributes([
                'manage_stock' => 0,
                'available'    => 5,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();

        $managedVariant   = $managedProduct->variants->first();
        $unmanagedVariant = $unmanagedProduct->variants->first();

        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_id'   => $managedProduct->ID,
            'object_id' => $managedVariant->id,
            'quantity'  => 2,
        ]);
        $this->itemFactory->create($order->id, [
            'post_id'   => $unmanagedProduct->ID,
            'object_id' => $unmanagedVariant->id,
            'quantity'  => 3,
        ]);
        $order = Order::query()->with('order_items')->find($order->id);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $freshManaged   = $this->freshVariant($managedVariant->id);
        $freshUnmanaged = $this->freshVariant($unmanagedVariant->id);
        $this->assertSame(8, $freshManaged->available);
        $this->assertSame(5, $freshUnmanaged->available, 'Unmanaged variation untouched');
    }

    // Spec 124 — order with only fee/signup_fee items does not persist OrderMeta
    public function test_order_created_with_only_fee_items_writes_no_meta(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->itemFactory->create($order->id, [
            'post_id'      => $product->ID,
            'object_id'    => $variant->id,
            'quantity'     => 2,
            'payment_type' => 'signup_fee',
        ]);
        $order = Order::query()->with('order_items')->find($order->id);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $this->assertSame(0, OrderMeta::query()->where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')->count());
    }

    // Spec 127 — stock_availability recomputes 'in-stock' when at least one sibling is in-stock
    public function test_stock_availability_recompute_selects_in_stock_if_any_sibling_in_stock(): void
    {
        $product = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'available'    => 3, 'total_stock' => 3,
                'stock_status' => Helper::IN_STOCK,
            ])
            ->create();
        $variant = $product->variants->first();

        // Create a sibling variant, also in-stock. Order targets $variant with qty=3 (drains it).
        ProductVariation::query()->create([
            'post_id'          => $product->ID,
            'variation_title'  => 'Sibling',
            'serial_index'     => 1,
            'payment_type'     => 'onetime',
            'fulfillment_type' => 'physical',
            'item_status'      => 'active',
            'item_price'       => Helper::toCent(5),
            'compare_price'    => 0,
            'item_cost'        => 0,
            'manage_stock'     => 1,
            'stock_status'     => Helper::IN_STOCK,
            'total_stock'      => 10,
            'available'        => 10,
            'committed'        => 0,
            'on_hold'          => 0,
            'backorders'       => 0,
            'other_info'       => ['payment_type' => 'onetime'],
        ]);

        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3);

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('in-stock', $detail->stock_availability,
            'Sibling variant still in-stock → detail stays in-stock');
    }

    // =========================================================================
    // Spec 119: OrderMeta stock_movement persistence format
    // =========================================================================

    public function test_stock_movement_meta_is_keyed_by_order_id_and_meta_key(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        $customer = $this->customerFactory->create();
        $order = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 2);
        $itemId = $order->order_items->first()->id;

        $this->module->manageStockOnOrderCreated(['order' => $order, 'prev_order' => null]);

        $row = OrderMeta::query()
            ->where('order_id', $order->id)
            ->where('meta_key', 'stock_movement')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame($order->id, (int) $row->order_id);

        $decoded = is_array($row->meta_value) ? $row->meta_value : json_decode($row->meta_value, true);
        $this->assertArrayHasKey($itemId, $decoded);
    }

    // =========================================================================
    // Spec 62: order-updated persists stock_movement ledger
    // =========================================================================

    public function test_order_updated_writes_stock_movement_ledger(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 7, 'on_hold' => 3, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();

        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 3, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $newItem = $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 5,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $movement = $this->stockMovement($newOrder->id);
        $this->assertArrayHasKey($newItem->id, $movement);
        // diff = +2; handler clamps at 0 via max() but accumulates. Starting movement empty,
        // ends at 2 for unshipped path.
        $this->assertSame(2, (int) $movement[$newItem->id]['on_hold']);
    }

    // =========================================================================
    // Spec 64: order-updated removed item (old has it, new doesn't) restocks available
    // =========================================================================

    public function test_order_updated_removed_item_restocks_available(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 5, 'on_hold' => 5, 'committed' => 0, 'total_stock' => 10,
        ]);
        $customer = $this->customerFactory->create();

        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 5, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $fresh = $this->freshVariant($variant->id);
        // diff = 0 - 5 = -5. available -= (-5) → available+=5. on_hold column: -5 → 0.
        $this->assertSame(10, $fresh->available);
        $this->assertSame(0, $fresh->on_hold, 'Removed item releases its on_hold reservation');
    }

    // =========================================================================
    // Spec 63: order-updated mirrors diff on bundle children
    // =========================================================================

    public function test_order_updated_propagates_diff_to_bundle_children(): void
    {
        $child = $this->productFactory
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'available'        => 7, 'total_stock' => 10,
                'on_hold'          => 3, 'committed' => 0,
                'stock_status'     => Helper::IN_STOCK,
            ])
            ->create();
        $childVariantId = $child->variants->first()->id;

        $bundle = $this->productFactory->createBundle([$childVariantId]);
        ProductDetail::query()->where('post_id', $bundle->ID)->update(['manage_stock' => 1]);
        ProductVariation::query()->where('post_id', $bundle->ID)->update([
            'manage_stock'     => 1,
            'fulfillment_type' => 'physical',
            'available'        => 5, 'total_stock' => 5,
            'on_hold'          => 3, 'committed' => 0,
            'stock_status'     => Helper::IN_STOCK,
        ]);
        $bundleVariant = ProductVariation::query()->where('post_id', $bundle->ID)->first();

        $customer = $this->customerFactory->create();
        $oldOrder = $this->makeOrderWithItem($customer->id, $bundle->ID, $bundleVariant->id, 3, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $bundle->ID,
            'object_id' => $bundleVariant->id,
            'quantity'  => 5,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $freshChild = $this->freshVariant($childVariantId);
        // diff = +2 on bundle parent → child deducts 2 from available, adds 2 to on_hold
        $this->assertSame(5, $freshChild->available, 'child available 7 - diff(2)');
        $this->assertSame(5, $freshChild->on_hold, 'child on_hold 3 + diff(2)');
    }

    // =========================================================================
    // Spec 66: order-updated recomputes ProductDetail.stock_availability
    // =========================================================================

    public function test_order_updated_recomputes_product_detail_stock_availability(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 3, 'total_stock' => 3, 'on_hold' => 0, 'committed' => 0,
        ]);
        $customer = $this->customerFactory->create();

        $oldOrder = $this->makeOrderWithItem($customer->id, $product->ID, $variant->id, 0, [
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);

        $newOrder = $this->orderFactory->create([
            'customer_id'     => $customer->id,
            'shipping_status' => Status::SHIPPING_UNSHIPPED,
        ]);
        $this->itemFactory->create($newOrder->id, [
            'post_id'   => $product->ID,
            'object_id' => $variant->id,
            'quantity'  => 3,
        ]);
        $newOrder = Order::query()->with('order_items')->find($newOrder->id);

        $this->module->manageStockOnOrderUpdated([
            'order'     => $newOrder,
            'old_order' => $oldOrder,
        ]);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        $this->assertSame('out-of-stock', $detail->stock_availability,
            'Increasing qty to consume full stock must flip detail.stock_availability to out-of-stock');
    }

    // =========================================================================
    // canPurchase coverage gaps flagged by Codex (items 70, 72, 73, 75, 76 +
    // Pro-required message assertion for item 74).
    // =========================================================================

    // Spec 74 — Pro-required user-facing message
    public function test_can_purchase_bundle_without_pro_returns_translated_message(): void
    {
        $child = $this->productFactory->create();
        $childId = $child->variants->first()->id;
        $bundle = $this->productFactory->createBundle([$childId]);
        $bundleVariant = ProductVariation::query()->where('post_id', $bundle->ID)->first();

        $result = $bundleVariant->canPurchase(1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_bundle_product', $result->get_error_code());
        $this->assertSame(
            'Sorry, this product is not available for purchase.',
            $result->get_error_message(),
            'User must see the translated Pro-required guard message, not an opaque code.'
        );
    }

    // Spec 70 — missing product_detail row
    public function test_can_purchase_returns_error_when_product_detail_missing(): void
    {
        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
        // Drop the detail row to simulate a half-deleted product surfaced by a stale cache.
        ProductDetail::query()->where('post_id', $product->ID)->delete();

        $fresh = ProductVariation::query()->find($variant->id);
        $result = $fresh->canPurchase(1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unpublished', $result->get_error_code());
    }

    // Spec 72 — stock_management inactive skips the stock check
    public function test_can_purchase_skips_stock_when_module_inactive(): void
    {
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);

        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'available' => 1, 'total_stock' => 1,
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $result = $fresh->canPurchase(99);

        $this->assertTrue($result, 'Inactive module must bypass available-vs-qty gate entirely.');
    }

    // Spec 73 — variant manage_stock=0 skips the stock check
    public function test_can_purchase_skips_stock_when_variant_manage_stock_zero(): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => 'yes'],
        ]);
        ModuleSettings::getAllSettings(false);

        ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct([
            'manage_stock' => 0,
            'available'    => 0,
            'total_stock'  => 0,
        ]);

        $fresh = ProductVariation::query()->find($variant->id);
        $result = $fresh->canPurchase(5);

        $this->assertTrue($result);
    }

    // Spec 75 — filter may return a WP_Error that's surfaced as-is
    public function test_can_purchase_respects_bundle_filter_wp_error(): void
    {
        $expected = new \WP_Error('custom_reason', 'Custom rejection reason');
        add_filter('fluent_cart/variation/can_purchase_bundle', function () use ($expected) {
            return $expected;
        }, 20);

        try {
            ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
            $fresh = ProductVariation::query()->find($variant->id);
            $result = $fresh->canPurchase(1);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('custom_reason', $result->get_error_code());
            $this->assertSame('Custom rejection reason', $result->get_error_message());
        } finally {
            remove_all_filters('fluent_cart/variation/can_purchase_bundle');
        }
    }

    // Spec 76 — filter may return false → insufficient_stock envelope
    public function test_can_purchase_converts_filter_false_to_insufficient_stock(): void
    {
        add_filter('fluent_cart/variation/can_purchase_bundle', '__return_false', 20);

        try {
            ['product' => $product, 'variant' => $variant] = $this->makeStockedProduct();
            $fresh = ProductVariation::query()->find($variant->id);
            $result = $fresh->canPurchase(1);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('insufficient_stock', $result->get_error_code());
        } finally {
            remove_all_filters('fluent_cart/variation/can_purchase_bundle');
        }
    }
}
