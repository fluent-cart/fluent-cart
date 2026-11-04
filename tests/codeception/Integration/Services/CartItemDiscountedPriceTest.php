<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Renderer\CartItemRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * Item prices on the checkout summary.
 *
 * A discounted line shows what it was worth before the discount, struck
 * through, then what it costs now — the same story the thank-you receipt
 * tells. The discount can come from a coupon, a renewal credit, or a pricing
 * rule; the line only knows that it is worth less than its subtotal.
 */
class CartItemDiscountedPriceTest extends WPTestCase
{
    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/cart/item_dynamic_discount');

        parent::tearDown();
    }

    public function test_an_item_discounted_outside_a_coupon_shows_both_prices()
    {
        // manual_discount is where renewal credits and pricing rules land —
        // coupon_discount stays zero, which used to hide the struck price.
        $item = CartFactory::makeCartItem([
            'unit_price'      => 10000,
            'manual_discount' => 1000,
            'discount_total'  => 1000,
            'line_total'      => 9000,
        ]);

        ob_start();
        (new CartItemRenderer($item))->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct_line_item_original_price', $html, 'The subtotal is marked as the struck original');
        $this->assertStringContainsString('100.00', $html, 'It names the price before the discount');
        $this->assertStringContainsString('90.00', $html, 'And the discounted price renders beside it');
    }

    public function test_a_coupon_discounted_item_still_renders_the_coupon_pair()
    {
        $item = CartFactory::makeCartItem([
            'unit_price'      => 10000,
            'coupon_discount' => 1000,
            'discount_total'  => 1000,
            'line_total'      => 9000,
        ]);

        ob_start();
        (new CartItemRenderer($item))->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct_line_item_original_price', $html, 'A coupon line still strikes its original');
        $this->assertStringContainsString('fct_line_item_discounted_price', $html, 'And still carries its discounted row');
        $this->assertStringContainsString('100.00', $html, 'The price before the coupon');
        $this->assertStringContainsString('90.00', $html, 'And the price after it');
    }

    public function test_the_former_class_names_are_still_emitted_for_existing_store_css()
    {
        $item = CartFactory::makeCartItem([
            'unit_price'      => 10000,
            'coupon_discount' => 1000,
            'discount_total'  => 1000,
            'line_total'      => 9000,
        ]);

        ob_start();
        (new CartItemRenderer($item))->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct_coupon_original_price', $html, 'Store CSS written against the old struck-price class keeps working');
        $this->assertStringContainsString('fct_coupon_discounted_price', $html, 'And against the old discounted-price class');
    }

    public function test_an_undiscounted_item_shows_only_its_price()
    {
        $item = CartFactory::makeCartItem(['unit_price' => 10000]);

        ob_start();
        (new CartItemRenderer($item))->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('100.00', $html, 'The price renders as before');
        $this->assertStringNotContainsString('fct_line_item_original_price', $html, 'No discount, no struck original');
    }

    public function test_the_summary_renders_the_items_a_dynamic_discount_filter_returns()
    {
        $cart = (new CartFactory())->withCartItems([
            CartFactory::makeCartItem(['unit_price' => 10000]),
        ])->create();

        // Stands in for the pricing module: the summary must show the items the
        // filter hands back, not the raw stored ones, or the line prices
        // disagree with the total the same filter already feeds.
        add_filter('fluent_cart/cart/item_dynamic_discount', function ($items) {
            $items[0]['manual_discount'] = 1000;
            $items[0]['discount_total'] = 1000;
            $items[0]['line_total'] = 9000;

            return $items;
        });

        ob_start();
        (new CartSummaryRender($cart))->renderItemsLists();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct_line_item_original_price', $html, 'The filtered discount reaches the line');
        $this->assertStringContainsString('100.00', $html, 'The original price is struck through');
        $this->assertStringContainsString('90.00', $html, 'And the discounted price is shown');
    }
}
