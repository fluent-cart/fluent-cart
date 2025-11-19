<?php

namespace FluentCart\Api;

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Relations\HasOne;
use FLuentCart\Framework\Support\Arr;
use FluentCart\Api\Resource\OrderMetaResource;

class Orders
{

    /**
     * @return array $orders
     *
     * It will return all order lists by query passed
     *
     */
    public function get($params)
    {
        $orders = Order::with(['customer', 'order_items'])
            ->whereHas('customer', function ($query) use ($params) {
                $query->when(Arr::get($params["params"], 'search'), function ($query) use ($params) {
                    return $query->search(Arr::get($params["params"], 'search', ''));
                });
            })
            ->applyCustomFilters(Arr::get($params["params"], 'filters', []))
            ->orderBy(
                sanitize_sql_orderby(Arr::get($params["params"], 'order_by', 'id')),
                sanitize_sql_orderby(Arr::get($params["params"], 'order_type', 'DESC')))
            ->paginate(Arr::get($params["params"], 'per_page'), ['*'], 'page', Arr::get($params["params"], 'page'));

        return [
            'orders' => $orders
        ];
    }

    public function getByHash($hash)
    {
        $order = Order::with(
            [
                'customer' => function ($query) {
                    $query->with(['billing_address' => function ($query) {
                        $query->where('is_primary', '1');
                    }]);
                    $query->with(['shipping_address' => function ($query) {
                        $query->where('is_primary', '1');
                    }]);
                },
                'transactions'
            ])
            ->where('uuid', $hash)
            ->first();

        return $order;
    }


    /**
     * @param array $data array of order details
     * @return string ID of order created
     *
     * Will create order with blank order items
     */
    public function create($data)
    {

        $data['currency'] = (new StoreSettings())->getCurrency();

        return (new Order())->store($data);
    }

    /**
     *
     * @param Order $order
     * @param $orderData - will be the order updated data array
     * @param $deleteItems
     * @return array
     * @throws \Exception
     */
    public function update($order, $orderData, $deleteItems = [], $discount = '', $shipping = '')
    {

        if (empty($order) || $order->status === Status::ORDER_COMPLETED) {

            throw new \Exception(esc_html__('Order information does not match.', 'fluent-cart'));
        }
        $orderId = $order->id;

        /**
         * First delete the deleted items
         */
        if (!empty($deleteItems)) {

            OrderItem::destroy($deleteItems);
        }

        if (!empty($discount)) {

            OrderMetaApi::updateDiscountMeta($orderId, $discount);
        }

        if (!empty($shipping)) {
            OrderMetaApi::updateShippingMeta($orderId, $shipping);
        }

        $items = Arr::get($orderData, 'order_items');

        try {

            (new OrderItems)->updateOrInsertOrderItems($orderId, $items);

        } catch (\Exception $ex) {

            throw $ex;
        }

        unset($orderData['order_items']);
        unset($orderData['customer']);

        $orderData['currency'] = (new StoreSettings())->get('checkout_currency', 'BDT');

        $order->update($orderData);

        return [
            'message' => __('Order updated.', 'fluent-cart')
        ];
    }

    public function getBy(string $column, $value)
    {
        return Order::query()->where($column, $value)
            ->with(['customer' => function ($query) {
                $query->with('billing_address')
                    ->with('shipping_address');
            }])
            ->with(['shipping_address' => function (HasOne $query) {
                return $query->addAppends(['first_name', 'last_name']);
            }])
            ->with(['billing_address' => function (HasOne $query) {
                return $query->addAppends(['first_name', 'last_name']);
            }])
            ->with('order_items')
            ->first();
    }

    public function getById($id)
    {
        return $this->getBy('id', $id);
    }

}
