<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Meta;
use FluentCart\App\Services\PrintService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;

/**
 * The print routes (invoice, packing slip, ...) render the saved template for one order, found by its uuid.
 */
class PrintServiceTest extends DatabaseTestCase
{
    // A PDF plugin hooked here would make print() die() and stop the whole test run.
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_actions('fluent_pdf_download');
    }

    // The invoice template's items table calls Order model methods, so the order must reach it as a model, not an array.
    public function test_the_invoice_renders_the_order_items(): void
    {
        $order = $this->makeOrderWithItem();

        ob_start();
        $html = (string) PrintService::invoice($order->uuid);
        $html .= (string) ob_get_clean();

        $this->assertStringContainsString('Ceramic Mug', $html);
    }

    // Each print route (print-invoice, print-packing-slip, ...) maps to one of these methods.
    public function test_every_print_template_renders_by_uuid(): void
    {
        $order = $this->makeOrderWithItem();

        foreach (['invoice', 'packingSlip', 'deliverySlip', 'shippingSlip', 'dispatchSlip'] as $method) {
            ob_start();
            $html = (string) PrintService::$method($order->uuid);
            $html .= (string) ob_get_clean();

            $this->assertStringContainsString('Ceramic Mug', $html, $method . ' must render the order item.');
        }
    }

    // The receipt links use the order uuid; the numeric id is no longer accepted.
    public function test_the_numeric_order_id_is_not_accepted(): void
    {
        $order = $this->makeOrderWithItem();

        [$result, $status, $html] = $this->printAndCapture($order->id);

        $this->assertFalse($result);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('Order not found', $html);
    }

    public function test_an_unknown_order_gets_a_not_found_page(): void
    {
        [$result, $status, $html] = $this->printAndCapture('NOSUCHUUID00');

        $this->assertFalse($result);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('Order not found', $html);
    }

    public function test_a_buyer_value_cannot_add_an_attribute_to_the_print_page(): void
    {
        $customer = (new CustomerFactory())->create(['first_name' => 'Ann" data-injected="1']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        Meta::query()->create([
            'meta_key'   => 'invoice_template',
            'meta_value' => '<img src="x.png" alt="{{order.customer.first_name}}">',
        ]);

        ob_start();
        $html = (string) PrintService::invoice($order->uuid);
        $html .= (string) ob_get_clean();

        $img = new \WP_HTML_Tag_Processor($html);
        $this->assertTrue($img->next_tag(['tag_name' => 'img']));
        $this->assertSame(['src', 'alt'], $img->get_attribute_names_with_prefix(''));
        $this->assertSame('Ann" data-injected="1', $img->get_attribute('alt'));
    }

    // Returns [result, HTTP status set, page output] for an invoice print request.
    private function printAndCapture($orderRef): array
    {
        $status = null;
        $capture = function ($header, $code) use (&$status) {
            $status = $code;
            return $header;
        };

        add_filter('status_header', $capture, 10, 2);
        ob_start();
        try {
            $result = PrintService::invoice($orderRef);
        } finally {
            $html = (string) ob_get_clean();
            remove_filter('status_header', $capture, 10);
        }

        return [$result, $status, $html];
    }

    private function makeOrderWithItem()
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create($order->id, ['post_title' => 'Ceramic Mug', 'title' => 'Ceramic Mug']);

        return $order;
    }
}
