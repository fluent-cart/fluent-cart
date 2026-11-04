<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Renderer\Receipt\ThankYouRender;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * Item prices on the thank-you receipt.
 *
 * A discounted line stores the original in subtotal and what was paid in
 * line_total. The receipt shows both — the original struck through, then the
 * discounted price — so the customer sees what the discount was worth, the
 * same way the line looked at checkout.
 */
class ThankYouRenderItemPriceTest extends WPTestCase
{
    public function test_a_discounted_item_shows_the_struck_original_before_the_paid_price()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->withTransaction()->create();

        (new OrderItemFactory())->create($order->id, [
            'subtotal'       => 10000,
            'discount_total' => 1000,
            'line_total'     => 9000,
        ]);

        ob_start();
        (new ThankYouRender(['order' => $order]))->renderOrderItemsBody();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct-thank-you-page-order-items-list-price-original', $html, 'The original price gets its own marked element');
        $this->assertStringContainsString('100.00', $html, 'It names the price before the discount');
        $this->assertStringContainsString('90.00', $html, 'And the paid price still renders');
    }

    public function test_an_undiscounted_item_shows_only_its_price()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->withTransaction()->create();

        (new OrderItemFactory())->create($order->id, [
            'subtotal'       => 10000,
            'discount_total' => 0,
            'line_total'     => 10000,
        ]);

        ob_start();
        (new ThankYouRender(['order' => $order]))->renderOrderItemsBody();
        $html = ob_get_clean();

        $this->assertStringContainsString('100.00', $html, 'The price renders as before');
        $this->assertStringNotContainsString('fct-thank-you-page-order-items-list-price-original', $html, 'No discount, no struck original');
    }
}
