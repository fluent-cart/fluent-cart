<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\Payments\RefundPlanner;
use FluentCart\App\Services\ShipmentService;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * An item refund records how many of its units had shipped, so the units still to ship and the
 * order's shipping status follow what actually left the store.
 */
class RefundShippedUnitsTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        FakeRefundGateway::register();
    }

    public function tearDown(): void
    {
        FakeRefundGateway::remove();
        $this->healCommittedRows();
        parent::tearDown();
    }

    public function test_refunding_a_shipped_unit_leaves_the_kept_units_to_ship(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(3);
        $this->ship($order, $item, 2);

        $this->refund($txn, $item, 1, 1);

        $this->assertSame(1, $this->lineMeta($item, 'refunded_shipped_quantity'));
        $this->assertSame(['total' => 2, 'fulfilled' => 1], ShipmentService::getFulfillmentTotals($order->id));
        $this->assertSame('partially_shipped', Order::find($order->id)->shipping_status);
        $this->assertWPError($this->ship(Order::find($order->id), $item, 2));
        $this->assertInstanceOf(Shipment::class, $this->ship(Order::find($order->id), $item, 1));
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
    }

    public function test_refunding_an_unshipped_unit_ships_the_order(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(3);
        $this->ship($order, $item, 2);

        $this->refund($txn, $item, 1, 0);

        $this->assertSame(0, $this->lineMeta($item, 'refunded_shipped_quantity'));
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
        $this->assertWPError($this->ship(Order::find($order->id), $item, 1));
    }

    public function test_refunding_the_only_shipped_unit_unships_the_order(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(2);
        $this->ship($order, $item, 1);
        $this->assertSame('partially_shipped', Order::find($order->id)->shipping_status);

        $this->refund($txn, $item, 1, 1);

        $this->assertSame('unshipped', Order::find($order->id)->shipping_status);
        $this->assertInstanceOf(Shipment::class, $this->ship(Order::find($order->id), $item, 1));
    }

    public function test_deleting_the_shipment_drops_its_refunded_shipped_units(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(3);
        $shipment = $this->ship($order, $item, 2);
        $this->refund($txn, $item, 1, 1);

        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $this->assertSame(0, $this->lineMeta($item, 'refunded_shipped_quantity'));
        $this->assertInstanceOf(Shipment::class, $this->ship(Order::find($order->id), $item, 2));
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
    }

    public function test_write_fits_shipped_units_to_shipments_changed_since_the_plan_and_logs(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(3);
        $shipment = $this->ship($order, $item, 2);
        $plan = RefundPlanner::plan(Order::find($order->id), ['transaction_id' => $txn->id], [
            ['id' => $item->id, 'refund_qty' => 1, 'shipped_qty' => 1],
        ]);
        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);

        $this->assertIsArray($result);
        $this->assertSame(1, $this->lineMeta($item, 'refunded_quantity'));
        $this->assertSame(0, $this->lineMeta($item, 'refunded_shipped_quantity'));
        $this->assertSame(1, Activity::query()
            ->where('module_type', Order::class)
            ->where('module_id', $order->id)
            ->where('title', 'Shipped units refunded adjusted')
            ->count());
    }

    public function test_marking_unshipped_by_hand_clears_refunded_shipped_units(): void
    {
        [$order, $item, $txn] = $this->paidOrderWithItem(2);
        $this->setShippingStatus($order, 'shipped');
        $this->refund($txn, $item, 1, 1);

        $this->setShippingStatus($order, 'unshipped');
        $this->assertSame(0, $this->lineMeta($item, 'refunded_shipped_quantity'));

        $this->setShippingStatus($order, 'shipped');
        $this->assertSame(['total' => 1, 'fulfilled' => 1], ShipmentService::getFulfillmentTotals($order->id));
    }

    private function paidOrderWithItem(int $qty): array
    {
        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id'     => (new CustomerFactory())->create()->id,
            'total_amount'    => 10000 * $qty,
            'total_paid'      => 10000 * $qty,
            'payment_status'  => Status::PAYMENT_PAID,
            'payment_method'  => FakeRefundGateway::SLUG,
            'shipping_status' => 'unshipped',
        ]);
        $item = (new OrderItemFactory())->create($order->id, [
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => 0,
            'unit_price'         => 10000,
            'subtotal'           => 10000 * $qty,
            'line_total'         => 10000 * $qty,
        ]);
        $txn = (new OrderTransactionFactory())->create((int) $order->id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000 * $qty,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'ch_fake_1',
        ]);

        return [Order::find($order->id), $item, $txn];
    }

    private function ship(Order $order, OrderItem $item, int $qty)
    {
        return ShipmentService::createShipment($order, [
            'tracking_number' => 'TRK-' . wp_generate_password(6, false),
            'items'           => [['order_item_id' => $item->id, 'qty' => $qty]],
        ]);
    }

    private function refund(OrderTransaction $txn, OrderItem $item, int $qty, int $shippedQty): void
    {
        $plan = RefundPlanner::plan(Order::find($txn->order_id), ['transaction_id' => $txn->id], [
            ['id' => $item->id, 'refund_qty' => $qty, 'shipped_qty' => $shippedQty],
        ]);
        $this->assertIsArray($plan);

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);
        $this->assertIsArray($result);
    }

    private function setShippingStatus(Order $order, string $status): void
    {
        $result = OrderResource::updateStatuses([
            'order'    => Order::find($order->id),
            'statuses' => ['shipping_status' => $status],
            'action'   => 'change_shipping_status',
        ]);
        $this->assertIsArray($result);
    }

    private function lineMeta(OrderItem $item, string $key): int
    {
        return (int) Arr::get(OrderItem::find($item->id)->line_meta, $key, 0);
    }
}
