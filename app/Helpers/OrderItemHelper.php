<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\Framework\Support\Arr;

class OrderItemHelper
{
    private $orderId = null;

    /**
     * @return array 
     * sanitized order_item
     */
    public function sanitize($orderItem)
    {
        $rules = [
            "order_id" => 'intval',
            "post_id" => 'intval',
            "variation_type" => 'sanitize_text_field',
            "quantity" => 'intval',
            "item_name" => 'sanitize_text_field',
            "item_price" => 'floatval',
            "item_total" => 'floatval',
            "line_total" => 'floatval'
        ];

        foreach ($rules as $key => $value) {
            if (isset($orderItem[$key])) {
                $orderItem[$key] = call_user_func($value, $orderItem[$key]);
            }
        }

        return $orderItem;
    }

    public function mapOrderItem($product, $quantity = 0)
    {
        $total = floatval(Arr::get($product, 'detail.item_price') * $quantity);
        $detail = Arr::get($product, 'detail');

        return array(
            'order_id' => intval($this->orderId),
            'post_id'    => intval(Arr::get($product, 'ID')),
            'fulfillment_type' => Arr::get($detail, 'item_type'),
            'quantity' => intval($quantity),
            'item_name' => Arr::get($product, 'post_title'),
            'item_price' => floatval(Arr::get($detail, 'item_price')),
            'tax_amount' => 0,
            'discount_total' => 0,
            'item_total' => $total,
            'line_total' => $total,
        );
    }

    public function saveOrderItems($products)
    {
        foreach ($products as $product) {
            $product = $this->sanitize($product);

            $id = Arr::get($product, 'id', false);
            $orderItem = OrderItem::find($id);

            if (empty($orderItem)) {
                OrderItem::create($product);
            } else {
                $orderItem->update($product);
            }
        }

        return [
            'message' => __('Product Updates!', 'fluent-cart')
        ];
    }

    /**
     * @return array
     * processed orderItem from product
     */
    public function processProducts($orderId, $items)
    {
        if (empty($items)) {
            throw new \Exception(esc_html__('Please add some products!', 'fluent-cart'));
        }

        if (!$orderId) {
            throw new \Exception(esc_html__('Order Not valid!', 'fluent-cart'));
        }

        $this->orderId = $orderId;
        $processedData = [];

        foreach ($items as $item) {
            if (isset($item['ID'])) {
                $data = $this->mapOrderItem($item);
                array_push($processedData, $data);
            }
        };

        return $processedData;
    }


    public function processCustom($product, $orderId)
    {
        if (!$orderId) {
            throw new \Exception(esc_html__('Order Not valid!', 'fluent-cart'));
        }

        // The published spec (openapi/orders/create-custom-order-item.json) names
        // these `title` and `price`; the original implementation read `item_name`
        // and `item_price`. Accept both so neither contract is broken.
        $title = sanitize_text_field((string)Arr::get($product, 'title', Arr::get($product, 'item_name', '')));

        if (!$title) {
            throw new \Exception(esc_html__('Item must have a name!', 'fluent-cart'));
        }

        // Price arrives already in cents.
        $unitPrice = Helper::roundCent(Arr::get($product, 'price', Arr::get($product, 'item_price', 0)));
        $quantity = intval(Arr::get($product, 'quantity', 0));

        if ($unitPrice <= 0 || $quantity <= 0) {
            throw new \Exception(esc_html__('Price, Quantity field should not be empty or zero!', 'fluent-cart'));
        }

        $lineTotal = $unitPrice * $quantity;

        $fulfillmentType = Arr::get($product, 'fulfillment_type', Status::FULFILLMENT_TYPE_PHYSICAL);

        if (!in_array($fulfillmentType, [Status::FULFILLMENT_TYPE_PHYSICAL, Status::FULFILLMENT_TYPE_DIGITAL], true)) {
            $fulfillmentType = Status::FULFILLMENT_TYPE_PHYSICAL;
        }

        $order = Order::query()->find(intval($orderId));

        if (!$order) {
            throw new \Exception(esc_html__('Order Not valid!', 'fluent-cart'));
        }

        // Same rule as OrderController::updateOrder — a subscription order's
        // total is bound to its pending charge, which nothing on this path
        // resynchronizes.
        if ($order->isSubscription()) {
            throw new \Exception(esc_html__('Subscription Order cannot be edited.', 'fluent-cart'));
        }

        $db = Order::query()->getConnection();
        $db->beginTransaction();

        try {
            // Serialize on the order row so concurrent additions rebuild the
            // aggregates one at a time, and a failed rebuild rolls the line
            // back out. The tax pass opens its own transaction inside this
            // one, which the connection runs as a savepoint.
            $locked = Order::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new \Exception(esc_html__('Order Not valid!', 'fluent-cart'));
            }

            $orderItem = OrderItem::create([
                'order_id'         => $locked->id,
                'fulfillment_type' => $fulfillmentType,
                'title'            => $title,
                'post_title'       => $title,
                'quantity'         => $quantity,
                'unit_price'       => $unitPrice,
                'subtotal'         => $lineTotal,
                'line_total'       => $lineTotal,
                'tax_amount'       => 0,
                'discount_total'   => 0,
                // Same rule as the order-edit insert path: digital lines need
                // no shipment, so they are born fulfilled.
                'fulfilled_quantity' => $fulfillmentType === Status::FULFILLMENT_TYPE_PHYSICAL ? 0 : $quantity,
            ]);

            // A line added on its own leaves the order carrying the subtotal it
            // had before that line existed; the whole-order save posts
            // client-computed totals instead and never reaches here.
            OrderResource::syncItemDerivedTotals($locked);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return $orderItem;
    }
}
