<?php

namespace Tests\Functional\Rest\Shipping;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * POST /orders/{id}/shipments/quote prices a shipment without creating it. It
 * spends the store's carrier quota, so it is gated like the create route, and
 * only the order's own physical items (deduped) may reach the carrier.
 */
class ShipmentQuoteCest
{
    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    public function quotePayloadIsBoundedToTheOrdersOwnPhysicalItems(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer    = (new CustomerFactory())->create();
        $itemFactory = new OrderItemFactory();
        $order       = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $physical    = $itemFactory->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 5]);
        $digital     = $itemFactory->create($order->id, ['fulfillment_type' => 'digital', 'quantity' => 1]);
        $foreign     = $itemFactory->create((new OrderFactory())->create(['customer_id' => $customer->id])->id, ['fulfillment_type' => 'physical', 'quantity' => 9]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [
                ['order_item_id' => (int) $physical->id, 'qty' => 2],
                ['order_item_id' => (int) $physical->id, 'qty' => 3],
                ['order_item_id' => (int) $digital->id, 'qty' => 1],
                ['order_item_id' => (int) $foreign->id, 'qty' => 4],
                ['order_item_id' => 999999, 'qty' => 1],
            ],
        ]);

        Assert::assertSame(200, $status, 'a valid quote request must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('stub_rate', $body['quote']['rates'][0]['rate_object_id'] ?? null, 'the carrier rates must be returned');
        $seen = StubShippingCarrier::$lastQuoteItems;
        Assert::assertCount(1, $seen, 'only the order-scoped physical item, deduped, may reach the carrier');
        Assert::assertSame((int) $physical->id, (int) $seen[0]['order_item_id'], 'the surviving item must be the physical order line');
        Assert::assertSame(2, (int) $seen[0]['qty'], 'the first occurrence of a duplicated id wins');
    }

    public function workerCanQuoteAShipment(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $item     = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2]);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(200, $status, 'a worker holds orders/manage_statuses and may quote. Body: ' . wp_json_encode($body));
    }

    public function accountantCannotQuoteAShipment(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $item     = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2]);
        $I->loginAsAccountant();

        [$status] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(403, $status, 'without orders/manage_statuses the quote must be refused');
        Assert::assertSame([], StubShippingCarrier::$lastQuoteItems, 'a refused request must not reach the carrier');
    }

    public function quoteWithNoShippableItemsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $item     = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2]);

        [$status] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 0]],
        ]);

        Assert::assertSame(422, $status, 'a quote with nothing to ship must be rejected');
        Assert::assertSame([], StubShippingCarrier::$lastQuoteItems, 'the carrier must not be called');
    }

    public function quoteForACarrierThatDoesNotQuoteIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        StubShippingCarrier::$quoteResult = null;
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $item     = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2]);

        [$status] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(422, $status, 'a carrier without quotes must answer 422, not an empty 200');
    }

    public function quoteForAnUnknownCarrierIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->withAddresses()->create(['customer_id' => $customer->id, 'shipping_status' => 'unshipped']);
        $item     = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2]);

        [$status] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/quote', [
            'carrier_slug' => 'no_such_carrier',
            'items'        => [['order_item_id' => (int) $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(422, $status, 'an unregistered carrier must be rejected');
    }
}
