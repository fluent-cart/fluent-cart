<?php

namespace Tests\Integration\Helpers;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;

class CartQuantityLimitTest extends WPTestCase
{
    /** @dataProvider invalidQuantities */
    public function test_invalid_quantities_are_rejected($quantity): void
    {
        $this->assertWPError(CartHelper::validateQuantity($quantity), 'An invalid quantity must fail before any arithmetic or casting.');
    }

    public function invalidQuantities(): array
    {
        return [
            'zero'          => [0],
            'negative'      => [-1],
            'above ceiling' => [CartHelper::MAX_QUANTITY + 1],
            'int32 ceiling' => [2147483647],
            'near int64'    => ['9223372036854775'],
            'above int64'   => ['18446744073709551616'],
            'fractional'    => ['1.5'],
            'non numeric'   => ['invalid'],
            'infinite'      => [INF],
            'not a number'  => [NAN],
            'array'         => [[]],
        ];
    }

    public function test_supported_quantities_are_accepted(): void
    {
        $this->assertNull(CartHelper::validateQuantity(1), 'One is the smallest purchasable quantity.');
        $this->assertNull(CartHelper::validateQuantity(CartHelper::MAX_QUANTITY), 'The ceiling itself must remain purchasable.');
        $this->assertNull(CartHelper::validateQuantity('25'), 'A numeric string from a request body is a valid quantity.');
        $this->assertNull(CartHelper::validateQuantity(-1, true), 'A decrement is a valid cart update.');
        $this->assertNull(CartHelper::validateQuantity(0, true), 'A zero delta means "remove", which the caller handles.');
    }

    public function test_a_store_can_raise_the_ceiling_through_the_filter(): void
    {
        $filter = static function () {
            return 250000;
        };
        add_filter('fluent_cart/cart/max_quantity', $filter);

        try {
            $this->assertSame(250000, CartHelper::maxQuantity(), 'The filtered ceiling must be used.');
            $this->assertNull(CartHelper::validateQuantity(250000), 'A quantity at the filtered ceiling must be accepted.');
            $this->assertWPError(CartHelper::validateQuantity(250001), 'A quantity above the filtered ceiling must still fail.');
        } finally {
            remove_filter('fluent_cart/cart/max_quantity', $filter);
        }

        $this->assertSame(CartHelper::MAX_QUANTITY, CartHelper::maxQuantity(), 'Removing the filter must restore the default.');
    }

    /** @dataProvider unusableFilteredCeilings */
    public function test_a_filter_cannot_reopen_the_overflow_the_ceiling_guards($filtered, $expected, string $why): void
    {
        $filter = static function () use ($filtered) {
            return $filtered;
        };
        add_filter('fluent_cart/cart/max_quantity', $filter);

        try {
            $this->assertSame($expected, CartHelper::maxQuantity(), $why);
        } finally {
            remove_filter('fluent_cart/cart/max_quantity', $filter);
        }
    }

    public function unusableFilteredCeilings(): array
    {
        return [
            'above the hard limit' => [PHP_INT_MAX, CartHelper::QUANTITY_HARD_LIMIT, 'A ceiling above the hard limit must be capped there.'],
            'overflowing string'   => ['9223372036854775', CartHelper::QUANTITY_HARD_LIMIT, 'An overflowing numeric string must be capped, not trusted.'],
            'zero'                 => [0, CartHelper::MAX_QUANTITY, 'A ceiling below one must fall back to the default.'],
            'negative'             => [-5, CartHelper::MAX_QUANTITY, 'A negative ceiling must fall back to the default.'],
            'fractional'           => [1.5, CartHelper::MAX_QUANTITY, 'A fractional ceiling must fall back to the default.'],
            'non numeric'          => ['lots', CartHelper::MAX_QUANTITY, 'A non-numeric ceiling must fall back to the default.'],
            'null'                 => [null, CartHelper::MAX_QUANTITY, 'A null ceiling must fall back to the default.'],
        ];
    }

    public function test_the_hard_limit_keeps_line_multiplication_safe(): void
    {
        // Even a store that raises the ceiling as far as it goes stays inside the
        // integer range for any price a real catalogue would carry.
        $this->assertLessThan(
            PHP_INT_MAX / CartHelper::QUANTITY_HARD_LIMIT,
            100000000000,
            'A $1,000,000,000 item must still multiply safely at the hard limit.'
        );
    }

    public function test_the_ceiling_keeps_line_multiplication_inside_the_integer_range(): void
    {
        // The guarantee the ceiling exists for: no catalogue price can overflow a line.
        $this->assertLessThan(PHP_INT_MAX / CartHelper::MAX_QUANTITY, 100000000, 'A $1,000,000 item must still multiply safely at the ceiling.');
    }

    public function test_an_overflowing_quantity_never_reaches_the_cart(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->create();

        $this->assertWPError($cart->addByVariation($variation, ['quantity' => '9223372036854775']), 'An overflowing quantity must be rejected.');
        $this->assertSame([], $cart->fresh()->cart_data, 'A rejected quantity must leave the stored cart untouched.');
    }

    public function test_repeated_increments_cannot_accumulate_past_the_ceiling(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $cart = (new CartFactory())->withCartItems([CartHelper::generateCartItemFromVariation($variation, CartHelper::MAX_QUANTITY)])->create();

        $this->assertWPError($cart->addByVariation($variation, ['quantity' => 1]), 'An increment past the ceiling must be rejected.');
        $this->assertSame(CartHelper::MAX_QUANTITY, $cart->fresh()->cart_data[0]['quantity'], 'A rejected increment must preserve the stored quantity.');
    }

    public function test_the_direct_cart_data_write_path_is_also_bounded(): void
    {
        // CartResource::create() assigns cart_data itself instead of going through Cart::addItem().
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $before = Cart::query()->count();

        $result = CartResource::create(['id' => $variation->id, 'quantity' => '9223372036854775']);

        $this->assertWPError($result, 'The direct write path must reject an overflowing quantity.');
        $this->assertSame($before, Cart::query()->count(), 'A rejected add must not persist a cart.');
    }

    public function test_instant_checkout_rejects_an_overflowing_quantity_without_writing_a_cart(): void
    {
        // Instant and modal checkout take their quantity straight from a public URL
        // param and write cart_data without going through the Cart model at all.
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $before = Cart::query()->count();

        $result = CartResource::generateCartForInstantCheckout($variation->id, '9223372036854775');

        $this->assertWPError($result, 'Instant checkout must reject an overflowing quantity.');
        $this->assertSame($before, Cart::query()->count(), 'A rejected instant checkout must not persist a cart.');
    }

    public function test_instant_checkout_still_builds_a_cart_for_a_supported_quantity(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();

        $cart = CartResource::generateCartForInstantCheckout($variation->id, 4);

        $this->assertNotWPError($cart, 'A normal instant checkout must still work.');
        $this->assertSame(4, $cart->cart_data[0]['quantity'], 'The requested quantity must be stored.');
        $this->assertSame(40000, $cart->cart_data[0]['subtotal'], 'The line subtotal must be priced normally.');
    }

    public function test_a_filter_cannot_push_instant_checkout_past_the_ceiling(): void
    {
        // The quantity guard runs after fluent_cart/item_max_quantity, which returns the
        // quantity itself and so can replace it with anything.
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $filter = static function () {
            return '9223372036854775';
        };
        add_filter('fluent_cart/item_max_quantity', $filter);
        $before = Cart::query()->count();

        try {
            $result = CartResource::generateCartForInstantCheckout($variation->id, 1);

            $this->assertWPError($result, 'A filtered quantity must be bounded too.');
            $this->assertSame($before, Cart::query()->count(), 'A rejected filtered quantity must not persist a cart.');
        } finally {
            remove_filter('fluent_cart/item_max_quantity', $filter);
        }
    }

    public function test_an_overflowing_line_fails_the_checkout_instead_of_becoming_free(): void
    {
        // A cart persisted before the ceiling existed: (int) on the wrapped float produced
        // subtotal 0, and FluentCart treats a zero total as an already paid order.
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 10000, 'manage_stock' => 0])->create()->variants->first();
        $item = CartHelper::generateCartItemFromVariation($variation, 1);
        $item['quantity'] = '9223372036854775';
        $item['subtotal'] = 10000 * 9223372036854775;
        $item['line_total'] = $item['subtotal'];
        $before = Order::query()->count();

        $processor = new CheckoutProcessor([$item], ['customer_id' => 1]);

        $this->assertWPError($processor->createDraftOrder(), 'An unsafe line amount must fail the checkout.');
        $this->assertSame($before, Order::query()->count(), 'A rejected amount must not persist an order.');
    }

    public function test_a_genuinely_free_product_still_produces_a_zero_total_order(): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 0, 'manage_stock' => 0])->create()->variants->first();
        $processor = new CheckoutProcessor([CartHelper::generateCartItemFromVariation($variation, 1)], ['customer_id' => 1]);

        $order = $processor->createDraftOrder();

        $this->assertNotWPError($order, 'A genuinely free product must still check out.');
        $this->assertSame(0, (int)$order->total_amount, 'A free product must keep its zero total.');
    }
}
