<?php

namespace FluentCart\App\Helpers;

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
        $price = Arr::get($product, 'item_price', false);
        $quantity = Arr::get($product, 'quantity', false);

        if (!Arr::get($product, 'item_name', false)) {
            throw new \Exception(esc_html__('Item must have a name!', 'fluent-cart'));
        }

        if (!$price || !$quantity) {
            throw new \Exception(esc_html__('Price, Quantity field should not be empty or zero!', 'fluent-cart'));
        }

        //$total = floatVal($price * 100 * $quantity);
        $total = floatVal($price * $quantity);

        $type = Arr::get($product, 'fulfillment_type', 'physical');

        $otherData = [
            'order_id' => $orderId,
            'variation_type' => $type,
            //'item_price' => $price * 100,
            'item_price' => $price,
            'item_total' => $total,
            'line_total' => $total,
            'tax_amount' => 0,
            'discount_total' => 0,
        ];

        return $this->sanitize(array_merge($product, $otherData));
    }
}
