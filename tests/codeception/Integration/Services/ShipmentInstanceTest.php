<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\Shipments\ShipmentInstance;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * The unit of work handed to a carrier. getPackages() re-reads each item scoped
 * to the order, so a carrier never sees another order's parcel data.
 */
class ShipmentInstanceTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;

    public function test_get_packages_reads_order_scoped_package_data(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(5);
        [, $foreign]    = $this->shipmentOrderWithItem(5);

        $instance = new ShipmentInstance($order, [
            ['order_item_id' => $item->id, 'qty' => 2],
            ['order_item_id' => $foreign->id, 'qty' => 3],
        ], ['carrier_slug' => 'manual']);

        $packages = $instance->getPackages();

        $this->assertCount(1, $packages, 'only the order-scoped item may produce a package');
        $this->assertSame(1.5, $packages[0]['weight'], 'weight must come from the order item package data');
        $this->assertSame('kg', $packages[0]['weight_unit'], 'weight unit must come from the item');
        $this->assertSame(10.0, $packages[0]['length'], 'length must come from the item');
        $this->assertSame(8.0, $packages[0]['width'], 'width must come from the item');
        $this->assertSame(6.0, $packages[0]['height'], 'height must come from the item');
        $this->assertSame('cm', $packages[0]['dimension_unit'], 'dimension unit must come from the item');
        $this->assertSame(2, $packages[0]['qty'], 'the dispatched quantity must be carried on the package');
    }

    public function test_get_packages_carries_the_box_capacity_snapshot(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(5);
        [, $legacy]     = $this->shipmentOrderWithItem(5);

        $info = $item->other_info;
        $info['package_max_weight'] = 12.5;
        $item->other_info = $info;
        $item->save();

        $packages = (new ShipmentInstance($order, [['order_item_id' => $item->id, 'qty' => 1]], []))->getPackages();
        $this->assertSame(12.5, $packages[0]['max_weight'], 'max_weight must come from the order item snapshot');

        $legacyOrder = Order::find($legacy->order_id);
        $packages    = (new ShipmentInstance($legacyOrder, [['order_item_id' => $legacy->id, 'qty' => 1]], []))->getPackages();
        $this->assertSame(0.0, $packages[0]['max_weight'], 'an item snapshotted before max_weight existed means unlimited');
    }

    public function test_get_packages_falls_back_to_a_single_nominal_parcel(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        [, $foreign] = $this->shipmentOrderWithItem(5);

        $instance = new ShipmentInstance($order, [['order_item_id' => $foreign->id, 'qty' => 4]], []);

        $packages = $instance->getPackages();

        $this->assertCount(1, $packages, 'exactly one nominal parcel when nothing resolves');
        $this->assertSame(1, $packages[0]['weight'], 'nominal weight is 1');
        $this->assertSame('kg', $packages[0]['weight_unit'], 'nominal weight unit is kg');
        $this->assertSame(10, $packages[0]['length'], 'nominal length is 10');
        $this->assertSame('cm', $packages[0]['dimension_unit'], 'nominal dimension unit is cm');
        $this->assertSame(1, $packages[0]['qty'], 'nominal qty is 1');
        $this->assertSame(0, $packages[0]['max_weight'], 'nominal parcel has no capacity limit');
    }

    public function test_get_carrier_slug_sanitizes_and_defaults_to_manual(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical']);

        $this->assertSame('manual', (new ShipmentInstance($order, [], []))->getCarrierSlug(), 'an absent slug defaults to manual');
        $this->assertSame('stubcarrier', (new ShipmentInstance($order, [], ['carrier_slug' => 'Stub Carrier!']))->getCarrierSlug(), 'the slug must go through sanitize_key');
    }
}
