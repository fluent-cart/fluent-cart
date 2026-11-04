<?php

namespace Tests\Integration\Services;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * A label bought by the carrier is an external charge that a rollback cannot
 * undo, so createShipment() buys it outside the transaction and voids it on
 * every path that then fails to record the shipment.
 */
class ShipmentDispatchRollbackTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;
    use BuildsShipmentOrders;

    /** @var callable|null */
    private $queryBreaker = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        StubShippingCarrier::install();
        StubShippingCarrier::$dispatchResult = [
            'tracking_number' => 'TRK-LABEL',
            'label_url'       => 'https://label.test/l.pdf',
            'transaction_id'  => 'txn_rollback',
        ];
    }

    public function tearDown(): void
    {
        if ($this->queryBreaker) {
            remove_filter('query', $this->queryBreaker, 10);
            $this->queryBreaker = null;
        }
        StubShippingCarrier::uninstall();
        $this->healCommittedRows();
        parent::tearDown();
    }

    public function test_the_carrier_is_called_outside_the_transaction(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);

        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->assertInstanceOf(Shipment::class, $shipment, 'the shipment must be created');
        $this->assertSame([0], StubShippingCarrier::$dispatchLevels, 'the label must be bought with no transaction (and no order row lock) open');
    }

    public function test_service_name_lookup_runs_outside_the_transaction(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = null;

        $shipment = $this->shipThroughStub($order, $item, 1, [
            'tracking_number'  => 'MANUAL-1',
            'selected_rate_id' => 'stub_rate',
        ]);

        $this->assertInstanceOf(Shipment::class, $shipment, 'the shipment must be created');
        $this->assertSame('Stub — Ground', $shipment->meta['service_name'] ?? null, 'the picked service must be resolved from the carrier quote');
        $this->assertSame([0], StubShippingCarrier::$quoteLevels, 'the carrier quote must run with no transaction open');
    }

    public function test_a_forged_rate_id_records_no_service_name(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = null;

        $shipment = $this->shipThroughStub($order, $item, 1, [
            'tracking_number'  => 'MANUAL-1',
            'selected_rate_id' => 'not_offered',
        ]);

        $meta = is_array($shipment->meta) ? $shipment->meta : [];
        $this->assertArrayNotHasKey('service_name', $meta, 'a rate the carrier does not offer must not name the service');
    }

    public function test_label_voided_when_the_order_is_gone_under_the_lock(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$onDispatch = function () use ($order) {
            App::db()->table('fct_orders')->where('id', $order->id)->delete();
        };

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'creating against a deleted order must fail');
        $this->assertSame('order_not_found', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may stay fulfilled');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the bought label must be voided exactly once');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    public function test_label_voided_when_the_order_turned_terminal_under_the_lock(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$onDispatch = function () use ($order) {
            App::db()->table('fct_orders')->where('id', $order->id)->update(['shipping_status' => 'delivered']);
        };

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'creating against a delivered order must fail');
        $this->assertSame('terminal_status', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may stay fulfilled');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the bought label must be voided exactly once');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    /**
     * Double submit with a label carrier: both requests buy a label, the loser
     * finds the quantity gone at the atomic bump and must void its own label.
     */
    public function test_label_voided_when_a_concurrent_shipment_took_the_quantity(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$onDispatch = function () use ($item) {
            App::db()->table('fct_order_items')->where('id', $item->id)->update(['fulfilled_quantity' => 2]);
        };

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'the request that lost the race must fail');
        $this->assertSame('qty_exceeded', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the loser must void the label it bought');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    public function test_label_voided_when_the_write_throws(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $this->queryBreaker = function ($sql) {
            if (is_string($sql) && preg_match('/^\s*insert\s+into\s+`?\w*fct_shipments`?/i', $sql)) {
                throw new \RuntimeException('simulated write failure');
            }
            return $sql;
        };
        add_filter('query', $this->queryBreaker, 10, 1);

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'a failed write must fail the create');
        $this->assertSame('db_error', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may stay fulfilled');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the bought label must be voided exactly once');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    public function test_label_voided_when_the_carrier_tracking_number_is_too_long(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult['tracking_number'] = str_repeat('9', 256);

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'an overlong carrier tracking number must not be truncated into the column');
        $this->assertSame('field_too_long', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may stay fulfilled');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the bought label must be voided exactly once');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    public function test_label_voided_when_the_carrier_tracking_url_is_too_long(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult['tracking_url'] = 'https://track.test/' . str_repeat('u', 490);

        $result = $this->shipThroughStub($order, $item, 1);

        $this->assertWPError($result, 'an overlong carrier tracking URL must not be truncated into the column');
        $this->assertSame('field_too_long', $result->get_error_code(), 'wrong error code');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may stay fulfilled');
        $this->assertCount(1, StubShippingCarrier::$cancelCalls, 'the bought label must be voided exactly once');
        $this->assertSame('txn_rollback', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the void must carry the label handle');
    }

    public function test_overlong_admin_values_are_rejected_before_any_label_is_bought(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);

        $result = $this->shipThroughStub($order, $item, 1, [
            'tracking_url' => 'https://track.test/' . str_repeat('u', 490),
        ]);

        $this->assertWPError($result, 'an overlong admin value must be rejected');
        $this->assertSame([], StubShippingCarrier::$dispatchLevels, 'the carrier must not be asked to buy a label');
        $this->assertSame([], StubShippingCarrier::$cancelCalls, 'nothing was bought, so nothing is voided');
    }

    public function test_a_manual_rollback_voids_nothing(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        StubShippingCarrier::$dispatchResult = null;
        StubShippingCarrier::$onDispatch = function () use ($order) {
            App::db()->table('fct_orders')->where('id', $order->id)->update(['shipping_status' => 'delivered']);
        };

        $result = $this->shipThroughStub($order, $item, 1, ['tracking_number' => 'MANUAL-1']);

        $this->assertWPError($result, 'creating against a delivered order must fail');
        $this->assertSame([], StubShippingCarrier::$cancelCalls, 'no label was bought, so nothing may be voided');
    }

    public function test_label_cost_is_stored_in_the_carriers_currency(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(2);
        $shippingTotal = (int) Order::find($order->id)->shipping_total;
        StubShippingCarrier::$dispatchResult['cost'] = 1234;
        StubShippingCarrier::$dispatchResult['currency'] = 'gbp';

        $shipment = $this->shipThroughStub($order, $item, 1, ['cost' => '0.99', 'cost_currency' => 'USD']);

        $this->assertInstanceOf(Shipment::class, $shipment, 'the shipment must be created');
        $this->assertSame(1234, $shipment->meta['cost'] ?? null, 'the label cost is what the carrier charged, in cents');
        $this->assertSame('GBP', $shipment->meta['cost_currency'] ?? null, 'the label cost keeps the carrier currency');
        $this->assertSame($shippingTotal, (int) Order::find($order->id)->shipping_total, 'the merchant cost must never touch the order shipping total');
    }
}
