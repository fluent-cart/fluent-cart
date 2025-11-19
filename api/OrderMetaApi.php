<?php

namespace FluentCart\Api;

use FluentCart\App\Models\OrderMeta;

class OrderMetaApi
{

    /**
     *
     * @param $orderId
     * @param $val
     * @return bool
     */
    public static function updateDiscountMeta($orderId, $val): bool
    {
        $ins = new static();

        return $ins->update($orderId, 'order_discount', $val);
    }


    /**
     *
     * @param $orderId
     * @param $val
     * @return bool
     */
    public static function updateShippingMeta($orderId, $val): bool
    {
        $ins = new static();

        return $ins->update($orderId, 'order_shipping', $val);
    }


    /**
     *
     * @param $orderId
     * @return mixed|string
     */
    public static function getDiscountMeta($orderId)
    {
        $ins = new static();

        return $ins->getMetaByKey('order_discount', $orderId);
    }


    /**
     *
     * @param $orderId
     * @return mixed|string
     */
    public static function getShippingMeta($orderId)
    {
        $ins = new static();

        return $ins->getMetaByKey('order_shipping', $orderId);
    }


    /**
     * Alias of all
     * @param $orderId
     * @return null
     */
    protected function all($orderId)
    {
        return $this->getAll($orderId);
    }


    /**
     *
     * @param $orderId
     * @param $meta_key
     * @param $meta_value
     * @return bool
     */
    public function update($orderId, $meta_key, $meta_value)
    {
        $meta = new OrderMeta();

        $meta_key = sanitize_text_field($meta_key);

        $existingMeta = $meta::where('order_id', $orderId)->where('meta_key', $meta_key)->first();

        if ($existingMeta) {
            $existingMeta->meta_value = $meta_value;
            return $existingMeta->save();
        }

        $meta->order_id = $orderId;
        $meta->meta_key = $meta_key;
        $meta->meta_value = $meta_value;

        return $meta->save();
    }


    /**
     *
     * @param $orderId
     * @return mixed
     */
    public function getAll($orderId)
    {

        return OrderMeta::query()->where('order_id', $orderId)->get();
    }


    /**
     *
     * @param $key
     * @param $orderId
     * @return mixed
     */
    protected function getMetaByKey($key, $orderId, $def = '')
    {
        $vl = OrderMeta::query()->where('order_id', $orderId)->where('meta_key', $key)->first();

        if (empty($vl)) {

            return $def;
        }

        return $vl->meta_value;
    }


    /**
     *
     * @param $method
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($method, $arguments)
    {
        $ins = new static();

        return call_user_func_array([$ins, $method], $arguments);
    }
}

