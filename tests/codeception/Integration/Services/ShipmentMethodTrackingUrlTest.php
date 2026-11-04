<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * Spec: Cases/Integration/shipping.md → "Shipment tracking URL", items 173-177.
 */
class ShipmentMethodTrackingUrlTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;
    use BuildsShipmentOrders;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        // A value older versions saved on the carrier; it must be ignored.
        $this->setCarrierPattern('https://carrier.example/t/{tracking_number}');
    }

    public function tearDown(): void
    {
        $this->setCarrierPattern('');
        $this->healCommittedRows();
        parent::tearDown();
    }

    private function setCarrierPattern(string $pattern): void
    {
        CarrierManager::carrier(Shipment::CARRIER_MANUAL)->getSettings()->save(['tracking_url_pattern' => $pattern]);
    }

    private function methodWithPattern(string $pattern): int
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $meta = $pattern !== '' ? ['tracking_url_pattern' => $pattern] : [];

        return (int) (new ShippingMethodFactory())->create(['zone_id' => (int) $zone->id, 'meta' => $meta])->id;
    }

    private function shipManual(int $methodId, array $extra = [])
    {
        [$order, $item] = $this->shipmentOrderWithItem();

        return ShipmentService::createShipment($order, array_merge([
            'carrier_slug'     => Shipment::CARRIER_MANUAL,
            'tracking_number'  => 'TRK 1',
            'selected_rate_id' => (string) $methodId,
            'items'            => [['order_item_id' => $item->id, 'qty' => 1]],
        ], $extra));
    }

    /** Spec [173] */
    public function test_method_pattern_builds_the_tracking_url(): void
    {
        $shipment = $this->shipManual($this->methodWithPattern('https://method.example/track?n={tracking_number}'));

        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_code() : '');
        $this->assertSame('https://method.example/track?n=TRK%201', (string) $shipment->tracking_url);
    }

    /** Spec [174] */
    public function test_method_without_pattern_gives_no_tracking_url(): void
    {
        $shipment = $this->shipManual($this->methodWithPattern(''));

        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_code() : '');
        $this->assertSame('', (string) $shipment->tracking_url, 'the carrier-level pattern is no longer a fallback');
    }

    /** Spec [175] */
    public function test_typed_url_wins_over_the_method_pattern(): void
    {
        $shipment = $this->shipManual(
            $this->methodWithPattern('https://method.example/track?n={tracking_number}'),
            ['tracking_url' => 'https://typed.example/abc']
        );

        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_code() : '');
        $this->assertSame('https://typed.example/abc', (string) $shipment->tracking_url);
    }

    /** Spec [176] */
    public function test_manual_quote_response_carries_the_method_pattern(): void
    {
        $methodId = $this->methodWithPattern('https://method.example/track?n={tracking_number}');
        [$order, $item] = $this->shipmentOrderWithItem();

        $quote = ShipmentService::quoteShipment($order, [
            'carrier_slug' => Shipment::CARRIER_MANUAL,
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        $this->assertIsArray($quote, is_wp_error($quote) ? $quote->get_error_code() : '');
        $patterns = array_column($quote['rates'], 'tracking_url_pattern', 'rate_object_id');
        $this->assertSame('https://method.example/track?n={tracking_number}', $patterns[(string) $methodId] ?? null);
    }

    /** Spec [177] */
    public function test_other_carriers_quote_response_has_no_pattern(): void
    {
        StubShippingCarrier::install();
        try {
            [$order, $item] = $this->shipmentOrderWithItem();
            StubShippingCarrier::$quoteResult = [
                'rate_object_id' => 'r1',
                'rates'          => [[
                    'rate_object_id'       => 'r1',
                    'service_name'         => 'Priority',
                    'cost_cents'           => 500,
                    'currency'             => 'USD',
                    'tracking_url_pattern' => 'https://stub.example/{tracking_number}',
                ]],
            ];

            $quote = ShipmentService::quoteShipment($order, [
                'carrier_slug' => StubShippingCarrier::SLUG,
                'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
            ]);

            $this->assertIsArray($quote, is_wp_error($quote) ? $quote->get_error_code() : '');
            $this->assertArrayNotHasKey('tracking_url_pattern', $quote['rates'][0], 'only Manual rates carry a pattern');
        } finally {
            StubShippingCarrier::uninstall();
        }
    }
}
