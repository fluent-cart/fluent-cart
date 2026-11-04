<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\Models\Shipment;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * DELETE /orders/{id}/shipments/{sid} always stands, even when the carrier
 * label cannot be voided: void_warning (bool) tells the client to warn, and the
 * message carries what to reconcile.
 */
class DeleteShipmentCest
{
    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    private function seedLabelledShipment(string $status = Shipment::STATUS_DISPATCHED): array
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create([
            'customer_id'     => $customer->id,
            'shipping_status' => 'unshipped',
        ]);

        $shipment = new Shipment([
            'carrier_slug'    => StubShippingCarrier::SLUG,
            'carrier_name'    => 'Stub Carrier',
            'tracking_number' => 'TRK-DEL',
            'items'           => [],
        ]);
        $shipment->order_id = $order->id;
        $shipment->status   = $status;
        $shipment->meta     = ['transaction_id' => 'txn_del', 'label_url' => 'https://label.test/x.pdf'];
        $shipment->save();

        return [$order, $shipment];
    }

    public function deleteWithCleanVoidReportsNoWarningAndDeletesRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $shipment] = $this->seedLabelledShipment();
        StubShippingCarrier::$cancelResult = true;

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . (int) $shipment->id);

        Assert::assertSame(200, $status, 'a delete must return 200. Body: ' . wp_json_encode($body));
        Assert::assertFalse($body['void_warning'] ?? null, 'a clean void must report void_warning = false');
        Assert::assertSame('txn_del', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the stored label handle must reach the carrier');
        Assert::assertNull(Shipment::find($shipment->id), 'the shipment row must be deleted');
    }

    public function deleteWithFailedVoidReportsWarningButStillDeletesRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $shipment] = $this->seedLabelledShipment();
        StubShippingCarrier::$cancelResult = new \WP_Error('refund_failed', 'carrier refused');

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . (int) $shipment->id);

        Assert::assertSame(200, $status, 'a delete must return 200 even when the void fails. Body: ' . wp_json_encode($body));
        Assert::assertTrue($body['void_warning'] ?? null, 'a failed void must report void_warning = true');
        Assert::assertStringContainsString('carrier refused', (string) ($body['message'] ?? ''), 'the warning must replace the success line');
        Assert::assertNull(Shipment::find($shipment->id), 'the shipment row must be deleted even when the void failed');
    }

    public function deleteDeliveredShipmentSkipsTheVoid(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [$order, $shipment] = $this->seedLabelledShipment(Shipment::STATUS_DELIVERED);
        StubShippingCarrier::$cancelResult = new \WP_Error('refund_failed', 'label already used');

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . (int) $shipment->id);

        Assert::assertSame(200, $status, 'a delete must return 200. Body: ' . wp_json_encode($body));
        Assert::assertSame([], StubShippingCarrier::$cancelCalls, 'a delivered label is used, so it must not be voided');
        Assert::assertFalse($body['void_warning'] ?? null, 'no void means no warning');
        Assert::assertNull(Shipment::find($shipment->id), 'the shipment row must be deleted');
    }
}
