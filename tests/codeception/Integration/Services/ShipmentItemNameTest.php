<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * The item name a shipment stores and emails must identify the variant shipped.
 */
class ShipmentItemNameTest extends DatabaseTestCase
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

    public function test_split_shipments_of_two_variants_name_each_variant(): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations', 'fulfillment_type' => 'physical'])
            ->createWithVariants([
                ['variation_title' => 'Red / M', 'fulfillment_type' => 'physical'],
                ['variation_title' => 'Blue / L', 'fulfillment_type' => 'physical'],
            ], ['post_title' => 'QA Tee']);
        $variants = $product->variants->values();

        $order = $this->orderWith([
            $this->line($product->ID, $variants[0]->id, 'QA Tee', 'Red / M', 2, 'simple_variations'),
            $this->line($product->ID, $variants[1]->id, 'QA Tee', 'Blue / L', 1, 'simple_variations'),
        ]);
        $items = $order->order_items->sortBy('id')->values();

        $this->mails = [];
        $red = $this->ship($order, $items[0]->id, 2, 'QX-RED');
        $redMails = $this->mails;

        $this->mails = [];
        $blue = $this->ship($order, $items[1]->id, 1, 'QX-BLUE');
        $blueMails = $this->mails;

        $this->assertSame('QA Tee - Red / M', $red->items[0]['item_name']);
        $this->assertSame('QA Tee - Blue / L', $blue->items[0]['item_name']);

        $this->assertCount(1, $redMails);
        $this->assertCount(1, $blueMails);
        $this->assertStringContainsString('QA Tee - Red / M', $redMails[0]['message']);
        $this->assertStringNotContainsString('Blue / L', $redMails[0]['message']);
        $this->assertStringContainsString('QA Tee - Blue / L', $blueMails[0]['message']);
        $this->assertStringNotContainsString('Red / M', $blueMails[0]['message']);
    }

    public function test_attribute_snapshot_names_the_variant_like_order_emails(): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'advanced_variations', 'fulfillment_type' => 'physical'])
            ->createWithVariants([
                ['variation_title' => 'Red', 'fulfillment_type' => 'physical'],
            ], ['post_title' => 'QA Tee']);

        $line = $this->line($product->ID, $product->variants[0]->id, 'QA Tee', 'Red', 1, 'advanced_variations');
        // pa_* groups absent from the store library still render, title-cased.
        $line['other_info']['item_attributes'] = ['pa_qacolor' => ['value' => 'Red']];
        $order = $this->orderWith([$line]);
        $item  = $order->order_items[0];

        $this->mails = [];
        $shipment = $this->ship($order, $item->id, 1, 'QX-ATTR');

        $this->assertSame('Qacolor: Red', $item->variation_display_title);
        $this->assertSame('QA Tee - Qacolor: Red', $shipment->items[0]['item_name']);
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('QA Tee - Qacolor: Red', $this->mails[0]['message']);
    }

    public function test_simple_product_keeps_its_plain_name(): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['variation_title' => 'QA Mug', 'fulfillment_type' => 'physical'])
            ->create(['post_title' => 'QA Mug']);

        $order = $this->orderWith([
            $this->line($product->ID, $product->variants[0]->id, 'QA Mug', 'QA Mug', 1, 'simple'),
        ]);

        $shipment = $this->ship($order, $order->order_items[0]->id, 1, 'QX-MUG');

        $this->assertSame('QA Mug', $shipment->items[0]['item_name']);
    }

    public function test_simple_product_ignores_a_stale_variation_title(): void
    {
        // A renamed simple product keeps its old variation_title.
        $product = (new ProductFactory())
            ->withDetailAttributes(['fulfillment_type' => 'physical'])
            ->withVariantAttributes(['variation_title' => 'Old Mug', 'fulfillment_type' => 'physical'])
            ->create(['post_title' => 'QA Mug']);

        $order = $this->orderWith([
            $this->line($product->ID, $product->variants[0]->id, 'QA Mug', 'Old Mug', 1, 'simple'),
        ]);

        $shipment = $this->ship($order, $order->order_items[0]->id, 1, 'QX-MUG2');

        $this->assertSame('QA Mug', $shipment->items[0]['item_name']);
    }

    private function line(int $postId, int $variantId, string $postTitle, string $title, int $qty, string $variationType): array
    {
        return [
            'post_id'            => $postId,
            'object_id'          => $variantId,
            'post_title'         => $postTitle,
            'title'              => $title,
            'quantity'           => $qty,
            'unit_price'         => 1000,
            'subtotal'           => 1000 * $qty,
            'line_total'         => 1000 * $qty,
            'payment_type'       => 'onetime',
            'fulfillment_type'   => 'physical',
            'fulfilled_quantity' => 0,
            'other_info'         => ['payment_type' => 'onetime', 'variation_type' => $variationType],
        ];
    }

    private function orderWith(array $lines): Order
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->withItems($lines)->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'physical',
            'shipping_status'  => 'unshipped',
            'status'           => 'processing',
            'payment_status'   => 'paid',
        ]);

        return Order::query()->with(['customer', 'order_items'])->find($order->id);
    }

    private function ship(Order $order, int $itemId, int $qty, string $tracking): Shipment
    {
        $shipment = ShipmentService::createShipment(Order::query()->with('customer')->find($order->id), [
            'carrier_slug'    => 'manual',
            'tracking_number' => $tracking,
            'notify_customer' => 'yes',
            'items'           => [['order_item_id' => $itemId, 'qty' => $qty]],
        ]);
        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_message() : '');

        return Shipment::query()->find($shipment->id);
    }
}
