<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Orders;
use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class OrderResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return Order::query();
    }

    /**
     * Retrieve orders with additional data based on specified parameters.
     *
     * @param array $params Optional. Additional parameters for order retrieval.
     *        $params = [
     *           'search'     => (string) Optional. Search Order.
     *               [
     *                  "column name(e.g., customer_id)" => [
     *                      column => "column name(e.g., customer_id)",
     *                      value => "value" ]
     *               ],
     *            'order_by'     => (string) Optional. Column to order by,
     *            'order_type'   => (string) Optional. Order type for sorting (ASC or DESC),
     *            'per_page'     => (int) Optional. Number of items for per page,
     *            'page'         => (int) Optional. Page number for pagination
     *       ]
     *
     */
    public static function get(array $params = [])
    {
        $searchConditions = Arr::get($params, 'search.search_conditions', []);
        $with = Arr::get($params, 'with', []);
        $requestParams = Arr::get($params, 'params', []);

        $orderBy = sanitize_sql_orderby(Arr::get($requestParams, 'order_by', 'id'));
        $orderType = sanitize_sql_orderby(Arr::get($requestParams, 'order_type', 'DESC'));
        $perPage = Arr::get($requestParams, 'per_page', 10);
        $page = Arr::get($requestParams, 'page', 1);
        $searchId = Arr::get($params, 'search.id');

        return static::getQuery()
            ->with($with)
            ->whereHas('customer', function ($query) use ($searchId) {
                return $query->where('id', $searchId);
            })
            ->search($searchConditions)
            ->orderBy(
                sanitize_sql_orderby($orderBy),
                sanitize_sql_orderby($orderType))
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Find order based on the given id and parameters.
     *
     * @param int $id Required. The id of the order.
     * @param array $params Optional. Array containing the necessary parameters.
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);
        return static::getQuery()
            ->with($with)
            ->with([
                'customer' => function ($query) {
                    $query->with(['billing_address' => function ($query) {
                        $query->where('is_primary', '1');
                    }]);
                    $query->with(['shipping_address' => function ($query) {
                        $query->where('is_primary', '1');
                    }]);
                }
            ])
            ->where('uuid', $id)
            ->first();
    }

    /**
     * Create order address with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     * @param array $params Required. Additional parameters for creating an order.
     * 
     */
    public static function create($data, $params = [])
    {
        //
    }

    /**
     * Update order with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     * @param int $id Required. The id of the order to be updated.
     *
     */
    public static function update($data, $id, $params = [])
    {
        //
    }

    /**
     * Delete an order based on the given id and parameters.
     *
     * @param int $id Required. The id of the order.
     * @param array $params Optional. Additional parameters for order deletion.
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }
}
