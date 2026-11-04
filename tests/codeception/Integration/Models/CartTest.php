<?php

namespace Tests\Integration\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;

class CartTest extends DatabaseTestCase
{
    private CartFactory $cartFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->cartFactory = new CartFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 1: Primary key is cart_hash
    // =========================================================================

    public function test_primary_key_is_cart_hash(): void
    {
        $cart = new Cart();
        $this->assertSame('cart_hash', $cart->getKeyName());
        $this->assertFalse($cart->incrementing);
        $this->assertSame('fct_carts', $cart->getTable());
    }

    // =========================================================================
    // Spec 2: Auto-generates cart_hash on creating
    // =========================================================================

    public function test_auto_generates_cart_hash_on_creating(): void
    {
        $cart = Cart::query()->create([
            'stage'     => 'draft',
            'cart_data' => [],
        ]);

        $this->assertNotEmpty($cart->cart_hash);
        $this->assertSame(32, strlen($cart->cart_hash)); // MD5 hash length
    }

    // =========================================================================
    // Spec 3: Fillable attributes
    // =========================================================================

    public function test_fillable_attributes(): void
    {
        $expected = [
            'customer_id', 'user_id', 'order_id', 'cart_hash',
            'checkout_data', 'cart_data', 'utm_data', 'coupons',
            'first_name', 'last_name', 'email', 'stage', 'cart_group',
            'user_agent', 'ip_address', 'completed_at', 'deleted_at',
        ];

        $cart = new Cart();
        foreach ($expected as $field) {
            $this->assertContains($field, $cart->getFillable(), "Expected '{$field}' to be fillable");
        }
    }

    // =========================================================================
    // Spec 4: Hidden attributes
    // =========================================================================

    public function test_hidden_attributes_not_in_json(): void
    {
        $customer = $this->customerFactory->create();
        $cart = $this->cartFactory->create([
            'customer_id' => $customer->id,
            'user_id'     => 1,
            'order_id'    => 999,
        ]);

        $array = $cart->toArray();
        $this->assertArrayNotHasKey('customer_id', $array);
        $this->assertArrayNotHasKey('user_id', $array);
        $this->assertArrayNotHasKey('order_id', $array);
    }

    // =========================================================================
    // Spec 5: cart_data JSON round-trip
    // =========================================================================

    public function test_cart_data_json_round_trip(): void
    {
        $items = [CartFactory::makeCartItem(['post_title' => 'Widget'])];
        $cart = $this->cartFactory->create(['cart_data' => $items]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertIsArray($reloaded->cart_data);
        $this->assertSame('Widget', $reloaded->cart_data[0]['post_title']);
    }

    // =========================================================================
    // Spec 6: checkout_data JSON round-trip
    // =========================================================================

    public function test_checkout_data_json_round_trip(): void
    {
        $data = ['billing' => ['country' => 'US']];
        $cart = $this->cartFactory->create(['checkout_data' => $data]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertIsArray($reloaded->checkout_data);
        $this->assertSame('US', Arr::get($reloaded->checkout_data, 'billing.country'));
    }

    public function test_checkout_data_returns_empty_array_for_null(): void
    {
        $cart = new Cart();
        $this->assertSame([], $cart->checkout_data);
    }

    // =========================================================================
    // Spec 7: coupons JSON round-trip
    // =========================================================================

    public function test_coupons_json_round_trip(): void
    {
        $codes = ['CODE10', 'CODE20'];
        $cart = $this->cartFactory->create(['coupons' => $codes]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertSame($codes, $reloaded->coupons);
    }

    public function test_coupons_returns_empty_array_for_null(): void
    {
        $cart = new Cart();
        $this->assertSame([], $cart->coupons);
    }

    // =========================================================================
    // Spec 8: utm_data JSON round-trip
    // =========================================================================

    public function test_utm_data_json_round_trip(): void
    {
        $utm = ['utm_source' => 'google', 'utm_medium' => 'cpc'];
        $cart = $this->cartFactory->create(['utm_data' => $utm]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertSame($utm, $reloaded->utm_data);
    }

    public function test_utm_data_returns_empty_array_for_null(): void
    {
        $cart = new Cart();
        $this->assertSame([], $cart->utm_data);
    }

    // =========================================================================
    // Spec 9: Cart belongsTo Customer
    // =========================================================================

    public function test_cart_belongs_to_customer(): void
    {
        $customer = $this->customerFactory->create();
        $cart = $this->cartFactory->create(['customer_id' => $customer->id]);

        $this->assertInstanceOf(Customer::class, $cart->customer);
        $this->assertSame($customer->id, $cart->customer->id);
    }

    // =========================================================================
    // Spec 10: Cart belongsTo Order
    // =========================================================================

    public function test_cart_belongs_to_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create(['order_id' => $order->id]);

        $this->assertInstanceOf(Order::class, $cart->order);
        $this->assertSame($order->id, $cart->order->id);
    }

    // =========================================================================
    // Spec 11: scopeStageNotCompleted
    // =========================================================================

    public function test_scope_stage_not_completed(): void
    {
        $this->cartFactory->create(['stage' => 'draft']);
        $this->cartFactory->create(['stage' => 'intended']);
        $this->cartFactory->create(['stage' => 'completed']);

        $results = Cart::stageNotCompleted()->get();
        $this->assertCount(2, $results);

        foreach ($results as $cart) {
            $this->assertNotSame('completed', $cart->stage);
        }
    }

    // =========================================================================
    // Spec 12-14: addItem
    // =========================================================================

    public function test_add_item_appends_to_cart_data_and_fires_actions(): void
    {
        $cart = $this->cartFactory->create();
        $item = CartFactory::makeCartItem();

        $itemAddedFired = false;
        $itemsUpdatedFired = false;
        add_action('fluent_cart/cart/item_added', function () use (&$itemAddedFired) {
            $itemAddedFired = true;
        });
        add_action('fluent_cart/cart/cart_data_items_updated', function () use (&$itemsUpdatedFired) {
            $itemsUpdatedFired = true;
        });

        $cart->addItem($item);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
        $this->assertTrue($itemAddedFired);
        $this->assertTrue($itemsUpdatedFired);
    }

    public function test_add_item_with_replacing_index(): void
    {
        $item1 = CartFactory::makeCartItem(['post_title' => 'Original']);
        $item2 = CartFactory::makeCartItem(['post_title' => 'Replacement']);
        $cart = $this->cartFactory->withCartItems([$item1])->create();

        $cart->addItem($item2, 0);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
        $this->assertSame('Replacement', $reloaded->cart_data[0]['post_title']);
    }

    // =========================================================================
    // Spec 15: addItem returns WP_Error when locked
    // =========================================================================

    public function test_add_item_returns_error_when_locked(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        $result = $cart->addItem(CartFactory::makeCartItem());
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 16-17: removeItem
    // =========================================================================

    public function test_remove_item_removes_by_variation_id(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 42]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $cart->removeItem(42);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(0, $reloaded->cart_data);
    }

    public function test_remove_item_fires_item_removed_action(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 42]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $fired = false;
        add_action('fluent_cart/cart/item_removed', function () use (&$fired) {
            $fired = true;
        });

        $cart->removeItem(42);
        $this->assertTrue($fired);
    }

    // =========================================================================
    // Spec 18: removeItem returns WP_Error when locked
    // =========================================================================

    public function test_remove_item_returns_error_when_locked(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $item = CartFactory::makeCartItem(['object_id' => 42]);
        $cart = $this->cartFactory->withCartItems([$item])->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        $result = $cart->removeItem(42);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 19: removeItem returns cart unchanged if not found
    // =========================================================================

    public function test_remove_item_returns_cart_when_not_found(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 42]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $result = $cart->removeItem(999);
        $this->assertInstanceOf(Cart::class, $result);
        $this->assertCount(1, $result->cart_data);
    }

    // =========================================================================
    // Spec 20-21: addByVariation
    // =========================================================================

    public function test_add_by_variation_adds_product_to_cart(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();
        $cart = $this->cartFactory->create();

        $result = $cart->addByVariation($variation, ['quantity' => 1]);

        $this->assertInstanceOf(Cart::class, $result);
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
        $this->assertEquals($variation->id, $reloaded->cart_data[0]['object_id']);
    }

    public function test_add_by_variation_rejects_variation_of_deleted_product(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();
        wp_delete_post($product->ID, true);
        $variation = ProductVariation::query()->find($variation->id);
        $cart = $this->cartFactory->create();

        $result = $cart->addByVariation($variation, ['quantity' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('product_not_found', $result->get_error_code());
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(0, $reloaded->cart_data);
    }

    public function test_add_by_variation_quantity_zero_removes_item(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();
        $item = CartFactory::makeCartItem(['object_id' => $variation->id]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $cart->addByVariation($variation, ['quantity' => 0]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(0, $reloaded->cart_data);
    }

    // =========================================================================
    // Spec 22-23: addByVariation quantity behavior
    // =========================================================================

    public function test_add_by_variation_increments_quantity_for_existing_item(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $cart = $this->cartFactory->create();
        $cart->addByVariation($variation, ['quantity' => 1]);
        $cart->addByVariation($variation, ['quantity' => 1]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
        $this->assertSame(2, (int)$reloaded->cart_data[0]['quantity']);
    }

    public function test_add_by_variation_by_input_replaces_quantity(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $cart = $this->cartFactory->create();
        $cart->addByVariation($variation, ['quantity' => 3]);
        $cart->addByVariation($variation, ['quantity' => 2, 'by_input' => true]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
        $this->assertSame(2, (int)$reloaded->cart_data[0]['quantity']);
    }

    // =========================================================================
    // Spec 24-25: addByVariation validation
    // =========================================================================

    public function test_add_by_variation_with_validation_rejects_inactive_product(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'item_status' => 'inactive',
        ])->create();
        $variation = $product->variants->first();

        $cart = $this->cartFactory->create();
        $result = $cart->addByVariation($variation, [
            'quantity'      => 1,
            'will_validate' => true,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_add_by_variation_with_validation_returns_error_when_locked(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        // First add without validation to populate, then try with validation
        $result = $cart->addByVariation($variation, [
            'quantity'      => 1,
            'will_validate' => true,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 28-32: addByCustom
    // =========================================================================

    public function test_add_by_custom_adds_normalized_item(): void
    {
        $cart = $this->cartFactory->create();
        $customItem = [
            'id'          => 100,
            'item_id'     => 100,
            'object_id'   => 100,
            'post_id'     => 50,
            'post_title'  => 'Custom Item',
            'title'       => 'Custom Variant',
            'price'       => 1000,
            'unit_price'  => 1000,
            'item_price'  => 1000,
            'payment_type' => 'onetime',
            'fulfillment_type' => 'digital',
        ];

        $result = $cart->addByCustom($customItem, ['quantity' => 1]);

        $this->assertInstanceOf(Cart::class, $result);
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(1, $reloaded->cart_data);
    }

    public function test_add_by_custom_quantity_zero_removes_item(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 100]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $cart->addByCustom([
            'id' => 100, 'object_id' => 100, 'post_id' => 50,
            'post_title' => 'X', 'price' => 1000, 'unit_price' => 1000,
            'item_price' => 1000, 'payment_type' => 'onetime',
        ], ['quantity' => 0]);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(0, $reloaded->cart_data);
    }

    public function test_add_by_custom_missing_required_fields_removes_item(): void
    {
        $cart = $this->cartFactory->create();
        // Missing post_title, price, unit_price
        $result = $cart->addByCustom([
            'id' => 200, 'object_id' => 200, 'post_id' => 50,
            'payment_type' => 'onetime',
        ], ['quantity' => 1]);

        // Should silently remove (via removeItem) — cart stays empty
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertCount(0, $reloaded->cart_data);
    }

    public function test_add_by_custom_subscription_returns_error(): void
    {
        $cart = $this->cartFactory->create();
        $result = $cart->addByCustom([
            'id' => 300, 'object_id' => 300, 'post_id' => 50,
            'post_title' => 'Sub', 'price' => 1000, 'unit_price' => 1000,
            'item_price' => 1000, 'payment_type' => 'subscription',
        ], ['quantity' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 33-35: applyCoupon
    // =========================================================================

    public function test_apply_coupon_returns_error_when_locked(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        $result = $cart->applyCoupon(['INVALID']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cart_locked', $result->get_error_code(), 'the lock is refused before the coupon is looked up');
    }

    public function test_apply_coupon_with_invalid_code_returns_error(): void
    {
        $item = CartFactory::makeCartItem();
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $result = $cart->applyCoupon(['NONEXISTENT_COUPON_XYZ']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_apply_valid_coupon_updates_cart(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 10000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $coupon = Coupon::query()->create([
            'title'      => 'Test Coupon',
            'code'       => 'SAVE10',
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => 1000,
            'stackable'  => 1,
            'priority'   => 1,
            'use_count'  => 0,
            'conditions' => [],
        ]);

        $fired = false;
        add_action('fluent_cart/cart/cart_data_items_updated', function () use (&$fired) {
            $fired = true;
        });

        $result = $cart->applyCoupon(['SAVE10']);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertTrue($fired);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertContains('SAVE10', $reloaded->coupons);
    }

    // =========================================================================
    // Spec 36-37: removeCoupon
    // =========================================================================

    public function test_remove_coupon_returns_error_when_locked(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
            'coupons'       => ['CODE10'],
        ]);

        $result = $cart->removeCoupon(['CODE10']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // =========================================================================
    // Spec 38-40: reValidateCoupons
    // =========================================================================

    public function test_revalidate_coupons_fires_cart_amount_updated(): void
    {
        $item = CartFactory::makeCartItem();
        $cart = $this->cartFactory->withCartItems([$item])->create();

        // Create a valid coupon and apply it
        Coupon::query()->create([
            'title' => 'Test', 'code' => 'REVALIDATE', 'status' => 'active',
            'type' => 'fixed', 'amount' => 500, 'stackable' => 1,
            'priority' => 1, 'use_count' => 0, 'conditions' => [],
        ]);
        $cart->applyCoupon(['REVALIDATE']);

        $fired = false;
        add_action('fluent_cart/checkout/cart_amount_updated', function () use (&$fired) {
            $fired = true;
        });

        $cart->reValidateCoupons();
        $this->assertTrue($fired);
    }

    // =========================================================================
    // Spec 41-45: Fee system — addFee
    // =========================================================================

    public function test_add_fee_persists_to_checkout_data(): void
    {
        $cart = $this->cartFactory->create();
        $result = $cart->addFee([
            'key'    => 'processing',
            'label'  => 'Processing Fee',
            'amount' => 500,
        ]);

        $this->assertTrue($result);
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $fees = Arr::get($reloaded->checkout_data, 'fees', []);
        $this->assertCount(1, $fees);
        $this->assertSame('processing', $fees[0]['key']);
        $this->assertSame(500, $fees[0]['amount']);
    }

    public function test_add_fee_same_source_key_replaces(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'proc', 'label' => 'Fee v1', 'amount' => 300, 'source' => 'gateway']);
        $cart->addFee(['key' => 'proc', 'label' => 'Fee v2', 'amount' => 600, 'source' => 'gateway']);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $fees = Arr::get($reloaded->checkout_data, 'fees', []);
        $this->assertCount(1, $fees);
        $this->assertSame(600, $fees[0]['amount']);
        $this->assertSame('Fee v2', $fees[0]['label']);
    }

    public function test_add_fee_returns_false_for_missing_fields(): void
    {
        $cart = $this->cartFactory->create();
        $this->assertFalse($cart->addFee(['key' => 'x', 'label' => 'X'])); // missing amount
        $this->assertFalse($cart->addFee(['key' => 'x', 'amount' => 100])); // missing label
        $this->assertFalse($cart->addFee(['label' => 'X', 'amount' => 100])); // missing key
    }

    public function test_add_fee_returns_false_for_zero_or_negative_amount(): void
    {
        $cart = $this->cartFactory->create();
        $this->assertFalse($cart->addFee(['key' => 'x', 'label' => 'X', 'amount' => 0]));
        $this->assertFalse($cart->addFee(['key' => 'x', 'label' => 'X', 'amount' => -100]));
    }

    // =========================================================================
    // Spec 46-49: Fee system — removeFee, removeFeesBySource
    // =========================================================================

    public function test_remove_fee_by_key(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'proc', 'label' => 'Fee', 'amount' => 500]);

        $this->assertTrue($cart->removeFee('proc'));
        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $this->assertEmpty(Arr::get($reloaded->checkout_data, 'fees', []));
    }

    public function test_remove_fee_by_key_and_source(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'proc', 'label' => 'Fee A', 'amount' => 300, 'source' => 'gateway_a']);
        $cart->addFee(['key' => 'proc', 'label' => 'Fee B', 'amount' => 400, 'source' => 'gateway_b']);

        $cart->removeFee('proc', 'gateway_a');

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $fees = Arr::get($reloaded->checkout_data, 'fees', []);
        $this->assertCount(1, $fees);
        $this->assertSame('gateway_b', $fees[0]['source']);
    }

    public function test_remove_fee_returns_false_when_not_found(): void
    {
        $cart = $this->cartFactory->create();
        $this->assertFalse($cart->removeFee('nonexistent'));
    }

    public function test_remove_fees_by_source(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'fee1', 'label' => 'Fee 1', 'amount' => 100, 'source' => 'addon_x']);
        $cart->addFee(['key' => 'fee2', 'label' => 'Fee 2', 'amount' => 200, 'source' => 'addon_x']);
        $cart->addFee(['key' => 'fee3', 'label' => 'Fee 3', 'amount' => 300, 'source' => 'addon_y']);

        $cart->removeFeesBySource('addon_x');

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $fees = Arr::get($reloaded->checkout_data, 'fees', []);
        $this->assertCount(1, $fees);
        $this->assertSame('addon_y', $fees[0]['source']);
    }

    // =========================================================================
    // Spec 50: getFeeTotal
    // =========================================================================

    public function test_get_fee_total_returns_sum(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'a', 'label' => 'A', 'amount' => 300]);
        $cart->addFee(['key' => 'b', 'label' => 'B', 'amount' => 200]);

        $this->assertSame(500, $cart->getFeeTotal());
    }

    // =========================================================================
    // Spec 51-55: getFees behavior
    // =========================================================================

    public function test_get_fees_returns_stored_fees(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'proc', 'label' => 'Processing', 'amount' => 450]);

        $fees = $cart->getFees();
        $this->assertCount(1, $fees);
        $this->assertSame('proc', $fees[0]['key']);
    }

    public function test_get_fees_skips_filter_for_locked_cart(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => [
                'is_locked' => 'yes',
                'fees'      => [['key' => 'stored', 'label' => 'Stored', 'amount' => 100, 'source' => 'custom']],
            ],
        ]);

        $filterCalled = false;
        $callback = function ($fees) use (&$filterCalled) {
            $filterCalled = true;
            $fees[] = ['key' => 'dynamic', 'label' => 'Dynamic', 'amount' => 999, 'source' => 'filter'];
            return $fees;
        };
        add_filter('fluent_cart/cart/fees', $callback);

        $fees = $cart->getFees();
        $this->assertFalse($filterCalled);
        $this->assertCount(1, $fees);

        remove_filter('fluent_cart/cart/fees', $callback);
    }

    public function test_get_fees_skips_filter_for_renewal_cart(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => [
                'renew_data' => ['is_renewal' => 'yes'],
                'fees'       => [['key' => 'stored', 'label' => 'Stored', 'amount' => 100, 'source' => 'custom']],
            ],
        ]);

        $filterCalled = false;
        $callback = function ($fees) use (&$filterCalled) {
            $filterCalled = true;
            return $fees;
        };
        add_filter('fluent_cart/cart/fees', $callback);

        $fees = $cart->getFees();
        $this->assertFalse($filterCalled);

        remove_filter('fluent_cart/cart/fees', $callback);
    }

    public function test_get_fees_caches_per_request(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'a', 'label' => 'A', 'amount' => 100]);

        $callCount = 0;
        $callback = function ($fees) use (&$callCount) {
            $callCount++;
            return $fees;
        };
        add_filter('fluent_cart/cart/fees', $callback);

        $cart->getFees();
        $cart->getFees();
        // Filter should be called at most once due to caching
        $this->assertLessThanOrEqual(1, $callCount);

        remove_filter('fluent_cart/cart/fees', $callback);
    }

    public function test_clear_fee_cache_allows_re_evaluation(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'a', 'label' => 'A', 'amount' => 100]);

        $callCount = 0;
        $callback = function ($fees) use (&$callCount) {
            $callCount++;
            return $fees;
        };
        add_filter('fluent_cart/cart/fees', $callback);

        $cart->getFees();
        $cart->clearFeeCache();
        $cart->getFees();
        $this->assertSame(2, $callCount);

        remove_filter('fluent_cart/cart/fees', $callback);
    }

    // =========================================================================
    // Spec 56: buildFeeCartItem
    // =========================================================================

    public function test_build_fee_cart_item(): void
    {
        $fee = ['key' => 'proc', 'label' => 'Processing Fee', 'amount' => 450, 'source' => 'gateway'];
        $item = Cart::buildFeeCartItem($fee);

        $this->assertSame('fee', $item['payment_type']);
        $this->assertTrue($item['is_fee']);
        $this->assertSame('digital', $item['fulfillment_type']);
        $this->assertSame(450, $item['unit_price']);
        $this->assertSame(450, $item['subtotal']);
        $this->assertSame(450, $item['line_total']);
        $this->assertSame(1, $item['quantity']);
        $this->assertSame('Processing Fee', $item['title']);
    }

    // =========================================================================
    // Spec 57-58: getEstimatedTotal
    // =========================================================================

    public function test_get_estimated_total_sums_items_and_fees(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();
        $cart->addFee(['key' => 'fee', 'label' => 'Fee', 'amount' => 300]);

        $total = $cart->getEstimatedTotal();
        $this->assertSame(5300, $total);
    }

    public function test_get_estimated_total_clamps_to_zero(): void
    {
        // Create cart with heavily discounted items resulting in negative
        $item = CartFactory::makeCartItem([
            'unit_price'      => 100,
            'subtotal'        => 100,
            'line_total'      => 0,
            'discount_total'  => 100,
            'coupon_discount' => 100,
        ]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $total = $cart->getEstimatedTotal();
        $this->assertGreaterThanOrEqual(0, $total);
    }

    // =========================================================================
    // Spec 59-60: getEstimatedTotal hooks
    // =========================================================================

    public function test_get_estimated_total_fires_before_and_after_actions(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 1000, 'subtotal' => 1000, 'line_total' => 1000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $beforeFired = false;
        $afterFired = false;
        add_action('fluent_cart/cart/before_totals_calculation', function () use (&$beforeFired) {
            $beforeFired = true;
        });
        add_action('fluent_cart/cart/after_totals_calculation', function () use (&$afterFired) {
            $afterFired = true;
        });

        $cart->getEstimatedTotal();
        $this->assertTrue($beforeFired);
        $this->assertTrue($afterFired);
    }

    // =========================================================================
    // Spec 62-63: getEstimatedRecurringTotal
    // =========================================================================

    public function test_get_estimated_recurring_total_sums_subscription_items(): void
    {
        $subItem = CartFactory::makeSubscriptionItem(['unit_price' => 2000, 'subtotal' => 2000]);
        $onetimeItem = CartFactory::makeCartItem(['unit_price' => 1000, 'subtotal' => 1000]);
        $cart = $this->cartFactory->withCartItems([$subItem, $onetimeItem])->create();

        $recurring = $cart->getEstimatedRecurringTotal();
        $this->assertSame(2000, $recurring);
    }

    public function test_get_estimated_recurring_total_returns_zero_without_subscriptions(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 1000, 'subtotal' => 1000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->assertSame(0, $cart->getEstimatedRecurringTotal());
    }

    // =========================================================================
    // Spec 64: getItemsSubtotal
    // =========================================================================

    public function test_get_items_subtotal(): void
    {
        $item1 = CartFactory::makeCartItem(['unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 3000]);
        $item2 = CartFactory::makeCartItem(['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $this->assertEquals(5000, $cart->getItemsSubtotal());
    }

    // =========================================================================
    // Spec 65-66: isLocked
    // =========================================================================

    public function test_is_locked_returns_true_when_locked_with_order(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        $this->assertTrue($cart->isLocked());
    }

    public function test_is_locked_returns_false_without_order_id(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => ['is_locked' => 'yes'],
        ]);

        $this->assertFalse($cart->isLocked());
    }

    // =========================================================================
    // Spec 67-68: hasSubscription
    // =========================================================================

    public function test_has_subscription_returns_true_with_subscription_item(): void
    {
        $subItem = CartFactory::makeSubscriptionItem();
        $cart = $this->cartFactory->withCartItems([$subItem])->create();

        $this->assertTrue($cart->hasSubscription());
    }

    public function test_has_subscription_returns_false_with_only_onetime(): void
    {
        $item = CartFactory::makeCartItem();
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->assertFalse($cart->hasSubscription());
    }

    // =========================================================================
    // Spec 69-70: requireShipping
    // =========================================================================

    public function test_require_shipping_true_with_physical_item(): void
    {
        $item = CartFactory::makePhysicalItem();
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->assertTrue($cart->requireShipping());
    }

    public function test_require_shipping_false_with_digital_only(): void
    {
        $item = CartFactory::makeCartItem(['fulfillment_type' => 'digital']);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->assertFalse($cart->requireShipping());
    }

    // =========================================================================
    // Spec 71-72: isZeroPayment
    // =========================================================================

    public function test_is_zero_payment_true_when_total_zero_no_subscription(): void
    {
        $cart = $this->cartFactory->create(['cart_data' => []]);
        $this->assertTrue($cart->isZeroPayment());
    }

    public function test_is_zero_payment_false_when_has_subscription(): void
    {
        $subItem = CartFactory::makeSubscriptionItem(['unit_price' => 0, 'subtotal' => 0, 'line_total' => 0]);
        $cart = $this->cartFactory->withCartItems([$subItem])->create();

        $this->assertFalse($cart->isZeroPayment());
    }

    // =========================================================================
    // Spec 73-76: Address methods
    // =========================================================================

    public function test_get_billing_address_from_checkout_data(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => [
                'form_data' => [
                    'billing_full_name' => 'John Doe',
                    'billing_address_1' => '123 Main St',
                    'billing_city'      => 'New York',
                    'billing_state'     => 'NY',
                    'billing_postcode'  => '10001',
                    'billing_country'   => 'US',
                ],
            ],
        ]);

        $address = $cart->getBillingAddress();
        $this->assertSame('John Doe', $address['full_name']);
        $this->assertSame('123 Main St', $address['address_1']);
        $this->assertSame('US', $address['country']);
    }

    public function test_get_shipping_address_returns_shipping_when_ship_to_different(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => [
                'form_data' => [
                    'ship_to_different'      => 'yes',
                    'shipping_full_name'     => 'Jane Smith',
                    'shipping_address_1'     => '456 Oak Ave',
                    'shipping_city'          => 'LA',
                    'shipping_country'       => 'US',
                    'billing_full_name'      => 'John Doe',
                    'billing_address_1'      => '123 Main St',
                ],
            ],
        ]);

        $address = $cart->getShippingAddress();
        $this->assertSame('Jane Smith', $address['full_name']);
        $this->assertSame('456 Oak Ave', $address['address_1']);
    }

    public function test_get_shipping_address_falls_back_to_billing(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => [
                'form_data' => [
                    'billing_full_name' => 'John Doe',
                    'billing_address_1' => '123 Main St',
                ],
            ],
        ]);

        $address = $cart->getShippingAddress();
        $this->assertSame('John Doe', $address['full_name']);
    }

    public function test_is_ship_to_different(): void
    {
        $cart = $this->cartFactory->create([
            'checkout_data' => ['form_data' => ['ship_to_different' => 'yes']],
        ]);
        $this->assertTrue($cart->isShipToDifferent());

        $cart2 = $this->cartFactory->create();
        $this->assertFalse($cart2->isShipToDifferent());
    }

    // =========================================================================
    // Spec 77-78: guessCustomer
    // =========================================================================

    public function test_guess_customer_by_customer_id(): void
    {
        $customer = $this->customerFactory->create();
        $cart = $this->cartFactory->create(['customer_id' => $customer->id]);

        $guessed = $cart->guessCustomer();
        $this->assertNotNull($guessed);
        $this->assertSame($customer->id, $guessed->id);
    }

    public function test_guess_customer_by_user_id(): void
    {
        $customer = $this->customerFactory->createWithUser();
        $cart = $this->cartFactory->create(['user_id' => $customer->user_id]);

        $guessed = $cart->guessCustomer();
        $this->assertNotNull($guessed);
        $this->assertSame($customer->id, $guessed->id);
    }

    public function test_guess_customer_by_email(): void
    {
        $customer = $this->customerFactory->create(['email' => 'guess@example.com']);
        $cart = $this->cartFactory->create(['email' => 'guess@example.com']);

        $guessed = $cart->guessCustomer();
        $this->assertNotNull($guessed);
        $this->assertSame($customer->id, $guessed->id);
    }

    public function test_guess_customer_returns_null_when_not_found(): void
    {
        $cart = $this->cartFactory->create(['email' => 'unknown@example.com']);
        $this->assertNull($cart->guessCustomer());
    }

    // =========================================================================
    // Spec 79-81: findExistingItemAndIndex
    // =========================================================================

    public function test_find_existing_item_and_index(): void
    {
        $item1 = CartFactory::makeCartItem(['object_id' => 10]);
        $item2 = CartFactory::makeCartItem(['object_id' => 20]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $result = $cart->findExistingItemAndIndex(20);
        $this->assertNotNull($result);
        $this->assertSame(1, $result[0]); // index
        $this->assertEquals(20, $result[1]['object_id']); // item
    }

    public function test_find_existing_item_with_extra_args(): void
    {
        $item1 = CartFactory::makeCartItem(['object_id' => 10, 'other_info' => ['color' => 'red', 'payment_type' => 'onetime']]);
        $item2 = CartFactory::makeCartItem(['object_id' => 10, 'other_info' => ['color' => 'blue', 'payment_type' => 'onetime']]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $result = $cart->findExistingItemAndIndex(10, ['other_info.color' => 'blue']);
        $this->assertNotNull($result);
        $this->assertSame(1, $result[0]);
    }

    public function test_find_existing_item_returns_null_when_not_found(): void
    {
        $item = CartFactory::makeCartItem(['object_id' => 10]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $this->assertNull($cart->findExistingItemAndIndex(999));
    }

    // =========================================================================
    // Spec 82-85: getDiscountLines
    // =========================================================================

    public function test_get_discount_lines_returns_empty_without_coupons(): void
    {
        $cart = $this->cartFactory->create();
        $this->assertSame([], $cart->getDiscountLines());
    }

    public function test_get_discount_lines_with_single_coupon(): void
    {
        $coupon = Coupon::query()->create([
            'title' => 'Fixed', 'code' => 'DISC500', 'status' => 'active',
            'type' => 'fixed', 'amount' => 500, 'stackable' => 1,
            'priority' => 1, 'use_count' => 0, 'conditions' => [],
        ]);

        $item = CartFactory::makeCartItem([
            'unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 4500,
            'coupon_discount' => 500, 'discount_total' => 500,
        ]);
        $cart = $this->cartFactory->withCartItems([$item])->create([
            'coupons' => ['DISC500'],
        ]);

        $lines = $cart->getDiscountLines();
        $this->assertArrayHasKey('DISC500', $lines);
        $this->assertSame(500, $lines['DISC500']['discount']);
        $this->assertSame('DISC500', $lines['DISC500']['code']);
    }

    public function test_get_discount_lines_percentage_coupon_has_formatted_title(): void
    {
        $coupon = Coupon::query()->create([
            'title' => 'Percent', 'code' => 'PCT10', 'status' => 'active',
            'type' => 'percentage', 'amount' => 10, 'stackable' => 1,
            'priority' => 1, 'use_count' => 0, 'conditions' => [],
        ]);

        $item = CartFactory::makeCartItem([
            'unit_price' => 10000, 'subtotal' => 10000, 'line_total' => 9000,
            'coupon_discount' => 1000, 'discount_total' => 1000,
        ]);
        $cart = $this->cartFactory->withCartItems([$item])->create([
            'coupons' => ['PCT10'],
        ]);

        $lines = $cart->getDiscountLines();
        $this->assertStringContainsString('10%', $lines['PCT10']['formatted_title']);
    }

    // =========================================================================
    // Spec 184: Edge — empty cart_data returns empty array
    // =========================================================================

    public function test_cart_data_returns_empty_array_for_null(): void
    {
        $cart = new Cart();
        $this->assertSame([], $cart->cart_data);
    }

    // =========================================================================
    // Spec 186: Edge — locked cart blocks all modifications
    // =========================================================================

    public function test_locked_cart_blocks_all_modification_methods(): void
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $cart = $this->cartFactory->create([
            'order_id'      => $order->id,
            'checkout_data' => ['is_locked' => 'yes'],
            'coupons'       => ['CODE'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $cart->addItem(CartFactory::makeCartItem()));
        $this->assertInstanceOf(\WP_Error::class, $cart->removeItem(1));
        $this->assertInstanceOf(\WP_Error::class, $cart->applyCoupon(['X']));
        $this->assertInstanceOf(\WP_Error::class, $cart->removeCoupon(['CODE']));
    }

    // =========================================================================
    // Spec 190: Edge — static cache invalidation
    // =========================================================================

    public function test_setting_cart_data_invalidates_static_cache(): void
    {
        $item1 = CartFactory::makeCartItem(['post_title' => 'Original']);
        $cart = $this->cartFactory->withCartItems([$item1])->create();

        // Access to populate cache
        $_ = $cart->cart_data;

        // Update cart_data
        $item2 = CartFactory::makeCartItem(['post_title' => 'Updated']);
        $cart->cart_data = [$item2];

        // Cache should be invalidated; should see new data
        $this->assertSame('Updated', $cart->cart_data[0]['post_title']);
    }

    // =========================================================================
    // Spec 191: Edge — multiple fees accumulate
    // =========================================================================

    public function test_multiple_fees_accumulate_different_keys(): void
    {
        $cart = $this->cartFactory->create();
        $cart->addFee(['key' => 'fee_a', 'label' => 'A', 'amount' => 100, 'source' => 'plugin_a']);
        $cart->addFee(['key' => 'fee_b', 'label' => 'B', 'amount' => 200, 'source' => 'plugin_b']);

        $reloaded = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        $fees = Arr::get($reloaded->checkout_data, 'fees', []);
        $this->assertCount(2, $fees);
        $this->assertSame(300, $cart->getFeeTotal());
    }
}
