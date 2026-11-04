<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * Shipment route authorization. The status-changing shipment routes (quote / store / update / destroy /
 * refresh-tracking / recheck-unconfirmed / dismiss-unconfirmed) all gate on `orders/manage_statuses` — they move an order's
 * shipping_status or spend the carrier's API quota. Per PermissionManager the
 * accountant role does NOT carry that capability; a logged-out visitor carries
 * none. (The read-only index gates on the broader `orders/view` and is out of
 * scope here.)
 *
 * The policy runs as the route's permission_callback, before the controller, so
 * the gate fires regardless of whether the referenced shipment id exists.
 */
class ShipmentsPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function workerWithManageStatusesCanRefreshTracking(FunctionalTester $I): void
    {
        StubShippingCarrier::install();
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $shipment = new Shipment([
            'carrier_slug'    => StubShippingCarrier::SLUG,
            'carrier_name'    => 'Stub Carrier',
            'tracking_number' => 'TRK-W',
            'items'           => [],
        ]);
        $shipment->order_id = $order->id;
        $shipment->status   = Shipment::STATUS_DISPATCHED;
        $shipment->save();
        $I->loginAsWorker();

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/' . (int) $shipment->id . '/refresh-tracking');
        } finally {
            StubShippingCarrier::uninstall();
        }

        Assert::assertSame(200, $status, 'a worker holds orders/manage_statuses and may refresh tracking. Body: ' . wp_json_encode($body));
    }

    public function workerWithManageStatusesCanMarkAnUnconfirmedPurchaseResolved(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $order->updateMeta(ShipmentService::UNCONFIRMED_REQUESTS_META, [
            'a1' => ['id' => 'a1', 'carrier' => 'stub', 'items' => [], 'at' => 1],
        ]);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/shipments/dismiss-unconfirmed', ['unconfirmed_id' => 'a1']);

        Assert::assertSame(200, $status, 'Body: ' . wp_json_encode($body));
        Assert::assertSame([], $body['unconfirmed_shipments'] ?? null);
    }

    /**
     * Dispatch each of the seven gated routes for the current user.
     *
     * @return int[] route label => HTTP status
     */
    private function dispatchGatedRoutes(FunctionalTester $I): array
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $orderId  = (int) $order->id;
        $sid      = 999999; // gate fires before the controller resolves the shipment

        [$quote]   = $I->post('/fluent-cart/v2/orders/' . $orderId . '/shipments/quote', ['carrier_slug' => 'manual', 'items' => []]);
        [$store]   = $I->post('/fluent-cart/v2/orders/' . $orderId . '/shipments', ['carrier_slug' => 'manual', 'items' => []]);
        [$update]  = $I->put('/fluent-cart/v2/orders/' . $orderId . '/shipments/' . $sid, ['status' => 'in_transit']);
        [$destroy] = $I->delete('/fluent-cart/v2/orders/' . $orderId . '/shipments/' . $sid);
        [$refresh] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/shipments/' . $sid . '/refresh-tracking');
        [$recheck] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/shipments/recheck-unconfirmed', ['unconfirmed_id' => 'x']);
        [$dismiss] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/shipments/dismiss-unconfirmed', ['unconfirmed_id' => 'x']);

        return [
            'quote'            => $quote,
            'store'            => $store,
            'update'           => $update,
            'destroy'          => $destroy,
            'refresh-tracking' => $refresh,
            'recheck'          => $recheck,
            'dismiss'          => $dismiss,
        ];
    }

    public function loggedOutVisitorIsRejectedFromAllShipmentRoutes(FunctionalTester $I): void
    {
        $I->logout();

        foreach ($this->dispatchGatedRoutes($I) as $label => $status) {
            Assert::assertSame(401, $status, "guest on the {$label} route must be rejected as unauthenticated (401)");
        }
    }

    public function accountantWithoutManageStatusesIsForbiddenFromAllShipmentRoutes(FunctionalTester $I): void
    {
        $I->loginAsAccountant();

        foreach ($this->dispatchGatedRoutes($I) as $label => $status) {
            Assert::assertSame(403, $status, "accountant without manage_statuses on the {$label} route must be forbidden (403)");
        }
    }
}
