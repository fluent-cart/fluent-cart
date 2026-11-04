<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\Models\Shipment;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * POST /orders/{id}/shipments passes the carrier's error code and its
 * purchase_unconfirmed flag to the client, so the drawer can stop a second buy.
 */
class ShipmentPurchaseUnconfirmedCest
{
    use BuildsShipmentOrders;

    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    public function unconfirmedPurchaseReturnsCodeAndFlag(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = new \WP_Error('x_purchase_unconfirmed', 'Carrier timed out', ['purchase_unconfirmed' => true]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('Carrier timed out', $body['message'] ?? null, 'the message stays');
        Assert::assertSame('x_purchase_unconfirmed', $body['code'] ?? null, 'the carrier error code must reach the client');
        Assert::assertTrue($body['purchase_unconfirmed'] ?? null, 'the unconfirmed flag must reach the client');
        Assert::assertSame(0, Shipment::query()->where('order_id', $order->id)->count(), 'no shipment row may be written');
    }

    public function plainCarrierErrorHasNoUnconfirmedFlag(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = new \WP_Error('carrier_down', 'Carrier down');

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments', [
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        Assert::assertSame(422, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame('carrier_down', $body['code'] ?? null);
        Assert::assertArrayNotHasKey('purchase_unconfirmed', $body, 'a plain error must not lock the drawer');
    }
}
