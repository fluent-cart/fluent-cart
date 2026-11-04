<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\DateTime\DateFormatter;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * {{shipment.*}}, {{order.shipments}} and the shipment email parts.
 */
class ShipmentSmartcodeTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;

    private function makeShipment(Order $order, int $itemId, array $overrides = []): Shipment
    {
        $shipment = new Shipment();
        $shipment->forceFill(array_merge([
            'order_id'        => $order->id,
            'carrier_slug'    => 'manual',
            'carrier_name'    => 'DHL',
            'tracking_number' => 'S3-TEST-001',
            'tracking_url'    => 'https://track.example/?n=S3&x=1',
            'status'          => Shipment::STATUS_IN_TRANSIT,
            'items'           => [['order_item_id' => $itemId, 'qty' => 2, 'item_name' => 'Widget']],
            'notes'           => "Left at door\nThanks",
            'notify_customer' => 1,
            'shipped_at'      => '2026-03-04 10:00:00',
            'meta'            => ['service_name' => 'Express Worldwide'],
        ], $overrides));
        $shipment->save();

        return Shipment::find($shipment->id);
    }

    private function render(string $template, array $data): string
    {
        return (string) ShortcodeTemplateBuilder::make($template, $data);
    }

    public function test_shipment_codes_resolve_from_the_payload_shipment(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id);
        $data = ['order' => $order, 'shipment' => $shipment];

        $this->assertSame('DHL', $this->render('{{shipment.carrier_name}}', $data), 'carrier name');
        $this->assertSame('Express Worldwide', $this->render('{{shipment.service_name}}', $data), 'service name comes from meta.service_name');
        $this->assertSame('S3-TEST-001', $this->render('{{shipment.tracking_number}}', $data), 'tracking number');
        $this->assertSame('In Transit', $this->render('{{shipment.status}}', $data), 'status renders its label');
        $this->assertSame('1', $this->render('{{shipment.item_count}}', $data), 'item count is the number of shipment lines');
        $this->assertSame((string) $shipment->id, $this->render('{{shipment.id}}', $data), 'shipment id');
        $this->assertSame('Left at door<br />' . "\n" . 'Thanks', $this->render('{{shipment.notes}}', $data), 'notes keep line breaks');
        $this->assertStringContainsString('Widget', $this->render('{{shipment.items}}', $data), 'items table lists the shipped item');
        $this->assertSame('', $this->render('{{shipment.order_id}}', $data), 'codes outside the documented set resolve to nothing');
    }

    public function test_shipped_at_follows_the_store_date_format(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id);

        $expected = DateFormatter::format('2026-03-04 10:00:00', false, $order);

        $this->assertNotSame('', $expected, 'the formatter must produce a date');
        $this->assertSame(esc_html($expected), $this->render('{{shipment.shipped_at}}', ['order' => $order, 'shipment' => $shipment]));
    }

    public function test_tracking_values_are_escaped(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id, [
            'tracking_number' => '<b>TRK</b>',
            'tracking_url'    => 'javascript:alert(1)',
            'carrier_name'    => '<script>x</script>DHL',
        ]);
        $data = ['order' => $order, 'shipment' => $shipment];

        $this->assertSame('&lt;b&gt;TRK&lt;/b&gt;', $this->render('{{shipment.tracking_number}}', $data), 'tracking number is escaped');
        $this->assertSame('', $this->render('{{shipment.tracking_url}}', $data), 'a non-http tracking URL is dropped');
        $this->assertSame('&lt;b&gt;TRK&lt;/b&gt;', $this->render('{{shipment.tracking_link}}', $data), 'no anchor without a safe URL');
        $this->assertStringNotContainsString('<script>', $this->render('{{shipment.carrier_name}}', $data), 'carrier name is escaped');
    }

    public function test_tracking_link_wraps_the_number_in_the_url(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id);

        $link = $this->render('{{shipment.tracking_link}}', ['order' => $order, 'shipment' => $shipment]);

        $this->assertStringContainsString('href="https://track.example/?n=S3&#038;x=1"', $link, 'the URL is escaped for the attribute');
        $this->assertStringContainsString('>S3-TEST-001</a>', $link, 'the number is the link text');
    }

    public function test_shipment_codes_are_empty_without_a_shipment(): void
    {
        [$order] = $this->shipmentOrderWithItem(3);

        $this->assertSame('', $this->render('{{shipment.carrier_name}}{{shipment.items}}{{shipment.shipped_at}}', ['order' => $order]), 'a preview without a shipment renders nothing');
    }

    public function test_the_array_form_of_a_shipment_also_resolves(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id);

        $data = ['order' => $order, 'shipment' => $shipment->toArray()];

        $this->assertSame('Express Worldwide', $this->render('{{shipment.service_name}}', $data));
        $this->assertSame('1', $this->render('{{shipment.item_count}}', $data));
    }

    public function test_order_shipments_lists_every_shipment(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $this->makeShipment($order, $item->id);
        $this->makeShipment($order, $item->id, [
            'carrier_name'    => 'UPS',
            'tracking_number' => 'UPS-2',
            'status'          => Shipment::STATUS_DELIVERED,
            'meta'            => [],
        ]);

        $html = $this->render('{{order.shipments}}', ['order' => Order::find($order->id)]);

        $this->assertStringContainsString('DHL', $html, 'first shipment carrier');
        $this->assertStringContainsString('Express Worldwide', $html, 'service name row');
        $this->assertStringContainsString('UPS-2', $html, 'second shipment tracking number');
        $this->assertStringContainsString('Delivered', $html, 'status label');
        $this->assertStringContainsString(esc_html(DateFormatter::format('2026-03-04 10:00:00', false, $order)), $html, 'shipped date in the store format');
        $this->assertLessThan(strpos($html, 'UPS-2'), strpos($html, 'S3-TEST-001'), 'shipments are listed oldest first');
        $this->assertStringNotContainsString('padding-left', $html, 'padding is logical, not physical');
        $this->assertStringNotContainsString('padding-right', $html, 'padding is logical, not physical');
    }

    public function test_order_shipments_is_empty_when_nothing_shipped(): void
    {
        [$order] = $this->shipmentOrderWithItem(3);

        $this->assertSame('', $this->render('{{order.shipments}}', ['order' => $order]));
    }

    public function test_shipment_email_renders_the_shared_items_part(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        $shipment = $this->makeShipment($order, $item->id);

        $html = (string) App::make('view')->make('emails.order.shipment.customer', [
            'order'    => Order::find($order->id),
            'shipment' => $shipment,
        ]);
        $part = (string) App::make('view')->make('emails.parts.shipment_items', ['items' => $shipment->items]);

        $this->assertNotSame('', trim($part), 'the items part renders');
        $this->assertStringContainsString(trim($part), $html, 'the email body embeds the shared items part');

        // The shared address and call-to-action parts follow the <hr />.
        $shipmentBlock = strstr($html, '<hr />', true);
        $this->assertNotFalse($shipmentBlock, 'the body has its address divider');
        $this->assertStringNotContainsString('padding-left', $shipmentBlock, 'padding is logical, not physical');
        $this->assertStringNotContainsString('padding-right', $shipmentBlock, 'padding is logical, not physical');
    }
}
