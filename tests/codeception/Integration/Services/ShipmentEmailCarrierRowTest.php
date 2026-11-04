<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * The per-shipment customer email shows a Carrier row only when the shipment names one.
 */
class ShipmentEmailCarrierRowTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;

    private array $mails = [];

    /** @var callable */
    private $mailCatcher;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        $this->mailCatcher = function ($short, $atts) {
            $this->mails[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailCatcher, PHP_INT_MAX);
        $this->healCommittedRows();
        parent::tearDown();
    }

    public function test_manual_shipment_without_carrier_name_hides_carrier_row(): void
    {
        $message = $this->shipAndCapture(['tracking_number' => 'QX101']);

        $this->assertStringContainsString('Tracking Details', $message);
        $this->assertStringContainsString('QX101', $message);
        $this->assertStringNotContainsString('Carrier', $message);
        $this->assertStringNotContainsString('Not specified', $message);
    }

    public function test_named_carrier_keeps_its_carrier_row(): void
    {
        $message = $this->shipAndCapture(['tracking_number' => 'QX102', 'carrier_name' => 'QA Post']);

        $this->assertStringContainsString('Carrier', $message);
        $this->assertStringContainsString('QA Post', $message);
        $this->assertStringContainsString('QX102', $message);
    }

    public function test_manual_shipment_without_tracking_hides_the_tracking_box(): void
    {
        $message = $this->shipAndCapture(['tracking_number' => '']);

        $this->assertStringNotContainsString('Tracking Details', $message);
        $this->assertStringNotContainsString('Tracking Number', $message);
        $this->assertStringNotContainsString('Track Shipment', $message);
        $this->assertStringContainsString('QA Mug', $message, 'the shipped items still show');
    }

    public function test_no_tracking_info_hides_the_tracking_box(): void
    {
        // A preview or test send has no shipment at all.
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id]);
        $order    = Order::query()->with('customer')->find($order->id);

        $html = (string) App::make('view')->make('emails.order.shipment.customer', ['order' => $order]);

        $this->assertStringNotContainsString('Tracking Details', $html);
        $this->assertStringNotContainsString('Carrier', $html);
    }

    public function test_carrier_service_gets_its_own_row(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id]);
        $order    = Order::query()->with('customer')->find($order->id);

        $shipment = new Shipment(['carrier_name' => 'Shippo']);
        $shipment->meta = ['service_name' => 'UPS — Ground'];
        $html = (string) App::make('view')->make('emails.order.shipment.customer', ['order' => $order, 'shipment' => $shipment]);

        $this->assertStringContainsString('Service', $html);
        $this->assertStringContainsString('UPS — Ground', $html);

        // A service alone is still tracking info worth showing.
        $shipment = new Shipment(['carrier_name' => '']);
        $shipment->meta = ['service_name' => 'UPS — Ground'];
        $html = (string) App::make('view')->make('emails.order.shipment.customer', ['order' => $order, 'shipment' => $shipment]);

        $this->assertStringContainsString('Tracking Details', $html);
        $this->assertStringContainsString('UPS — Ground', $html);
    }

    private function shipAndCapture(array $data): string
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['variation_title' => 'QA Mug', 'fulfillment_type' => 'physical'])
            ->create(['post_title' => 'QA Mug']);

        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->withItems([[
            'post_id'            => $product->ID,
            'object_id'          => $product->variants[0]->id,
            'post_title'         => 'QA Mug',
            'title'              => 'QA Mug',
            'quantity'           => 1,
            'unit_price'         => 1000,
            'subtotal'           => 1000,
            'line_total'         => 1000,
            'payment_type'       => 'onetime',
            'fulfillment_type'   => 'physical',
            'fulfilled_quantity' => 0,
            'other_info'         => ['payment_type' => 'onetime', 'variation_type' => 'simple'],
        ]])->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'physical',
            'shipping_status'  => 'unshipped',
            'status'           => 'processing',
            'payment_status'   => 'paid',
        ]);
        $order = Order::query()->with(['customer', 'order_items'])->find($order->id);

        $this->mails = [];
        $shipment = ShipmentService::createShipment($order, array_merge([
            'carrier_slug'    => 'manual',
            'notify_customer' => 'yes',
            'items'           => [['order_item_id' => $order->order_items[0]->id, 'qty' => 1]],
        ], $data));
        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_message() : '');

        $this->assertCount(1, $this->mails);

        return (string) $this->mails[0]['message'];
    }
}
