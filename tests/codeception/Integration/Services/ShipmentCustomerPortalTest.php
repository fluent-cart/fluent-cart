<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Traits\BuildsShipmentOrders;

/**
 * The customer portal lists an order's shipments without leaking internal meta.
 */
class ShipmentCustomerPortalTest extends DatabaseTestCase
{
    use BuildsShipmentOrders;

    public function test_customer_shipments_are_ordered_and_whitelisted(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);

        $second = $this->storedShipment($order->id, '2026-10-30 10:00:00', [
            'carrier_name'    => 'Pathao',
            'tracking_number' => 'PTH-2',
            'tracking_url'    => 'https://track.example.com/PTH-2',
            'notes'           => 'Left at the front desk',
            'items'           => [['order_item_id' => 9, 'qty' => 2, 'item_name' => 'QA Tee - Red']],
        ], Shipment::STATUS_IN_TRANSIT, [
            'service_name'            => 'Express',
            'tracking_status_details' => 'Arrived at hub',
            'cost'                    => 1250,
            'label_pdf_base64'        => 'JVBERi0=',
            'transaction_id'          => 'txn_secret',
        ]);
        $first = $this->storedShipment($order->id, '2026-10-29 10:00:00', ['carrier_name' => 'Manual'], Shipment::STATUS_DELIVERED, []);

        $rows = ShipmentService::customerShipments($order);

        $this->assertSame([$first->id, $second->id], array_column($rows, 'id'), 'oldest shipment is Shipment 1');
        $this->assertSame([
            'id'                      => $second->id,
            'status'                  => 'in_transit',
            'status_label'            => 'In Transit',
            'carrier_name'            => 'Pathao',
            'service_name'            => 'Express',
            'tracking_number'         => 'PTH-2',
            'tracking_url'            => 'https://track.example.com/PTH-2',
            'tracking_status_details' => 'Arrived at hub',
            'tracking_status_date'    => '',
            'tracking_eta'            => '',
            'tracking_substatus'      => '',
            'shipped_at'              => '2026-10-30 10:00:00',
            'notes'                   => 'Left at the front desk',
            'parcels'                 => [],
            'items'                   => [['order_item_id' => 9, 'item_name' => 'QA Tee - Red', 'qty' => 2]],
        ], $rows[1], 'cost, label and carrier ids must not reach the customer');
        $this->assertSame('', $rows[0]['tracking_number']);
        $this->assertSame([], $rows[0]['items']);
    }

    public function test_customer_shipments_send_the_update_time_and_each_parcels_tracking_only(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);

        $this->storedShipment($order->id, '2026-10-31 13:45:00', [
            'carrier_name'    => 'FedEx',
            'tracking_number' => '794698412345',
            'tracking_url'    => 'https://www.fedex.com/fedextrack/?trknbr=794698412345',
            'items'           => [['order_item_id' => 4, 'qty' => 1, 'item_name' => 'Board']],
        ], Shipment::STATUS_IN_TRANSIT, [
            'tracking_status_date' => '2026-11-01T18:10:00-04:00',
            'label_url'            => 'https://labels.example.com/1.pdf',
            'label_pdf_base64'     => 'JVBERi0=',
            'cost'                 => 1650,
            'transaction_id'       => 'tx1,tx2',
            'rate_object_id'       => 'rate_secret',
            'parcels'              => [
                ['transaction_id' => 'tx1', 'tracking_number' => '794698412345', 'tracking_url' => 'https://www.fedex.com/fedextrack/?trknbr=794698412345', 'label_url' => 'https://labels.example.com/1.pdf', 'cost' => 800],
                ['transaction_id' => 'tx2', 'tracking_number' => '794698412356', 'tracking_url' => 'javascript:alert(1)', 'label_url' => 'https://labels.example.com/2.pdf', 'object_id' => 'parcel_secret'],
            ],
        ]);

        $row = ShipmentService::customerShipments($order)[0];

        $this->assertSame('2026-11-01 22:10:00', $row['tracking_status_date'], 'carrier time is sent in GMT, like shipped_at');
        $this->assertSame([
            ['tracking_number' => '794698412345', 'tracking_url' => 'https://www.fedex.com/fedextrack/?trknbr=794698412345'],
            ['tracking_number' => '794698412356', 'tracking_url' => ''],
        ], $row['parcels'], 'each parcel carries its tracking number and a safe URL only');
        $this->assertSame(4, $row['items'][0]['order_item_id']);

        $json = wp_json_encode($row);
        foreach (['label_url', 'label_pdf_base64', 'cost', 'transaction_id', 'object_id', 'labels.example.com', 'tx1', 'rate_secret', 'parcel_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret . ' must not reach the customer');
        }
    }

    public function test_customer_shipments_send_the_expected_date_in_gmt_and_the_substatus(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $this->storedShipment($order->id, '2026-11-01 10:00:00', ['carrier_name' => 'UPS'], Shipment::STATUS_IN_TRANSIT, [
            'tracking_eta'       => '2026-11-04T20:00:00-04:00',
            'tracking_substatus' => 'out_for_delivery',
        ]);
        $this->storedShipment($order->id, '2026-11-02 10:00:00', ['carrier_name' => 'USPS'], Shipment::STATUS_IN_TRANSIT, [
            'tracking_eta'       => 'not a date',
            'tracking_substatus' => '<b>odd</b>',
        ]);

        $rows = ShipmentService::customerShipments($order);

        $this->assertSame('2026-11-05 00:00:00', $rows[0]['tracking_eta'], 'the expected date is sent in GMT, like shipped_at');
        $this->assertSame('out_for_delivery', $rows[0]['tracking_substatus']);
        $this->assertSame('', $rows[1]['tracking_eta']);
        $this->assertSame('boddb', $rows[1]['tracking_substatus']);
    }

    public function test_a_single_parcel_is_not_listed_as_extra_parcels(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id]);

        $this->storedShipment($order->id, '2026-10-31 13:45:00', ['tracking_number' => 'ONE'], Shipment::STATUS_DISPATCHED, [
            'parcels' => [['tracking_number' => 'ONE', 'tracking_url' => 'https://track.example.com/ONE']],
        ]);

        $this->assertSame([], ShipmentService::customerShipments($order)[0]['parcels']);
    }

    public function test_unshipped_items_list_the_physical_units_still_to_ship(): void
    {
        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())->create(['customer_id' => $customer->id, 'shipping_status' => 'partially_shipped']);
        $items    = new OrderItemFactory();

        $items->create($order->id, ['fulfillment_type' => 'physical', 'post_title' => 'Shipped Mug', 'quantity' => 2, 'fulfilled_quantity' => 2]);
        $partly = $items->create($order->id, ['fulfillment_type' => 'physical', 'post_title' => 'Apron', 'title' => 'Charcoal', 'quantity' => 3, 'fulfilled_quantity' => 1]);
        $refunded = $items->create($order->id, ['fulfillment_type' => 'physical', 'post_title' => 'Board', 'title' => 'Large', 'quantity' => 2, 'fulfilled_quantity' => 0, 'line_meta' => ['refunded_quantity' => 1]]);
        $items->create($order->id, ['fulfillment_type' => 'physical', 'post_title' => 'Refunded Kettle', 'quantity' => 1, 'fulfilled_quantity' => 0, 'line_meta' => ['refunded_quantity' => 1]]);
        $items->create($order->id, ['fulfillment_type' => 'physical', 'post_title' => 'Returned Grinder', 'quantity' => 2, 'fulfilled_quantity' => 2, 'line_meta' => ['refunded_quantity' => 1, 'refunded_shipped_quantity' => 1]]);
        $items->create($order->id, ['fulfillment_type' => 'digital', 'post_title' => 'eBook', 'quantity' => 1, 'fulfilled_quantity' => 0]);

        $this->assertSame([
            ['order_item_id' => $partly->id, 'post_title' => 'Apron', 'title' => 'Charcoal', 'item_name' => 'Apron - Charcoal', 'qty' => 2],
            ['order_item_id' => $refunded->id, 'post_title' => 'Board', 'title' => 'Large', 'item_name' => 'Board - Large', 'qty' => 1],
        ], ShipmentService::customerUnshippedItems($order));
    }

    public function test_an_unshippable_or_canceled_order_lists_nothing_to_ship(): void
    {
        $customer = (new CustomerFactory())->create();
        $items    = new OrderItemFactory();

        $unshippable = (new OrderFactory())->create(['customer_id' => $customer->id, 'shipping_status' => 'unshippable']);
        $items->create($unshippable->id, ['fulfillment_type' => 'physical', 'quantity' => 1, 'fulfilled_quantity' => 0]);

        $canceled = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => 'canceled', 'shipping_status' => 'unshipped']);
        $items->create($canceled->id, ['fulfillment_type' => 'physical', 'quantity' => 1, 'fulfilled_quantity' => 0]);

        $this->assertSame([], ShipmentService::customerUnshippedItems($unshippable));
        $this->assertSame([], ShipmentService::customerUnshippedItems($canceled));
    }
}
