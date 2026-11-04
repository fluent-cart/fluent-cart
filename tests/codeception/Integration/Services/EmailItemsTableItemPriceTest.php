<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;

/**
 * Item prices in the order email's items table.
 *
 * A discounted line stores the original in subtotal and what was paid in
 * line_total. The email shows both — the original struck through, then the
 * paid price — the same story the thank-you receipt tells.
 */
class EmailItemsTableItemPriceTest extends WPTestCase
{
    public function test_a_discounted_item_shows_the_struck_original_before_the_paid_price()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->create();

        (new OrderItemFactory())->create($order->id, [
            'subtotal'       => 10000,
            'discount_total' => 1000,
            'line_total'     => 9000,
        ]);

        $html = (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]);

        // Asserted on the rendered markup rather than a class: email HTML is
        // sanitized on its way out and class attributes do not survive, while
        // the inline style every mail client needs does.
        $this->assertMatchesRegularExpression(
            '/line-through[^>]*>[^<]*100\.00\s*<\/span>[^<]*90\.00/',
            preg_replace('/\s+/', ' ', $html),
            'The struck original is printed immediately before the price paid'
        );
    }

    // On a phone the struck original and the paid price must be able to stack,
    // or the two prices side by side push the card wider than the screen.
    public function test_a_discounted_price_cell_can_wrap_on_narrow_screens()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->create();

        (new OrderItemFactory())->create($order->id, [
            'subtotal'       => 12345678,
            'discount_total' => 1234567,
            'line_total'     => 11111111,
        ]);

        $html = preg_replace('/\s+/', ' ', (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]));

        $this->assertMatchesRegularExpression(
            '/<td style="([^"]*)"> <p[^>]*> <span style="text-decoration:line-through/',
            $html,
            'The discounted price cell is rendered'
        );
        preg_match('/<td style="([^"]*)"> <p[^>]*> <span style="text-decoration:line-through/', $html, $priceCell);
        $this->assertStringNotContainsString('white-space:nowrap', $priceCell[1], 'The price cell lets the two prices stack');
    }

    // A store's own checkout label for an offline method can be long; its badge must wrap
    // on a phone instead of pushing the card wider than the screen.
    public function test_a_long_payment_method_badge_can_wrap_on_narrow_screens()
    {
        $longTitle = 'Cash on Delivery - pay the courier at your door';
        $order = (new OrderFactory())->withAttributes([
            'customer_id'          => (new CustomerFactory())->create()->id,
            'payment_method'       => 'offline_payment',
            'payment_method_title' => $longTitle,
        ])->create();
        (new OrderItemFactory())->create($order->id);
        (new OrderTransactionFactory())->create($order->id, ['payment_method' => 'offline_payment']);

        $html = preg_replace('/\s+/', ' ', (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]));

        $this->assertMatchesRegularExpression(
            '/<td style="([^"]*)"> <p[^>]*> <span style="([^"]*)">' . preg_quote($longTitle, '/') . '<\/span>/',
            $html,
            'The payment badge shows the stored checkout title'
        );
        preg_match('/<td style="([^"]*)"> <p[^>]*> <span style="([^"]*)">' . preg_quote($longTitle, '/') . '<\/span>/', $html, $badge);
        $this->assertStringNotContainsString('white-space:nowrap', $badge[1], 'The payment cell lets the badge wrap');
        $this->assertStringContainsString('max-width:100%', $badge[2], 'The badge never grows wider than its cell');
    }

    public function test_an_undiscounted_item_shows_only_its_price()
    {
        $order = (new OrderFactory())->withAttributes([
            'customer_id' => (new CustomerFactory())->create()->id,
        ])->create();

        (new OrderItemFactory())->create($order->id, [
            'subtotal'       => 10000,
            'discount_total' => 0,
            'line_total'     => 10000,
        ]);

        $html = (string) App::make('view')->make('emails.parts.items_table', ['order' => $order]);

        $this->assertStringContainsString('100.00', $html, 'The price renders as before');
        $this->assertDoesNotMatchRegularExpression(
            '/line-through[^>]*>[^<]*100\.00\s*<\/span>/',
            preg_replace('/\s+/', ' ', $html),
            'No discount, no struck original'
        );
    }
}
