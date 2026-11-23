<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTaxRateFactory;

/**
 * Markup guards for receipt and email layout overflow. Each asserted style
 * made text spill out of its box in the browser at the widths noted.
 */
class ReceiptLayoutTest extends DatabaseTestCase
{
    // The totals table is 250px wide, so fixed 24%/18% columns cut "Taxable base" and the amounts at every width.
    public function test_the_tax_breakdown_columns_size_to_their_content(): void
    {
        update_option('fluent_cart_tax_configuration_settings', array_merge(
            (array) get_option('fluent_cart_tax_configuration_settings', []),
            ['checkout_tax_breakdown_display' => 'itemized']
        ));
        $order = $this->makeTaxedOrder();

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderOrderItemsFooter();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Taxable base', $html);
        $this->assertStringNotContainsString('table-layout:fixed', $html);
    }

    // Below ~420px the nowrap date and invoice cells pushed the header past the card edge.
    public function test_the_receipt_header_stacks_on_narrow_screens(): void
    {
        $order = $this->makeTaxedOrder();

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->render(true);
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression('/@media \(max-width: 480px\) \{\s*\.fct-receipt-header,/', $html);
        $this->assertStringContainsString('class="fct-receipt-header"', $html);
    }

    // Without a viewport meta, phones lay the standalone receipt page out at 980px and shrink it.
    public function test_the_standalone_page_declares_a_mobile_viewport(): void
    {
        $html = (string) App::view()->make('frontend/index.php')->with([
            'title'     => 'Order Receipt',
            'content'   => '<p>receipt</p>',
            'wp_head'   => false,
            'wp_footer' => false,
        ]);

        $this->assertMatchesRegularExpression('/<head>.*<meta name="viewport" content="width=device-width, initial-scale=1">.*<\/head>\s*<body/s', $html);
    }

    // The brand cell had no padding, so a wrapped store name ran into the order date.
    public function test_the_email_header_keeps_a_gap_after_the_store_name(): void
    {
        $order = $this->makeTaxedOrder();

        $html = (string) App::view()->make('emails.parts.order_header', ['order' => $order]);

        $this->assertMatchesRegularExpression('/<td style="[^"]*padding-right:12px;[^"]*">\s*<h1/', $html);
    }

    // Orders from before the 12-character uuid carry a 32-character one; on a 320px phone it must wrap, not push the header off screen.
    public function test_a_long_order_number_can_wrap_in_the_email_header(): void
    {
        $order = (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);
        Order::query()->where('id', $order->id)->update(['uuid' => md5('legacy-order-' . $order->id)]);
        $order = Order::query()->find($order->id);

        $html = (string) App::view()->make('emails.parts.order_header', ['order' => $order]);

        $this->assertStringContainsString((string) $order->uuid, $html, 'The long order number is printed');
        $this->assertStringNotContainsString('white-space:nowrap', $html, 'No header line is forced onto one line');
        $this->assertMatchesRegularExpression('/<p style="[^"]*word-break:break-all;[^"]*">' . $order->uuid . '<\/p>/', $html, 'The order number may break mid-string');
    }

    // A simple product's item title is its product title, so the variant line repeated the name under itself.
    public function test_a_simple_product_title_is_not_repeated_under_itself(): void
    {
        $order = $this->makeOrderWithItem(['post_title' => 'Ceramic Mug', 'title' => 'Ceramic Mug']);

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderOrderItemsTable();
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, 'Ceramic Mug'), 'The product name must appear once, not again as its own variation line.');
    }

    // A simple product can still carry attributes (e.g. added by a plugin); those must not be hidden with the duplicate.
    public function test_a_simple_product_keeps_its_attribute_line(): void
    {
        $order = $this->makeOrderWithItem([
            'post_title' => 'Ceramic Mug',
            'title'      => 'Ceramic Mug',
            'other_info' => ['payment_type' => 'onetime', 'item_attributes' => ['engraving' => ['value' => 'Hello']]],
        ]);

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderOrderItemsTable();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('engraving: Hello', $html);
    }

    public function test_a_variation_title_still_shows_under_the_product_title(): void
    {
        $order = $this->makeOrderWithItem(['post_title' => 'Cotton T-Shirt', 'title' => 'Large']);

        ob_start();
        (new ReceiptRenderer(['order' => $order]))->renderOrderItemsTable();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('- Large', $html);
    }

    private function makeOrderWithItem(array $itemOverrides): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create($order->id, array_merge(['unit_price' => 20000, 'subtotal' => 20000, 'line_total' => 20000], $itemOverrides));

        return Order::query()->with(['order_items'])->find($order->id);
    }

    private function makeTaxedOrder(): Order
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'tax_behavior' => 1,
            'subtotal'     => 20000,
            'tax_total'    => 3000,
            'total_amount' => 23000,
            'total_paid'   => 23000,
        ]);
        (new OrderItemFactory())->create($order->id, ['unit_price' => 20000, 'subtotal' => 20000, 'line_total' => 20000, 'tax_amount' => 3000]);
        (new OrderTaxRateFactory())->create([
            'order_id'    => $order->id,
            'tax_rate_id' => 1,
            'order_tax'   => 3000,
            'total_tax'   => 3000,
            'meta'        => ['label' => 'VAT', 'rate_percent' => 15, 'taxable_amount' => 20000, 'inclusive' => false],
        ]);

        return Order::query()->with(['order_items', 'orderTaxRates'])->find($order->id);
    }
}
