<?php

namespace Tests\Integration\Http;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\App;
use FluentCart\App\Http\Controllers\FrontendControllers\CustomerOrderController;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * Purchase History builds each order's delivery line in the browser, so the
 * customer order list sends the same shipments and unshipped items as the
 * order page, from relations loaded once for the page.
 */
class CustomerOrderListShippingStatusTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;

    public function setUp(): void
    {
        parent::setUp();
        // The current customer is cached per request; each test logs in its own.
        CustomerResource::resetCurrentCustomerRuntimeCache();
    }

    public function test_customer_order_list_sends_shipments_and_unshipped_items(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_set_current_user((int) $customer->user_id);
        $items = new OrderItemFactory();

        $split = (new OrderFactory())->create(['customer_id' => $customer->id, 'fulfillment_type' => 'physical', 'shipping_status' => 'partially_shipped']);
        $items->create($split->id, ['fulfillment_type' => 'physical', 'post_title' => 'Apron', 'quantity' => 3, 'fulfilled_quantity' => 3]);
        $items->create($split->id, ['fulfillment_type' => 'physical', 'post_title' => 'Grinder', 'quantity' => 2, 'fulfilled_quantity' => 1]);
        $items->create($split->id, ['fulfillment_type' => 'digital', 'post_title' => 'eBook', 'quantity' => 1, 'fulfilled_quantity' => 0]);
        $this->storedShipment($split->id, '2026-11-01 17:05:00', ['carrier_name' => 'USPS', 'items' => [['order_item_id' => 2, 'qty' => 1]]], Shipment::STATUS_DISPATCHED, []);
        $this->storedShipment($split->id, '2026-10-29 11:20:00', ['carrier_name' => 'UPS', 'items' => [['order_item_id' => 1, 'qty' => 3]]], Shipment::STATUS_IN_TRANSIT, [
            'tracking_status_date' => '2026-11-02 07:48:00',
            'tracking_eta'         => '2026-11-04T20:00:00Z',
            'tracking_substatus'   => 'out_for_delivery',
            'cost'                 => 1250,
        ]);

        $digital = (new OrderFactory())->create(['customer_id' => $customer->id, 'fulfillment_type' => 'digital', 'shipping_status' => '']);

        $request = new FluentRequest(App::getInstance(), ['per_page' => 10, 'page' => 1], []);
        $rows = array_column((new CustomerOrderController())->getOrders($request)->get_data()['orders']->items(), null, 'uuid');
        $row = $rows[$split->uuid];

        // The same rows as the order page: oldest shipment first, no label or cost.
        $this->assertSame(ShipmentService::customerShipments(Order::find($split->id)), $row['shipments']);
        $this->assertSame(['UPS', 'USPS'], array_column($row['shipments'], 'carrier_name'));
        $this->assertSame('2026-11-02 07:48:00', $row['shipments'][0]['tracking_status_date']);
        $this->assertSame('2026-11-04 20:00:00', $row['shipments'][0]['tracking_eta']);
        $this->assertSame('out_for_delivery', $row['shipments'][0]['tracking_substatus']);
        $this->assertArrayNotHasKey('cost', $row['shipments'][0]);

        // One grinder left to ship; digital lines are not part of the delivery.
        $this->assertSame(ShipmentService::customerUnshippedItems(Order::find($split->id)), $row['unshipped_items']);
        $this->assertSame([['Grinder', 1]], array_map(function ($item) {
            return [$item['post_title'], $item['qty']];
        }, $row['unshipped_items']));

        $this->assertSame([], $rows[$digital->uuid]['shipments']);
        $this->assertSame([], $rows[$digital->uuid]['unshipped_items']);
    }

    public function test_more_orders_with_shipments_add_no_query(): void
    {
        $customer = (new CustomerFactory())->createWithUser();
        wp_set_current_user((int) $customer->user_id);
        $request = new FluentRequest(App::getInstance(), ['per_page' => 10, 'page' => 1], []);
        $count = function () use ($request) {
            $queries = [];
            $log = function ($sql) use (&$queries) {
                $queries[] = $sql;
                return $sql;
            };
            add_filter('query', $log);
            (new CustomerOrderController())->getOrders($request);
            remove_filter('query', $log);
            return count($queries);
        };

        $this->shippedOrder($customer->id);
        // The first call also loads the customer and options, which later calls reuse.
        $count();
        $one = $count();
        $this->shippedOrder($customer->id);
        $this->shippedOrder($customer->id);
        $this->shippedOrder($customer->id);

        $this->assertSame($one, $count());
    }

    private function shippedOrder(int $customerId): void
    {
        $order = (new OrderFactory())->create(['customer_id' => $customerId, 'fulfillment_type' => 'physical', 'shipping_status' => 'partially_shipped']);
        $item = (new OrderItemFactory())->create($order->id, ['fulfillment_type' => 'physical', 'quantity' => 2, 'fulfilled_quantity' => 1]);
        $this->storedShipment($order->id, '2026-11-01 10:00:00', ['carrier_name' => 'UPS', 'items' => [['order_item_id' => $item->id, 'qty' => 1]]], Shipment::STATUS_IN_TRANSIT, []);
    }
}
