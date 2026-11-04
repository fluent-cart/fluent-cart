<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * Orders with one physical item, and shipments created through the stub
 * carrier, for the shipment dispatch tests.
 */
trait BuildsShipmentOrders
{
    /**
     * An unshipped order (with addresses) holding one physical item.
     *
     * @return array{0: Order, 1: OrderItem}
     */
    protected function shipmentOrderWithItem(int $qty = 5, array $orderOverrides = [], bool $withAddresses = true): array
    {
        $customer = (new CustomerFactory())->create();
        $factory  = $withAddresses ? (new OrderFactory())->withAddresses() : new OrderFactory();
        $order    = $factory->create(array_merge([
            'customer_id'     => $customer->id,
            'shipping_status' => 'unshipped',
        ], $orderOverrides));

        $item = (new OrderItemFactory())->create($order->id, [
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => 0,
            'post_title'         => 'Widget',
            'other_info'         => [
                'package_weight'         => 1.5,
                'package_weight_unit'    => 'kg',
                'package_length'         => 10,
                'package_width'          => 8,
                'package_height'         => 6,
                'package_dimension_unit' => 'cm',
            ],
        ]);

        return [Order::find($order->id), $item];
    }

    /**
     * @return \FluentCart\App\Models\Shipment|\WP_Error
     */
    protected function shipThroughStub(Order $order, OrderItem $item, int $qty, array $extra = [])
    {
        return ShipmentService::createShipment($order, array_merge([
            'carrier_slug' => StubShippingCarrier::SLUG,
            'items'        => [['order_item_id' => $item->id, 'qty' => $qty]],
        ], $extra));
    }

    /**
     * A shipment row saved directly, without the dispatch flow.
     */
    protected function storedShipment(int $orderId, string $shippedAt, array $attributes, string $status, array $meta): Shipment
    {
        $shipment = new Shipment();
        $shipment->fill(array_merge(['carrier_slug' => 'manual', 'shipped_at' => $shippedAt], $attributes));
        $shipment->order_id = $orderId;
        $shipment->status   = $status;
        $shipment->meta     = $meta;
        $shipment->save();

        return $shipment;
    }
}
