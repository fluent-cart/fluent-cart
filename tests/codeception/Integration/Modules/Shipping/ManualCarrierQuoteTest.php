<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Shipping\ManualQuoteTestSettings;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * Manual's quote is the store's own zone methods priced for the items being
 * dispatched, in the order's currency. It buys nothing; it only fills the cost.
 */
class ManualCarrierQuoteTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;

    public function test_returns_zone_methods_mapped_to_quote_shape(): void
    {
        $zone   = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'  => $zone->id,
            'title'    => 'Standard',
            'amount'   => 10,
            'settings' => ['configure_rate' => 'per_order'],
        ]);
        [$order, $item] = $this->shipmentOrderWithItem(1);

        $quote = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], ['carrier_slug' => 'manual'])
        );

        $this->assertIsArray($quote, 'quote must be an array of rate data');
        $this->assertCount(1, $quote['rates'], 'only the configured zone method should be offered');
        $rate = $quote['rates'][0];
        $this->assertSame((string) $method->id, $rate['rate_object_id'], 'rate id is the zone method id');
        $this->assertSame('Standard', $rate['service_name'], 'service name is the method title');
        $this->assertSame(1000, $rate['cost_cents'], 'a $10 per-order method prices to 1000 cents');
        $this->assertSame(strtoupper((string) (new StoreSettings())->get('currency')), $rate['currency'], 'a base-currency order quotes in the store currency');
    }

    /** Spec: Cases/Integration/shipping.md [176] */
    public function test_rates_carry_the_method_tracking_url_pattern(): void
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        (new ShippingMethodFactory())->create([
            'zone_id' => $zone->id,
            'title'   => 'Courier',
            'meta'    => ['tracking_url_pattern' => 'https://courier.example/t/{tracking_number}'],
        ]);
        (new ShippingMethodFactory())->create(['zone_id' => $zone->id, 'title' => 'Plain']);
        [$order, $item] = $this->shipmentOrderWithItem(1);

        $quote = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );

        $patterns = array_column($quote['rates'], 'tracking_url_pattern', 'service_name');
        $this->assertSame('https://courier.example/t/{tracking_number}', $patterns['Courier'] ?? null, 'the drawer prefills from this pattern');
        $this->assertSame('', $patterns['Plain'] ?? null, 'a method without a pattern sends an empty one');
    }

    public function test_partial_dispatch_is_pro_rated(): void
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        (new ShippingMethodFactory())->create([
            'zone_id'  => $zone->id,
            'title'    => 'Per Item',
            'amount'   => 10,
            'settings' => ['configure_rate' => 'per_item'],
        ]);
        [$order, $item] = $this->shipmentOrderWithItem(4);
        $carrier = new ManualCarrier(new ManualQuoteTestSettings());

        $partial = $carrier->getShipmentQuote(new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 2]], []));
        $full    = $carrier->getShipmentQuote(new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 4]], []));

        $this->assertSame(2000, $partial['cost_cents'], '2 of 4 per-item units must price to 2000 cents');
        $this->assertSame(4000, $full['cost_cents'], 'all 4 units must price to 4000 cents');
    }

    public function test_non_base_currency_order_quotes_at_its_frozen_rate(): void
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        (new ShippingMethodFactory())->create([
            'zone_id'  => $zone->id,
            'title'    => 'Standard',
            'amount'   => 10,
            'settings' => ['configure_rate' => 'per_order'],
        ]);
        [$order, $item] = $this->shipmentOrderWithItem(1, [
            'currency' => 'EUR',
            'rate'     => '0.5',
            'config'   => ['fx' => [
                'currency'      => 'EUR',
                'base_currency' => 'USD',
                'rate'          => '0.5',
                'source'        => 'manual',
                'rounding'      => 'none',
            ]],
        ]);

        $quote = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );

        $this->assertSame('EUR', $quote['rates'][0]['currency'], 'a customer-facing manual quote is in the order currency');
        $this->assertSame(500, $quote['rates'][0]['cost_cents'], 'a $10 method at the order rate 0.5 is EUR 5.00, not a live rate');
    }

    public function test_disabled_carrier_returns_null(): void
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        (new ShippingMethodFactory())->create(['zone_id' => $zone->id, 'title' => 'Standard', 'amount' => 10]);
        [$order, $item] = $this->shipmentOrderWithItem(1);

        $quote = (new ManualCarrier(new ManualQuoteTestSettings(false)))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );

        $this->assertNull($quote, 'a disabled manual carrier returns null, not an error');
    }

    public function test_no_address_returns_wp_error(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, [], false);

        $result = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );

        $this->assertWPError($result, 'an order without an address cannot be quoted');
        $this->assertSame('manual_no_address', $result->get_error_code(), 'wrong error code');
    }

    public function test_no_applicable_rates_returns_wp_error(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);

        $result = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );

        $this->assertWPError($result, 'no zone method means no quote');
        $this->assertSame('manual_no_rates', $result->get_error_code(), 'wrong error code');
    }

    public function test_state_limited_methods_are_filtered_by_address_state(): void
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $methods = new ShippingMethodFactory();
        $methods->create(['zone_id' => $zone->id, 'title' => 'CA Only', 'amount' => 10, 'states' => ['CA']]);
        $methods->create(['zone_id' => $zone->id, 'title' => 'NY Only', 'amount' => 10, 'states' => ['NY']]);
        $methods->create(['zone_id' => $zone->id, 'title' => 'Everywhere', 'amount' => 10, 'states' => []]);
        [$order, $item] = $this->shipmentOrderWithItem(1);

        $quote  = (new ManualCarrier(new ManualQuoteTestSettings()))->getShipmentQuote(
            new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], [])
        );
        $titles = array_column($quote['rates'], 'service_name');

        $this->assertContains('CA Only', $titles, 'a CA-only method is offered to a CA address');
        $this->assertContains('Everywhere', $titles, 'an unrestricted method is always offered');
        $this->assertNotContains('NY Only', $titles, 'an NY-only method is not offered to a CA address');
    }
}
