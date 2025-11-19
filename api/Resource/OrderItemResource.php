<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\OrderItem;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\DateTime;

class OrderItemResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return OrderItem::query();
    }

    public static function get(array $params = [])
    {

    }

    /**
     * Find and retrieve order items based on the order ID.
     *
     * @param int $id Required. The ID of the order to find and retrieve.
     * @param array $params Optional. Additional parameters for finding order items.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        return static::getQuery()->where('order_id', $id)->get()->toArray();
    }

    /**
     * Create order items with the provided data.
     *
     * This function creates order items based on the provided data.
     * It also dispatches events related to order creation and stock changes.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of order item details.
     *            [
     *                    'order_id'       => (int) The ID of the order to which the item belongs.
     *                    'post_id'      => (int) The product ID associated with the order item.
     *                    'object_id'   => (int) The variation ID of the order item.
     *                    'thumbnail'      => (string) The URL of the thumbnail of order item.
     *                    'price'     => (float) The price of the item.
     *                    'title'      => (string) The name of the item.
     *                    'quantity'       => (int) The quantity of the item.
     *                    'fulfillment_type'           => (string) Type (e.g., 'physical', 'digital').
     *                    'stockStatus'    => (string) (e.g., 'in-stock'|'out-of-stock').
     *                    'stock'          => (int) The current stock quantity.
     *                    'tax_amount'     => (float) The tax amount for the item.
     *                    'discount_total' => (float) The total discount amount for the item.
     *                    'total'     => (float) The total amount for the item.
     *                    'line_total'     => (float) The total amount for the line
     *             ]
     * @param array $params Optional. Additional parameters for order item creation.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->insert($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Order items created.', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order items failed to create!', 'fluent-cart')]
        ]);
    }

    /**
     * Update order items with the provided data.
     *
     * This function creates order items based on the provided data.
     * It also dispatches events related to order creation and stock changes.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of order item details.
     *            [
     *                    'id'             => (int) The id for the order item.
     *                    'order_id'       => (int) The ID of the order to which the item belongs.
     *                    'post_id'      => (int) The product ID associated with the order item.
     *                    'object_id'   => (int) The variation ID of the order item.
     *                    'thumbnail'      => (string) The URL of the thumbnail of order item.
     *                    'price'     => (float) The price of the item.
     *                    'title'      => (string) The name of the item.
     *                    'quantity'       => (int) The quantity of the item.
     *                    'fulfillment_type'           => (string) Type (e.g., 'physical', 'digital').
     *                    'stockStatus'    => (string) (e.g., 'in-stock'|'out-of-stock').
     *                    'stock'          => (int) The current stock quantity.
     *                    'tax_amount'     => (float) The tax amount for the item.
     *                    'discount_total' => (float) The total discount amount for the item.
     *                    'total'     => (float) The total amount for the item.
     *                    'line_total'     => (float) The total amount for the line
     *             ]
     * @param int $id Required. The id of the order item to update.
     * @param array $params Optional. Additional parameters for order item creation.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $id, $params = [])
    {
        $isUpdate = static::getQuery()->batchUpdate($data);

        if ($isUpdate) {
            return static::makeSuccessResponse(
                $isUpdate,
                __('Order items updated.', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order items failed to update!', 'fluent-cart')]
        ]);
    }

    public static function delete($id, $params = [])
    {

    }

    /**
     * Create or Update order items with the provided data.
     *
     * This function creates or updates order items based on the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        $order    =>   (array) Required. Array of order details.
     *            [
     *               'id'               => (int) The id for the order.
     *               'status'           => (string) The current status of the order
     *               'parent_id'        => (int) The parent order ID, if applicable.
     *               'invoice_no'     => (string) The order number assigned to the order.
     *               'receipt_number'     => (int) The sequential order number for using in invoice
     *               'fulfillment_type' => (string)  (e.g., 'virtual', 'physical', etc.).
     *               'type'             => (string) Type (e.g., 'sale', 'refund', etc.).
     *               'customer_id'      => (int) The ID of the customer associated with the order.
     *               'payment_method'   => (string) The payment method used for the order.
     *               'payment_method_title'   => (string) The title of the payment method.
     *               'currency'         => (string) The currency used for the order (e.g., 'BDT').
     *               'subtotal'         => (float) The subtotal amount of the order.
     *               'discount_tax'     => (float) The tax amount on discounts.
     *               'discount_total'   => (float) The total discount amount for the order.
     *               'shipping_tax'     => (float) The tax amount on shipping.
     *               'shipping_total'   => (float) The total shipping amount for the order.
     *               'tax_total'        => (float) The total tax amount for the order.
     *               'total_amount'     => (float) The total amount for the order.
     *               'total_paid'       => (float) The total amount paid for the order.
     *               'rate'             => (float) The exchange rate used for currency conversion.
     *               'note'             => (string) Additional notes or comments for the order.
     *               'ip_address'       => (string) The IP address associated with the order.
     *               'completed_at'     => (string|null) date-time order completed|null
     *               'refunded_at'      => (string|null) date-time the order was refunded|null
     *               'uuid'             => (string) The id for the order.
     *               'created_at'       => (string) The date and time the order was created.
     *               'updated_at'       => (string) The date and time the order was last updated.
     *           ]
     * @param int $id Required. The id of the order to create or update.
     * @param array $params Required. Additional parameters for order item creation|update.
     *        $params    =>   (array) Required. Array of order item details.
     *           [
     *               'id'             => (int) The id for the order item|null if not.
     *               'order_id'       => (int) The ID of the order to which the item belongs.
     *               'post_id'      => (int) The product ID associated with the order item.
     *               'object_id'   => (int) The variation ID of the order item.
     *               'thumbnail'      => (string) The URL of the thumbnail of order item.
     *               'unit_price'     => (float) The price of the item.
     *               'title'      => (string) The name of the item.
     *               'quantity'       => (int) The quantity of the item.
     *               'fulfillment_type'           => (string) Type (e.g., 'physical', 'digital').
     *               'stockStatus'    => (string) (e.g., 'in-stock'|'out-of-stock').
     *               'stock'          => (int) The current stock quantity.
     *               'tax_amount'     => (float) The tax amount for the item.
     *               'discount_total' => (float) The total discount amount for the item.
     *               'total'     => (float) The total amount for the item.
     *               'line_total'     => (float) The total amount for the line
     *           ]
     *
     */
    public static function updateOrInsertOrderItems($order, $id, $params = [], $couponCalculation = [])
    {
        if (empty($params) || !is_array($params)) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('No items given.', 'fluent-cart')]
            ]);
        }

        $newItems = [];
        $existingItems = [];

        $variationIds = (new Collection($params))->pluck('object_id')->toArray();

        $orderItems = static::getQuery()
            ->where('order_id', $id)
            ->whereIn('object_id', $variationIds)
            ->get();

        foreach ($params as $idx => &$item) {
            $item['order_id'] = $id;
            $productId = Arr::get($item, 'post_id');
            $variationId = Arr::get($item, 'object_id', null);
            if (isset($productId) && isset($variationId)) {

                $orderItem = $orderItems->where('post_id', $productId)
                    ->where('object_id', $variationId)->first();

                $isExist = !empty($orderItem);

                $itemData = Arr::only($item, ['id', 'title', 'post_id', 'object_id', 'post_title', 'quantity', 'unit_price', 'cost', 'tax_amount', 'discount_total', 'line_total']);
                $itemData['subtotal'] = Arr::get($item, 'quantity', 0) * Arr::get($item, 'unit_price', 0);
                $itemData['line_total'] = $itemData['subtotal'] - Arr::get($item, 'discount_total', 0) + Arr::get($item, 'tax_amount', 0);
                $fulfillment_type = Arr::get($item, 'fulfillment_type', 'physical');


                if (empty($isExist)) {
                    unset($itemData['id']);
                    $item = wp_parse_args([
                        'order_id' => $id,
                        'cart_index' => $idx + 1,
                        'fulfillment_type' => $fulfillment_type,
                        'other_info' => json_encode(Arr::get($item, 'other_info', [])),
                        'payment_type' => Arr::get($item, 'payment_type', Arr::get($item, 'other_info.payment_type', '')),
                        'shipping_charge' => Arr::get($item, 'shipping_charge', 0),
                        'created_at' => DateTime::now(),
                        'updated_at' => DateTime::now(),
                        'fulfilled_quantity' => $fulfillment_type === 'physical' ? 0 : Arr::get($item, 'quantity', 0)
                    ], $itemData);
                    $newItems[] = $item;
                } else {
                    $existingData = Arr::only($itemData, ['id', 'quantity', 'unit_price', 'cost', 'subtotal', 'tax_amount', 'discount_total', 'line_total', 'shipping_charge']) + ['updated_at' => DateTime::now()];
                    $existingData['fulfilled_quantity'] = $fulfillment_type === 'physical' ? Arr::get($item, 'fulfilled_quantity', 0) : Arr::get($item, 'quantity', 0);
                    $existingItems[] = $existingData;
                }
            }
        }

        // if any item is adjustment, then only add the adjustment item
        $adjustmentItem = array_filter($newItems, function ($item) {
            return Arr::get($item, 'payment_type') === 'adjustment';
        });

        if (count($adjustmentItem) > 0) {
            $newItems = $adjustmentItem[0];
        }

        if (count($newItems) > 0) {
            OrderItemResource::create($newItems, $order);
        }

        if (count($existingItems) > 0) {
            OrderItemResource::update($existingItems, $order);
        }

        return static::makeSuccessResponse(
            '',
            __('Order items updated.', 'fluent-cart')
        );
    }

    /**
     * Retrieve the top-selling products based on the total quantity sold.
     *
     * @param array $params Optional. Additional parameters for retrieving top-selling products.
     *        [
     *            'created_at'   => (array) Optional. Filter results by creation date.
     *                      [ "created_at" => [
     *                          "column"       => "created_at",
     *                          "operator"     => "between",
     *                          "value"        => "Date range e.g. start_date,end_date ]
     *                      ],
     *            'operator'     => (string) Optional. Operator for the 'total_sold' condition.
     *                              (e.g., '>', '=', '<', etc.)
     *            'total_sold'   => (int) Optional. Total quantity sold condition.
     *        ]
     *
     */
    public static function topProductsSold($params = [])
    {
        return OrderItem::search(Arr::only($params, ['created_at']))
            ->select('post_id')
            ->selectRaw('SUM(quantity) as total_sold')
            ->groupBy('post_id')
            ->orderBy(sanitize_sql_orderby('total_sold'), sanitize_sql_orderby('DESC'))
            ->having('total_sold', Arr::get($params, 'operator'), Arr::get($params, 'total_sold'))
            // ->with([
            //     'product' => function(Builder $query){
            //         return $query->select('ID','post_title');
            //     }
            // ])
            ->withWhereHas('product', function ($query) {
                $query->select('ID', 'post_title');
            })
            ->lazy()->forPage(1, 5);
    }

    public static function bulkDeleteByOrderIds($ids, $params = [])
    {
        return static::getQuery()->whereIn('order_id', $ids)->delete();
    }

}
