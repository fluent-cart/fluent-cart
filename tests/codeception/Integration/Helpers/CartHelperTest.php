<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

class CartHelperTest extends DatabaseTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    // =========================================================================
    // Spec 97: generateCartItemFromVariation returns expected keys
    // =========================================================================

    public function test_generate_cart_item_from_variation_returns_expected_keys(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $item = CartHelper::generateCartItemFromVariation($variation, 1);

        $expectedKeys = [
            'id', 'object_id', 'post_id', 'quantity', 'post_title',
            'title', 'price', 'unit_price', 'line_total', 'subtotal',
            'fulfillment_type', 'other_info', 'variation_type',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $item, "Expected key '{$key}' in cart item");
        }
    }

    // =========================================================================
    // Spec 98: generateCartItemFromVariation calculates subtotal and line_total
    // =========================================================================

    public function test_generate_cart_item_calculates_subtotal_and_line_total(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'item_price' => 2000,
        ])->create();
        $variation = $product->variants->first();

        $item = CartHelper::generateCartItemFromVariation($variation, 3);

        $this->assertSame(6000, $item['subtotal']);
        $this->assertSame(6000, $item['line_total']);
        $this->assertSame(3, $item['quantity']);
        $this->assertSame(2000, $item['unit_price']);
    }

    // =========================================================================
    // Spec 99: item_price filter is applied and clamped to non-negative
    // =========================================================================

    public function test_generate_cart_item_applies_price_filter(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'item_price' => 5000,
        ])->create();
        $variation = $product->variants->first();

        add_filter('fluent_cart/cart/item_price', function ($price) {
            return 3000; // override price
        });

        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $this->assertSame(3000, $item['price']);
        $this->assertSame(3000, $item['subtotal']);
    }

    public function test_generate_cart_item_clamps_negative_price_to_zero(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        add_filter('fluent_cart/cart/item_price', function () {
            return -500;
        });

        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $this->assertSame(0, $item['price']);
    }

    // =========================================================================
    // Spec 100-101: generateCartItemCustomItem
    // =========================================================================

    public function test_generate_cart_item_custom_item_returns_expected_fields(): void
    {
        $variation = [
            'id'               => 99,
            'object_id'        => 99,
            'post_id'          => 50,
            'post_title'       => 'Custom Product',
            'variation_title'  => 'Custom Variant',
            'item_price'       => 3000,
            'unit_price'       => 3000,
            'fulfillment_type' => 'digital',
            'other_info'       => ['payment_type' => 'onetime'],
        ];

        $item = CartHelper::generateCartItemCustomItem($variation, 2);

        $this->assertSame(6000, $item['subtotal']);
        $this->assertSame(6000, $item['line_total']);
        $this->assertSame(0, $item['discount_total']);
    }

    public function test_generate_cart_item_custom_with_discounts(): void
    {
        $variation = [
            'id'               => 99,
            'object_id'        => 99,
            'post_id'          => 50,
            'post_title'       => 'Custom',
            'item_price'       => 5000,
            'manual_discount'  => 500,
            'coupon_discount'  => 300,
            'fulfillment_type' => 'digital',
            'other_info'       => ['payment_type' => 'onetime'],
        ];

        $item = CartHelper::generateCartItemCustomItem($variation, 1);

        $this->assertSame(5000, $item['subtotal']);
        $this->assertSame(800, $item['discount_total']); // 500 + 300
        $this->assertSame(4200, $item['line_total']);     // 5000 - 800
    }

    // =========================================================================
    // Spec 102-106: calculateShippingCharge
    // =========================================================================

    public function test_calculate_shipping_charge_returns_zero_for_digital(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'fulfillment_type' => 'digital',
        ])->create();
        $variation = $product->variants->first();

        $charge = CartHelper::calculateShippingCharge($variation, 1);
        $this->assertSame(0, $charge);
    }

    public function test_calculate_shipping_charge_returns_zero_without_shipping_class(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'fulfillment_type' => 'physical',
        ])->withDetailAttributes([
            'fulfillment_type' => 'physical',
        ])->create();
        $variation = $product->variants->first();

        $charge = CartHelper::calculateShippingCharge($variation, 1);
        $this->assertSame(0, $charge);
    }

    // =========================================================================
    // Spec 107-109: shouldAddItemToCart
    // =========================================================================

    public function test_should_add_item_when_stock_not_managed(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'manage_stock' => 0,
        ])->create();
        $variation = $product->variants->first();

        $this->assertTrue(CartHelper::shouldAddItemToCart($variation, 999));
    }

    public function test_should_add_item_when_quantity_within_stock(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'manage_stock' => 1,
            'available'    => 10,
        ])->create();
        $variation = $product->variants->first();

        $this->assertTrue(CartHelper::shouldAddItemToCart($variation, 5));
    }

    public function test_should_not_add_item_when_quantity_exceeds_stock(): void
    {
        $product = $this->productFactory->withVariantAttributes([
            'manage_stock' => 1,
            'available'    => 2,
        ])->create();
        $variation = $product->variants->first();

        $this->assertFalse(CartHelper::shouldAddItemToCart($variation, 5));
    }

    // =========================================================================
    // Spec 110: normalizeCustomFields
    // =========================================================================

    public function test_normalize_custom_fields_sets_defaults(): void
    {
        $input = (object) [
            'item_id'    => 42,
            'unit_price' => 1500,
            'title'      => 'My Item',
        ];

        $result = CartHelper::normalizeCustomFields($input);

        $this->assertSame(42, $result->id);          // falls back to item_id
        $this->assertSame(1500, $result->item_price); // falls back to unit_price
        $this->assertSame('My Item', $result->variation_title);
        $this->assertSame('onetime', $result->payment_type);
        $this->assertSame('digital', $result->fulfillment_type);
    }

    // =========================================================================
    // Spec 111: generateCartFromVariation
    // =========================================================================

    public function test_generate_cart_from_variation_creates_cart_with_item(): void
    {
        $product = $this->productFactory->create();
        $variation = $product->variants->first();

        $cart = CartHelper::generateCartFromVariation($variation, 2);

        $this->assertInstanceOf(Cart::class, $cart);
        $this->assertCount(1, $cart->cart_data);
        $this->assertSame(2, (int) $cart->cart_data[0]['quantity']);
    }
}
