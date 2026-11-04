<?php

namespace FluentCart\App\Services\Shipments;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\Framework\Support\Arr;

/**
 * What a carrier is handed to quote, dispatch or cancel: the order, the items
 * going out in this shipment, and the admin's form data. Carriers never see the
 * raw request.
 *
 * `$shipment` is set only once a row exists (cancel on delete). During creation
 * it is null, because the row is written after the carrier answers.
 */
class ShipmentInstance
{
    /** @var Order */
    public $order;

    /** @var array List of ['order_item_id' => int, 'qty' => int]. */
    public $items;

    /** @var array Validated form data (carrier_slug, selected_rate_id, ...). */
    public $data;

    /** @var Shipment|null */
    public $shipment;

    public function __construct(Order $order, array $items, array $data, ?Shipment $shipment = null)
    {
        $this->order    = $order;
        $this->items    = $items;
        $this->data     = $data;
        $this->shipment = $shipment;
    }

    public function getCarrierSlug()
    {
        return sanitize_key((string) Arr::get($this->data, 'carrier_slug', Shipment::CARRIER_MANUAL)) ?: Shipment::CARRIER_MANUAL;
    }

    /**
     * One parcel per dispatched item, from the item's stored package data. Items
     * are re-read scoped to this order, since their ids come from the client.
     * Falls back to one nominal 1kg 10x10x10cm parcel: carrier APIs reject an
     * empty parcel list.
     *
     * @return array[]
     */
    public function getPackages()
    {
        $orderItemIds = array_map('intval', array_column($this->items, 'order_item_id'));

        $orderItems = $orderItemIds
            ? OrderItem::whereIn('id', $orderItemIds)->where('order_id', $this->order->id)->get()->keyBy('id')
            : [];

        $packages = [];
        foreach ($this->items as $dispatched) {
            $itemId = (int) Arr::get($dispatched, 'order_item_id', 0);
            if (!isset($orderItems[$itemId])) {
                continue;
            }

            $info = is_array($orderItems[$itemId]->other_info) ? $orderItems[$itemId]->other_info : [];

            $packages[] = [
                'weight'         => (float) Arr::get($info, 'package_weight', 1),
                'weight_unit'    => Arr::get($info, 'package_weight_unit', 'kg'),
                'length'         => (float) Arr::get($info, 'package_length', 10),
                'width'          => (float) Arr::get($info, 'package_width', 10),
                'height'         => (float) Arr::get($info, 'package_height', 10),
                'dimension_unit' => Arr::get($info, 'package_dimension_unit', 'cm'),
                'max_weight'     => (float) Arr::get($info, 'package_max_weight', 0),
                'qty'            => (int) Arr::get($dispatched, 'qty', 1),
            ];
        }

        if ($packages) {
            return $packages;
        }

        return [
            [
                'weight'         => 1,
                'weight_unit'    => 'kg',
                'length'         => 10,
                'width'          => 10,
                'height'         => 10,
                'dimension_unit' => 'cm',
                'max_weight'     => 0,
                'qty'            => 1,
            ],
        ];
    }
}
