<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\RateFixture;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

/**
 * A label is billed in the carrier's currency (the merchant's cost). On an
 * order in another currency the admin also sees an approximate figure in the
 * order's currency, at the order's own frozen rate. Base USD.
 */
class LabelCostOrderCurrencyTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        StubShippingCarrier::install();
    }

    public function tearDown(): void
    {
        StubShippingCarrier::uninstall();
        RateFixture::clear();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_usd_label_on_a_bdt_order_is_estimated_at_the_orders_frozen_rate(): void
    {
        $order = $this->bdtOrder('120', 'manual');
        // A live rate must not win over the rate the order was placed at
        $this->enableMultiCurrency();
        RateFixture::set('BDT', '110');

        $this->assertSame(1104000, ShipmentService::orderCurrencyEstimate($order, 9200, 'USD'), '$92.00 at 120 BDT/USD is ৳11,040.00');
        $this->assertSame(1104000, ShipmentService::orderCurrencyEstimate($order, 9200, 'usd'), 'currency codes are case-insensitive');
    }

    public function test_no_estimate_when_the_label_is_in_the_order_currency(): void
    {
        [$order] = $this->shipmentOrderWithItem(1, ['currency' => 'USD']);

        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 9200, 'USD'));
        $this->assertNull(ShipmentService::orderCurrencyEstimate($this->bdtOrder('120', 'manual'), 9200, 'BDT'));
    }

    public function test_no_estimate_without_a_cost_or_a_currency(): void
    {
        $order = $this->bdtOrder('120', 'manual');

        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 0, 'USD'));
        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 9200, ''));
        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, null, 'USD'));
    }

    public function test_no_estimate_when_the_order_has_no_trusted_rate_and_none_is_current(): void
    {
        $order = $this->bdtOrder('1', 'unknown');
        $this->enableMultiCurrency();

        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 9200, 'USD'), 'rate 1 on a BDT order is a label, not a rate');
    }

    public function test_an_order_without_a_trusted_rate_falls_back_to_the_current_store_rate(): void
    {
        $order = $this->bdtOrder('1', 'unknown');
        $this->enableMultiCurrency();
        RateFixture::set('BDT', '110');

        $this->assertSame(1012000, ShipmentService::orderCurrencyEstimate($order, 9200, 'USD'));
    }

    public function test_the_current_rate_fallback_needs_multi_currency_switched_on(): void
    {
        $order = $this->bdtOrder('1', 'unknown');
        RateFixture::set('BDT', '110');

        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 9200, 'USD'));
    }

    public function test_a_label_in_a_third_currency_converts_only_through_a_known_rate(): void
    {
        $order = $this->bdtOrder('120', 'manual');
        $this->enableMultiCurrency();

        $this->assertNull(ShipmentService::orderCurrencyEstimate($order, 9200, 'EUR'), 'no EUR rate, no estimate');

        RateFixture::set('EUR', '0.5');
        $this->assertSame(2208000, ShipmentService::orderCurrencyEstimate($order, 9200, 'EUR'), '€92 is $184 at 0.5, then ৳22,080 at 120');
    }

    public function test_the_quote_response_carries_the_estimate_per_rate(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('120', 'manual'));
        StubShippingCarrier::$quoteResult = [
            'rate_object_id' => 'r_usd',
            'rates'          => [
                ['rate_object_id' => 'r_usd', 'service_name' => 'Priority', 'cost_cents' => 3397, 'currency' => 'USD', 'estimated_days' => 2],
                ['rate_object_id' => 'r_bdt', 'service_name' => 'Local', 'cost_cents' => 50000, 'currency' => 'BDT'],
            ],
        ];

        $quote = ShipmentService::quoteShipment($order, [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        $this->assertIsArray($quote);
        $this->assertSame(3397, $quote['rates'][0]['cost_cents'], 'the billed amount is untouched');
        $this->assertSame('USD', $quote['rates'][0]['currency']);
        $this->assertSame(407640, $quote['rates'][0]['order_currency_estimate'], '$33.97 at 120 is ৳4,076.40');
        $this->assertSame('BDT', $quote['rates'][0]['order_currency']);
        $this->assertNull($quote['rates'][1]['order_currency_estimate'], 'a rate already in the order currency needs no estimate');
        $this->assertSame('BDT', $quote['rates'][1]['order_currency']);
    }

    public function test_the_order_view_shipments_carry_the_estimate_and_keep_the_stored_cost(): void
    {
        [$order] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('120', 'manual'));
        $label  = $this->storeShipment($order, ['cost' => 9200, 'cost_currency' => 'USD']);
        $manual = $this->storeShipment($order, ['cost' => 50000]);

        $view = OrderResource::view((int) $order->id);
        $shipments = [];
        foreach ($view['order']['shipments'] as $shipment) {
            $shipments[$shipment['id']] = $shipment;
        }

        $this->assertSame(9200, (int) $shipments[$label->id]['meta']['cost'], 'the stored cost stays the carrier amount');
        $this->assertSame('USD', $shipments[$label->id]['meta']['cost_currency']);
        $this->assertSame(1104000, $shipments[$label->id]['order_currency_estimate']);
        $this->assertSame('BDT', $shipments[$label->id]['order_currency']);
        $this->assertNull($shipments[$manual->id]['order_currency_estimate'], 'a cost without a currency is in the order currency');

        $this->assertSame(9200, (int) Shipment::find($label->id)->meta['cost'], 'nothing is written back');
        $this->assertArrayNotHasKey('order_currency_estimate', Shipment::find($label->id)->meta);
    }

    public function test_a_label_shipment_freezes_its_estimate_at_the_orders_rate(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('120', 'manual'));
        $this->enableMultiCurrency();
        RateFixture::set('BDT', '110');
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-F1', 'cost' => 9200, 'currency' => 'USD'];

        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->assertInstanceOf(Shipment::class, $shipment);
        $meta = Shipment::find($shipment->id)->meta;
        $this->assertSame('BDT', $meta['order_currency']);
        $this->assertSame('120.000000000000', $meta['order_currency_rate'], 'BDT per 1 USD, 12 decimals like order snapshots');
        $this->assertSame('order_snapshot', $meta['order_currency_rate_source']);
        $this->assertSame(1104000, $meta['order_currency_estimate']);

        // Neither a new store rate nor a rewritten order snapshot moves it
        RateFixture::set('BDT', '130');
        ExchangeRateService::flushCache();
        $this->rewriteOrderRate($order, '150');

        $this->assertSame(1104000, $this->viewEstimate($order, $shipment->id));
    }

    public function test_an_order_without_a_trusted_rate_freezes_the_current_store_rate(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('1', 'unknown'));
        $this->enableMultiCurrency();
        RateFixture::set('BDT', '110');
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-F2', 'cost' => 9200, 'currency' => 'USD'];

        $shipment = $this->shipThroughStub($order, $item, 1);

        $meta = Shipment::find($shipment->id)->meta;
        $this->assertSame('110.000000000000', $meta['order_currency_rate']);
        $this->assertSame('store_rate', $meta['order_currency_rate_source']);
        $this->assertSame(1012000, $meta['order_currency_estimate']);

        RateFixture::set('BDT', '130');
        ExchangeRateService::flushCache();

        $this->assertSame(1196000, ShipmentService::orderCurrencyEstimate(Order::find($order->id), 9200, 'USD'), 'the live figure did move');
        $this->assertSame(1012000, $this->viewEstimate($order, $shipment->id), 'the shipment keeps its own');
    }

    public function test_a_manually_entered_foreign_cost_is_frozen_too(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('120', 'manual'));
        StubShippingCarrier::$dispatchResult = null;

        $shipment = $this->shipThroughStub($order, $item, 1, ['tracking_number' => 'MAN-1', 'cost' => '92.00', 'cost_currency' => 'USD']);

        $meta = Shipment::find($shipment->id)->meta;
        $this->assertSame(1104000, $meta['order_currency_estimate']);
        $this->assertSame('order_snapshot', $meta['order_currency_rate_source']);
    }

    public function test_a_cost_in_the_order_currency_stores_no_estimate(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1, ['currency' => 'USD']);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-F3', 'cost' => 9200, 'currency' => 'USD'];

        $shipment = $this->shipThroughStub($order, $item, 1);

        $meta = Shipment::find($shipment->id)->meta;
        foreach (['order_currency', 'order_currency_rate', 'order_currency_rate_source', 'order_currency_estimate'] as $key) {
            $this->assertArrayNotHasKey($key, $meta);
        }
        $this->assertNull($this->viewEstimate($order, $shipment->id));
    }

    public function test_a_legacy_shipment_without_a_frozen_estimate_follows_the_current_rate(): void
    {
        [$order] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('1', 'unknown'));
        $this->enableMultiCurrency();
        RateFixture::set('BDT', '110');
        $legacy = $this->storeShipment($order, ['cost' => 9200, 'cost_currency' => 'USD']);

        $this->assertSame(1012000, $this->viewEstimate($order, $legacy->id));

        RateFixture::set('BDT', '130');
        ExchangeRateService::flushCache();
        $this->assertSame(1196000, $this->viewEstimate($order, $legacy->id));
    }

    public function test_the_quoted_estimate_is_the_one_that_gets_frozen(): void
    {
        // An uneven rate, so the two paths must share their rounding
        [$order, $item] = $this->shipmentOrderWithItem(1, $this->bdtOverrides('119.876543210987', 'manual'));
        StubShippingCarrier::$quoteResult = [
            'rate_object_id' => 'r_usd',
            'rates'          => [['rate_object_id' => 'r_usd', 'service_name' => 'Priority', 'cost_cents' => 3397, 'currency' => 'USD']],
        ];
        $quote = ShipmentService::quoteShipment($order, [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-F4', 'cost' => 3397, 'currency' => 'USD'];

        $shipment = $this->shipThroughStub($order, $item, 1, ['selected_rate_id' => 'r_usd']);

        $this->assertSame(407221, $quote['rates'][0]['order_currency_estimate'], '$33.97 at 119.8765... is ৳4,072.21');
        $this->assertSame($quote['rates'][0]['order_currency_estimate'], Shipment::find($shipment->id)->meta['order_currency_estimate']);
    }

    private function viewEstimate(Order $order, int $shipmentId)
    {
        $view = OrderResource::view((int) $order->id);
        foreach ($view['order']['shipments'] as $shipment) {
            if ((int) $shipment['id'] === $shipmentId) {
                return $shipment['order_currency_estimate'];
            }
        }
        $this->fail('shipment missing from the order view');
    }

    private function rewriteOrderRate(Order $order, string $rate): void
    {
        $fresh  = Order::find($order->id);
        $config = (array) $fresh->config;
        $config['fx']['rate'] = $rate;
        $fresh->config = $config;
        $fresh->rate   = $rate;
        $fresh->save();
        $this->assertSame($rate, (string) CurrencySnapshot::fromOrder(Order::find($order->id))['rate'], 'the snapshot was rewritten');
    }

    private function bdtOverrides(string $rate, string $source): array
    {
        return [
            'currency' => 'BDT',
            'rate'     => $rate,
            'config'   => ['fx' => [
                'currency'      => 'BDT',
                'base_currency' => 'USD',
                'rate'          => $rate,
                'source'        => $source,
                'rounding'      => 'none',
            ]],
        ];
    }

    private function bdtOrder(string $rate, string $source): Order
    {
        [$order] = $this->shipmentOrderWithItem(1, $this->bdtOverrides($rate, $source));

        return $order;
    }

    private function enableMultiCurrency(): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'BDT'], ['code' => 'EUR']]]);
        MultiCurrencySettings::clearCache();
    }

    private function storeShipment(Order $order, array $meta): Shipment
    {
        $shipment = new Shipment([
            'carrier_slug'    => StubShippingCarrier::SLUG,
            'carrier_name'    => 'Stub',
            'tracking_number' => 'TRK-' . wp_generate_password(6, false),
            'items'           => [],
            'shipped_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        $shipment->order_id = $order->id;
        $shipment->status   = Shipment::STATUS_DISPATCHED;
        $shipment->meta     = $meta;
        $shipment->save();

        return $shipment;
    }
}
