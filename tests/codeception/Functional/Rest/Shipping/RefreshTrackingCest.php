<?php

namespace Tests\Functional\Rest\Shipping;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * POST /orders/{id}/shipments/{sid}/refresh-tracking pulls a shipment's status
 * from its carrier. Only pull-sync carriers qualify (else 422) and a per-shipment
 * cooldown throttles the carrier call (429). The index's can_refresh_tracking
 * flag mirrors the same gate.
 */
class RefreshTrackingCest
{
    public function _before(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
    }

    public function _after(FunctionalTester $I): void
    {
        StubShippingCarrier::uninstall();
    }

    private function order(): Order
    {
        $customer = (new CustomerFactory())->create();

        return (new OrderFactory())->create([
            'customer_id'     => $customer->id,
            'shipping_status' => 'unshipped',
        ]);
    }

    private function seedShipment(int $orderId, array $overrides = []): Shipment
    {
        $status = $overrides['status'] ?? Shipment::STATUS_DISPATCHED;
        $meta   = $overrides['meta'] ?? null;
        unset($overrides['status'], $overrides['meta']);

        $shipment = new Shipment(array_merge([
            'carrier_slug'    => StubShippingCarrier::SLUG,
            'carrier_name'    => 'Stub Carrier',
            'tracking_number' => 'TRK-1',
            'items'           => [],
        ], $overrides));
        $shipment->order_id = $orderId;
        $shipment->status   = $status;
        $shipment->meta     = $meta;
        $shipment->save();

        return $shipment;
    }

    private function route(Order $order, Shipment $shipment): string
    {
        return '/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . (int) $shipment->id . '/refresh-tracking';
    }

    public function indexReportsCanRefreshTrackingPerShipment(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->order();

        $label      = ['transaction_id' => 'txn_label'];
        $syncable   = $this->seedShipment($order->id, ['tracking_number' => 'TRK-A', 'meta' => $label]);
        $nonSync    = $this->seedShipment($order->id, ['carrier_slug' => 'manual', 'tracking_number' => 'TRK-B', 'meta' => $label]);
        $terminal   = $this->seedShipment($order->id, ['tracking_number' => 'TRK-C', 'status' => Shipment::STATUS_DELIVERED, 'meta' => $label]);
        $noTracking = $this->seedShipment($order->id, ['tracking_number' => '', 'meta' => $label]);
        $noLabel    = $this->seedShipment($order->id, ['tracking_number' => 'TRK-D']);

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments');
        Assert::assertSame(200, $status, 'listing shipments must return 200. Body: ' . wp_json_encode($body));

        $flags = [];
        foreach ($body['shipments'] as $row) {
            $flags[(int) $row['id']] = $row['can_refresh_tracking'];
        }

        Assert::assertTrue($flags[(int) $syncable->id], 'a pull-sync carrier with a label, a tracking number and a live status must be refreshable');
        Assert::assertFalse($flags[(int) $nonSync->id], 'a non-sync carrier must not be refreshable');
        Assert::assertFalse($flags[(int) $terminal->id], 'a delivered (terminal) shipment must not be refreshable');
        Assert::assertFalse($flags[(int) $noTracking->id], 'a shipment with no tracking number must not be refreshable');
        Assert::assertFalse($flags[(int) $noLabel->id], 'a carrier shipment recorded without a label must not be refreshable');
    }

    public function indexNeverExposesTheLabelPdf(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order = $this->order();
        $this->seedShipment($order->id, ['meta' => ['label_pdf_base64' => base64_encode('%PDF-1.4 x'), 'label_url' => 'https://label.test/x.pdf']]);

        [$status, $body] = $I->get('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments');

        Assert::assertSame(200, $status, 'listing shipments must return 200');
        Assert::assertArrayNotHasKey('label_pdf_base64', $body['shipments'][0]['meta'] ?? [], 'the label blob must not be sent to the browser');
        Assert::assertSame('https://label.test/x.pdf', $body['shipments'][0]['meta']['label_url'] ?? null, 'the rest of the meta must still be returned');
    }

    public function refreshOnUnsupportedCarrierReturns422(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['carrier_slug' => 'manual']);

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(422, $status, 'refreshing a non-sync carrier must return 422. Body: ' . wp_json_encode($body));
    }

    public function refreshAppliesTheCarriersStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id);
        StubShippingCarrier::$onSync = function ($instance) {
            return ShipmentService::applyTrackingUpdate($instance->shipment, Shipment::STATUS_IN_TRANSIT, ['tracking_status' => 'TRANSIT']);
        };

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(200, $status, 'a refresh must return 200. Body: ' . wp_json_encode($body));
        Assert::assertTrue($body['applied'] ?? null, 'the carrier status must be reported as applied');
        Assert::assertSame('in_transit', $body['shipment_status'] ?? null, 'the response must carry the new status');
        Assert::assertSame('in_transit', Shipment::find($shipment->id)->status, 'the new status must be persisted');
    }

    public function refreshThatFindsNothingNewIsNotAnError(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id);
        StubShippingCarrier::$syncResult = false;

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(200, $status, 'nothing new is a successful refresh. Body: ' . wp_json_encode($body));
        Assert::assertFalse($body['applied'] ?? null, 'nothing must be reported as applied');
    }

    public function refreshWithNewerDetailsUnderTheSameStatusReportsThem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, [
            'status' => Shipment::STATUS_IN_TRANSIT,
            'meta'   => [
                'tracking_status'         => 'TRANSIT',
                'tracking_status_date'    => '2026-10-20T08:00:00Z',
                'tracking_status_details' => 'Left the origin hub',
            ],
        ]);
        StubShippingCarrier::$onSync = function ($instance) {
            return ShipmentService::applyTrackingUpdate($instance->shipment, Shipment::STATUS_IN_TRANSIT, [
                'tracking_status'         => 'TRANSIT',
                'tracking_status_date'    => '2026-10-21T08:00:00Z',
                'tracking_status_details' => 'Arrived at the destination hub',
            ]);
        };

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(200, $status, 'a refresh must return 200. Body: ' . wp_json_encode($body));
        Assert::assertFalse($body['applied'] ?? null, 'the status did not change');
        Assert::assertTrue($body['details_updated'] ?? null, 'the newer scan details must be reported');
        Assert::assertSame('Arrived at the destination hub', $body['shipment']['meta']['tracking_status_details'] ?? null, 'the response must carry the new details');
        Assert::assertSame('Tracking details updated from the carrier.', $body['message'] ?? null, 'the message must not say nothing changed');
        Assert::assertSame('Arrived at the destination hub', Shipment::find($shipment->id)->meta['tracking_status_details'] ?? null, 'the new details must be persisted');
    }

    public function refreshThatFindsNothingNewSaysSo(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id, ['meta' => ['tracking_status_details' => 'Left the origin hub']]);
        StubShippingCarrier::$syncResult = false;

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(200, $status, 'nothing new is a successful refresh. Body: ' . wp_json_encode($body));
        Assert::assertFalse($body['details_updated'] ?? null, 'no details changed');
        Assert::assertSame('Tracking is already up to date.', $body['message'] ?? null);
    }

    public function refreshCarrierErrorReturns422(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id);
        StubShippingCarrier::$onSync = function () {
            throw new \RuntimeException('carrier exploded');
        };

        [$status, $body] = $I->post($this->route($order, $shipment));

        Assert::assertSame(422, $status, 'a throwing carrier must not 500 the endpoint. Body: ' . wp_json_encode($body));
    }

    public function refreshRepeatWithinCooldownReturns429(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $shipment = $this->seedShipment($order->id);

        [$first]               = $I->post($this->route($order, $shipment));
        [$second, $secondBody] = $I->post($this->route($order, $shipment));

        Assert::assertSame(200, $first, 'the first refresh must pass the cooldown gate');
        Assert::assertSame(429, $second, 'a rapid repeat within the cooldown must return 429. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame(1, StubShippingCarrier::$syncCalls, 'the throttled repeat must not reach the carrier');
    }

    public function refreshOfAnotherOrdersShipmentReturns404(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $order    = $this->order();
        $other    = $this->order();
        $shipment = $this->seedShipment($other->id);

        [$status] = $I->post($this->route($order, $shipment));

        Assert::assertSame(404, $status, 'a shipment must be scoped to the order in the route');
        Assert::assertSame(0, StubShippingCarrier::$syncCalls, 'the carrier must not be called');
    }
}
