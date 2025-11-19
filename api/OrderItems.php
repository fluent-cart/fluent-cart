<?php

namespace FluentCart\Api;

use FluentCart\App\App;
use FluentCart\App\Models\OrderItem;
use FluentCart\Framework\Support\Arr;

class OrderItems
{
    use SanitizerTrait;

    public function __construct()
    {
        $this->rules = [
            "order_id"    => 'intval',
            "object_id"   => 'intval',
            "object_type" => 'sanitize_text_field',
            "quantity"    => 'intval',
            "item_name"   => 'sanitize_text_field',
            "item_price"  => 'floatval',
            "total"       => 'floatval',
            "line_total"  => 'floatval'
        ];
    }

    public function setOtherInfoAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['other_info'] = json_encode($value);
        } else {
            $this->attributes['other_info'] = $value;
        }
    }

    public function getOtherInfoAttribute($value)
    {
        return !empty($value) ? json_decode($value) : null;
    }

    public function setLineMetaAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['line_meta'] = json_encode($value);
        } else {
            $this->attributes['line_meta'] = $value;
        }
    }

    public function getLineMetaAttribute($value)
    {
        return !empty($value) ? json_decode($value) : null;
    }


    /**
     * @param $orderId
     * @return array
     */
    public function getItems($orderId)
    {
        return OrderItem::query()->where('order_id', $orderId)->get()->toArray();
    }

    /**
     *
     * @param $orderId
     * @param $items
     * @return array
     * @throws \Exception
     */
    public function updateOrInsertOrderItems($orderId, $items): array
    {

        if (empty($items) || !is_array($items)) {

            throw new \Exception(esc_html__('No items given.', 'fluent-cart'));
        }

        foreach ($items as $idx => $item) {

            $item = $this->sanitize($item);
            $item['order_id'] = $orderId;

            /**
             * object_id ::
             * object_id:
             *
             */
            $orderItem = OrderItem::query()->where('order_id', $orderId)
                ->where('object_id', $item['object_id'])
                ->first();

            if (empty($orderItem)) {

                $item['cart_index'] = $idx + 1;
                OrderItem::create($item);

            } else {

                $orderItem->update($item);
            }
        }

        return [
            'message' => __('Order items updated.', 'fluent-cart')
        ];
    }

    public static function getTopProductsSold($queryParams)
    {
        return OrderItem::search(Arr::only($queryParams, ['created_at']))
            ->select('object_id')
            ->selectRaw('SUM(quantity) as total_sold')
            ->groupBy('object_id')
            ->orderBy(sanitize_sql_orderby('total_sold'), sanitize_sql_orderby('DESC'))
            ->having('total_sold', Arr::get($queryParams, 'operator'), Arr::get($queryParams, 'total_sold'))
            ->with(['product' => function ($query) {
                $query->select('ID', 'post_title');
            }])
            ->take(5)->get();
    }
}
