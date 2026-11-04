<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The shipping rows in the order email's items table.
 *
 * Same contract as the thank-you receipt: an order that had money taken off
 * its postage stores the final charge in shipping_total and what was taken in
 * config.shipping_discount, and the email tells the same story checkout did —
 * the real postage price, then the discount as its own row.
 */
class EmailItemsTableShippingTest extends WPTestCase
{
    public function test_a_shipping_discount_renders_the_original_charge_and_its_own_row()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'shipping_total' => 270,
            'total_amount'   => 2270,
            'config'         => ['shipping_discount' => 30],
        ])->create();

        $html = (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]);

        $this->assertStringContainsString('3.00', $html, 'The Shipping row names the price before the discount');
        $this->assertStringContainsString('Shipping Discount', $html, 'The discount gets its own labelled row');
        $this->assertStringContainsString('0.30', $html, 'And the row carries the amount taken');
        $this->assertStringContainsString('Shipping after discount', $html, 'The story closes with what postage actually cost');
        $this->assertStringContainsString('2.70', $html, 'Which is the charge the order stored');
    }

    public function test_an_order_without_a_shipping_discount_renders_the_plain_shipping_row()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id'    => (new CustomerFactory())->create()->id,
            'shipping_total' => 270,
            'total_amount'   => 2270,
        ])->create();

        $html = (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]);

        $this->assertStringContainsString('2.70', $html, 'The stored charge renders as before');
        $this->assertStringNotContainsString('Shipping Discount', $html, 'No discount, no extra row');
        $this->assertStringNotContainsString('Shipping after discount', $html, 'And no after-discount row either');
    }
}
