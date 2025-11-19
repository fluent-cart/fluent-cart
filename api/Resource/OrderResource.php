<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Orders;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderDeleted;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Order\OrderUpdated;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\AdminOrderProcessor;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Query\QueryParser;
use FluentCart\App\Models\Query\Sort;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
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
     *           'search'     => ( string ) Optional. Search Order.
     *               [
     *                  'column name(e.g., first_name|last_name|email|id)' => [
     *                      column => 'column name(e.g., first_name|last_name|email|id)',
     *                      operator => 'operator (e.g., like_all|rlike|or_rlike|or_like_all)',
     *                      value => 'value' ]
     * ],
     *            'filters'   => ( string ) Optional. Filters order.
     *               [
     *                  'column name(e.g., status|payment_status|payment_method)' => [
     *                      column => 'column name(e.g., status|payment_status|payment_method)',
     *                      operator => 'operator (e.g., in)',
     *                      value => 'value' ]
     * ],
     *            'order_by'     => ( string ) Optional. Column to order by,
     *            'order_type'   => ( string ) Optional. Order type for sorting ( ASC or DESC ),
     *            'per_page'     => ( int ) Optional. Number of items for per page,
     *            'page'         => ( int ) Optional. Page number for pagination
     * ]
     *
     */
    public static function get(array $params = [])
    {
        $query = static::getQuery();
        $dynamicConditions = Arr::get($params, 'dynamic_filters') ?? [];
        QueryParser::make()->parse($query, $dynamicConditions);
        $sortCriteria = Arr::get($params, 'sort_criteria', []);
        Sort::make()->apply($query, $sortCriteria);


        $with = array_merge(['customer', 'filteredOrderItems'], Arr::get($params, 'with', []));

        return $query->with($with)
            ->whereHas('customer', function ($query) use ($params) {
                $query->when(Arr::get($params, 'search'), function ($query) use ($params) {
                    return $query->search(Arr::get($params, 'search', ''));
                });
            })
            ->applyCustomFilters(Arr::get($params, 'filters', []))
            ->when(!count($sortCriteria), function ($query) use ($params) {
                $query->orderBy(
                    sanitize_sql_orderby(Arr::get($params, 'order_by', 'id')),
                    sanitize_sql_orderby(Arr::get($params, 'order_type', 'DESC'))
                );
            })
            ->paginate(Arr::get($params, 'per_page'), ['*'], 'page', Arr::get($params, 'page'));
    }


    /**
     * Find an order by ID with associated customer and address details.
     *
     * @param string $id Required. The UUID of the order to find.
     * @param array $params Optional. Additional parameters for order retrieval.
     *        [
     *              // Include optional parameters, if any.
     * ]
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);
        return static::getQuery()
            ->with($with)
            ->with([
                'customer' => function ($query) {
                    $query->with([
                        'billing_address' => function ($query) {
                            $query->where('is_primary', '1');
                        }
                    ]);
                    $query->with([
                        'shipping_address' => function ($query) {
                            $query->where('is_primary', '1');
                        }
                    ]);
                }
            ])
            ->where('uuid', $id)
            ->first();
    }

    /**
     * Create an order with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters for order creation.
     *        $data = [
     *            'status'     => ( string )    Required. The status of the order,
     *            // Include additional parameters, if any.
     * ]
     * @param array $params Optional. Additional parameters for order creation.
     *        [
     *            // Include optional parameters, if any.
     * ]
     *
     */
    public static function create($data, $params = [])
    {
        $order = $data;
        $orderItems = Arr::except(Arr::get($order, 'order_items', []), ['*']);
        $hasPhysicalProduct = false;

        foreach ($orderItems as $item) {
            if (isset($item['trial_days']) && $item['trial_days'] > 0) {
                continue;
            }
            if (Arr::get($item, 'fulfillment_type') == 'physical') {
                $hasPhysicalProduct = true;
            }
        }

        $subtotal = OrderService::getItemsAmountWithoutDiscount($orderItems); //get order total without a discount

        // because of decimal issue commented this below line, using OrderService::getCouponDiscountTotal instead
        // $subtotalWithDiscount = OrderService::getItemsAmountTotal($orderItems, false, false); //get order total with discount
        $coupon_discount_total = OrderService::getCouponDiscountTotal($orderItems);
        $couponDiscountTotal = $coupon_discount_total;

        $totalAmount = floatVal($subtotal + Arr::get($order, 'tax_total', 0) + Arr::get($order, 'shipping_total', 0) - Arr::get($order, 'manual_discount_total', 0) - $couponDiscountTotal);

        $latestOrder = static::getQuery()->latest()->first();
        $latestOrderId = Arr::get($latestOrder, 'id', 0);

        $fulfillmentType = $hasPhysicalProduct ? 'physical' : 'digital';
        $storeSettings = new StoreSettings();

        $shipping_total = Arr::get($order, 'shipping_total', 0);
        $userTz = Arr::get($order, 'user_tz');
        $config = [];

        if (!empty($userTz)) {
            $config['user_tz'] = $userTz;
        }
        $orderData = [
            'subtotal'              => $subtotal,
            'total_amount'          => $totalAmount,
            'payment_status'        => $totalAmount == 0 ? Status::PAYMENT_PAID : Status::PAYMENT_PENDING,
            'status'                => Status::ORDER_ON_HOLD,
            'currency'              => Helper::shopConfig('currency'),
            'mode'                  => Helper::shopConfig('order_mode'),
            'receipt_number'        => ($latestOrderId + 1),
            'invoice_no'            => $storeSettings->getInvoicePrefix() . ($latestOrderId + 1) . $storeSettings->getInvoiceSuffix(),
            'ip_address'            => AddressHelper::getIpAddress(),
            'fulfillment_type'      => $fulfillmentType,
            'manual_discount_total' => Arr::get($order, 'manual_discount_total', 0),
            'coupon_discount_total' => $couponDiscountTotal,
            'shipping_total'        => $shipping_total,
            'config'                => $config
        ];

        $isPlanChange = Arr::get($params, 'is_plan_change', 'no');
        $discountApplied = Arr::get($params, 'discount_applied', 'no');
        if ('yes' == $isPlanChange && 'yes' == $discountApplied) {
            $orderData['subtotal'] = $subtotal + Arr::get($params, 'discount_amount', 0);
            $orderData['manual_discount_total'] = Arr::get($params, 'discount_amount', 0);
        }
        $orderData += $order;

        $orderData['created_at'] = DateTime::gmtNow();
        $orderData['updated_at'] = DateTime::gmtNow();

        try {
            $res = static::getQuery()->create($orderData);;
            if (!$res || !$res->id) {
                throw new \Exception(__('Order creation failed.', 'fluent-cart'));
            }
            return $res;
        } catch (\Exception $e) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => $e->getMessage()]
            ]);
        }
    }

    /**
     * @throws \Exception
     */
    public static function updatedPlaceOrder($data, $params = [])
    {
        $order = $data;
        $discount = Arr::get($data, 'discount');
        $shipping = Arr::get($data, 'shipping');
        $newLabelIds = Arr::get($data, 'labels');
        $paymentMethod = sanitize_text_field('offline_payment');

        $items = Arr::except(Arr::get($order, 'order_items'), ['*']);
        OrderService::validateProducts($items);

        $customer = static::getCustomer($data);

        if (Arr::get($discount, 'value', 0) > 0) {
            static::distributeManualDiscount($items, Helper::toCent(Arr::get($discount, 'value', 0)));
        }

        // admin order processor
        $adminOrderProcessor = new AdminOrderProcessor($items, [
            'customer_id'               => $customer->id,
            'payment_method'            => $paymentMethod,
            'applied_coupons'           => Arr::get($data, 'applied_coupon', []),
            'shipping_total'            => Arr::get($data, 'shipping_total', []),
            'billing_address'           => Arr::get($customer, 'billing_address', []),
            'shipping_address'          => Arr::get($customer, 'shipping_address', []),
            'user_tz'                   => Arr::get($data, 'user_tz', ''),
        ]);

        $order = $adminOrderProcessor->createDraftOrder();

        $data = Arr::except($data, ['order_items', 'customer', 'discount', 'shipping']);

        try {
            if ($paymentMethod) {
                static::addOrderMeta($order->id, $discount, $shipping, $newLabelIds);

                static::commitEvents($order);

                static::createOrderAddresses($order->id, $data);

                static::triggerStockChangedEvents($order);

                if ($gateway = App::gateway($paymentMethod)) {
                    $paymentInstance = new PaymentInstance($order);
                    $gateway->makePaymentFromPaymentInstance($paymentInstance);
                }

                return $order;
            } else {
                return static::makeErrorResponse([
                    ['code' => 423, 'message' => __('Please select a payment method first!', 'fluent-cart')]
                ]);
            }
        } catch (\Exception $e) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => $e->getMessage()]
            ]);
        }
    }

    private static function distributeManualDiscount(&$items, $manualDiscountTotal)
    {
        $totalSubtotal = array_reduce($items, function ($carry, $item) {
            return $carry + ((int)Arr::get($item, 'unit_price', 0) * (int)Arr::get($item, 'quantity', 1));
        }, 0);

        if ($totalSubtotal <= 0) {
            return;
        }

        $distributed = 0;
        foreach ($items as &$checkoutItem) {
            $unitPrice = (int)Arr::get($checkoutItem, 'unit_price', 0);
            $quantity = (int)Arr::get($checkoutItem, 'quantity', 1);
            $itemSubtotal = $unitPrice * $quantity;

            $itemManualDiscount = (int) (($itemSubtotal / $totalSubtotal) * $manualDiscountTotal);

            if ($itemManualDiscount > $itemSubtotal) {
                $itemManualDiscount = $itemSubtotal;
            }

            $distributed += $itemManualDiscount;

            Arr::set($checkoutItem, 'manual_discount', $itemManualDiscount);

        }

        $diff = round($manualDiscountTotal - $distributed, 2);
        // Adjust the first item to account for any precision differences
        if ($diff != 0) {
            $items[0]['manual_discount'] = (int) (Arr::get($items[0], 'manual_discount', 0) + $diff);
        }
    }


    private static function getCustomer($data)
    {
        $customer = CustomerResource::find(Arr::get($data, 'customer.id'), [
            'with' => ['primary_billing_address', 'primary_shipping_address']
        ]);
        return Arr::get($customer, 'customer');
    }

    private static function addOrderMeta($orderId, $discount, $shipping, $newLabelIds)
    {
        if (!empty($discount)) {
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_discount',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $discount
            ]);
        }

        if (!empty($shipping)) {
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_shipping',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $shipping
            ]);
        }

        if (!empty($newLabelIds)) {
            LabelResource::addLabelToLabelRelationships(Order::find($orderId), [
                'labelable_id'   => $orderId,
                'labelable_type' => Order::class,
                'new_label_ids'  => $newLabelIds,
            ]);
        }
    }

    private static function commitEvents($order)
    {

        if (!$order) {
            throw new \Exception(esc_html__('Please process order first', 'fluent-cart'));
        }

        if (!$order->customer) {
            throw new \Exception(esc_html__('Please set customer first', 'fluent-cart'));
        }

        if (!$order->latest_transaction) {
            throw new \Exception(esc_html__('Please set Transaction First', 'fluent-cart'));
        }

        $paymentStatus = $order->payment_status;

        $transactionStatus = $order->latest_transaction->status;

        if (in_array($transactionStatus, Status::getTransactionSuccessStatuses())) {

            do_action('fluent_cart/payment_' . $paymentStatus,
                [
                    'order'       => $order,
                    'customer'    => $order->customer,
                    'transaction' => $order->latest_transaction
                ]);

            do_action('fluent_cart/payment_' . $order->latest_transaction->transaction_type . '_' . $paymentStatus, [
                'order'       => $order,
                'customer'    => $order->customer,
                'transaction' => $order->latest_transaction
            ]);
        }

    }

    private static function createOrderAddresses($orderId, $data)
    {

        $billingAddress = CustomerAddresses::query()->find(
            Arr::get($data, 'billing_address_id')
        );

        $shippingAddress = CustomerAddresses::query()->find(
            Arr::get($data, 'shipping_address_id')
        );

        if (!empty($billingAddress)) {
            static::createOrderAddress($billingAddress->toArray(), $orderId);
        }
        if (!empty($shippingAddress)) {
            static::createOrderAddress($shippingAddress->toArray(), $orderId);
        }
    }

    private static function triggerStockChangedEvents($order)
    {
        $productIds = OrderService::pluckProductIds($order);
        if (!empty($productIds)) {
//            (new StockChanged($productIds))->dispatch();
        }
    }

    /**
     * Update an order with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters for order update.
     *        $data = [
     *          'orderData'    => ( array ) Required. Represents the main order details.
     *            [
     *              'id'               => (int) The id for the order.
     *              'status'           => (string) The current status of the order
     *              'parent_id'        => (int) The parent order ID, if applicable.
     *              'receipt_number'     => (int) the unique sequential order number.
     *              'invoice_no'     => (string) The order number assigned to the order.
     *              'fulfillment_type' => (string)  (e.g., 'virtual', 'physical', etc.).
     *              'type'             => (string) Type (e.g., 'sale', 'refund', etc.).
     *              'customer_id'      => (int) The ID of the customer associated with the order.
     *              'payment_method'   => (string) The payment method used for the order.
     *              'payment_method_title'   => (string) The title of the payment method.
     *              'currency'         => (string) The currency used for the order (e.g., 'BDT').
     *              'subtotal'         => (float) The subtotal amount of the order.
     *              'discount_tax'     => (float) The tax amount on discounts.
     *              'manual_discount_total'   => (float) The total discount amount for the order.
     *              'shipping_tax'     => (float) The tax amount on shipping.
     *              'shipping_total'   => (float) The total shipping amount for the order.
     *              'tax_total'        => (float) The total tax amount for the order.
     *              'total_amount'     => (float) The total amount for the order.
     *              'total_paid'       => (float) The total amount paid for the order.
     *              'rate'             => (float) The exchange rate used for currency conversion.
     *              'ip_address'       => (string) The IP address associated with the order.
     *              'completed_at'     => (string|null) date-time order completed|null
     *  *              'refunded_at'      => (string|null) date-time the order was refunded|null
     *  *              'uuid'             => (string) The id for the order.
     *   *              'created_at'       => (string) The date and time the order was created.
     *  *              'updated_at'       => (string) The date and time the order was last updated.
     *  *              'customer'         => (null|array) Info of customer associated with the order.
     *              'order_items'      => (array) Required. Array of order item details.
     *                 [
     *                    'id'             => ( int ) The id for the order item.
     *                    'order_id'       => ( int ) The ID of the order to which the item belongs.
     *                    'post_id'      => ( int ) The product ID associated with the order item.
     *                    'object_id'   => ( int ) The variation ID of the order item.
     *                    'thumbnail'      => ( string ) The URL of the thumbnail of order item.
     *                    'item_price'     => ( float ) The price of the item.
     *                    'item_name'      => ( string ) The name of the item.
     *                    'quantity'       => ( int ) The quantity of the item.
     *                    'type'           => ( string ) Type ( e.g., 'simple', 'variable' ).
     *                    'stockStatus'    => ( string ) ( e.g., 'in-stock'|'out-of-stock' ).
     *                    'stock'          => ( int ) The current stock quantity.
     *                    'tax_amount'     => ( float ) The tax amount for the item.
     *                    'manual_discount_total' => ( float ) The total discount amount for the item.
     *                    'item_total'     => ( float ) The total amount for the item.
     *                    'line_total'     => ( float ) The total amount for the line
     * ]
     * ],
     *     'discount'       => ( array ) Optional. Represents the discount details
     *        [
     *           'type'   => ( string ) Required. type of discount ( e.g., 'amount', 'percentage' )
     *           'label'  => ( string ) Optional. The label associated with the discount
     *           'reason' => ( string ) Optional. The reason for the discount
     *           'value'  => ( float ) Required. The value of the discount
     * ],
     *      'shipping'      => ( array ) Optional. Represents the shipping details.
     *        [
     *           'type'   => ( string ) Optional. The type of shipping.
     *           'value'  => ( float|null ) Optional. Value associated with shipping|null if not
     * ],
     *      'deletedItems'  => ( array ) Optional. IDs of items to be deleted.
     *        [
     *           ( e.g., 100, 501 etc )
     * ]
     * ]
     * @param int $id Required. The ID of the order to update.
     * @param array $params Optional. Additional parameters for order update.
     *        [
     *            // Include optional parameters, if any.
     * ]
     *
     */
    public static function update($data, $id, $params = [])
    {


        $order = static::getQuery()->with(["order_items", "appliedCoupons", "labels"])->where('id', $id)->first();

        if (empty($order) || $order->status === Status::ORDER_COMPLETED || $order->status === Status::ORDER_CANCELED) {
            if (empty($order)) {
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('The order information does not match', 'fluent-cart')]
                ]);
            }

            return static::makeErrorResponse([
                ['code' => 404, 'message' => sprintf(
                    /* translators: %s is the order status */
                    __('Your order status is marked as %s and not eligible for any further modifications at this time.', 'fluent-cart'), $order->status)]
            ]);
        }

        $orderData = $data['orderData'];
        $deletedItems = $data['deletedItems'];
        $appliedCoupons = Arr::get($orderData, 'applied_coupon');
        $discount = $data['discount'];
        $shipping = $data['shipping'];

        $orderId = $order->id;


        /**
         * First delete the deleted items
         */
        if (!empty($deletedItems)) {

            OrderItem::destroy($deletedItems);
        }

        if (!empty($discount)) {
            if (!empty($appliedCoupons) && count($appliedCoupons) > 0) {
                // Remove the custom discount amount if coupon is applied.
                OrderMetaResource::delete($orderId, [
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key' => 'order_discount',
                ]);
            } else {
                static::addOrUpdateOrderMeta([
                    'order_id'   => $orderId,
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_key'   => 'order_discount',
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'meta_value' => $discount
                ]);
            }
        }
        if (!empty($shipping)) {
            static::addOrUpdateOrderMeta([
                'order_id'   => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'   => 'order_shipping',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $shipping
            ]);
        }

        $items = Arr::get($orderData, 'order_items');
        $isUpdatedOrderItems = OrderItemResource::updateOrInsertOrderItems($order, $orderId, Arr::except($items, ['*']));

        if ($isUpdatedOrderItems) {
            unset($orderData['order_items']);
            unset($orderData['customer']);


            $orderData['currency'] = Helper::shopConfig('currency');

            $oldOrder = clone $order;
            $isUpdated = $order->update($orderData);

            if ($isUpdated) {
                $newOrder = $order->refresh();

                if (!empty($appliedCoupons)) {
                    $appliedCoupons = Arr::except($appliedCoupons, ['*']);
                    $couponCodes = array_keys($appliedCoupons);
                    if (!empty($couponCodes)) {
                        $coupons = Coupon::query()->whereIn('code', $couponCodes)->get()
                            ->keyBy('code')
                            ->toArray();

                        foreach ($coupons as $code => &$coupon) {
                            $coupon['order_id'] = $orderId;
                            $coupon['coupon_id'] = $appliedCoupons[$code]['id'];
                            $coupon['amount'] = $appliedCoupons[$code]['discount'];
                            $coupon['created_at'] = $order->updated_at;
                            $coupon['updated_at'] = $order->updated_at;
                        }
                        $order->appliedCoupons()->delete();
                        $order->appliedCoupons()->createMany($coupons);
                        Coupon::query()->whereIn('code', $couponCodes)->increment('use_count', 1);
                    }
                }

                if (empty($appliedCoupons) && count($order->appliedCoupons) > 0) {
                    $order->appliedCoupons()->delete();
                }

                // $getOrderNoActionableStatuses = ['unshippable'];
                // if(in_array($newOrder->shipping_status, $getOrderNoActionableStatuses)) {
                //     $newOrder->shipping_status = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                // }
                (new OrderUpdated($newOrder, $oldOrder))->dispatch();

                $oldOrderItems = json_decode(json_encode(Arr::get($oldOrder, 'order_items', [])), true);
                $newOrderItems = json_decode(json_encode(Arr::get($newOrder, 'order_items', [])), true);
                $pluckOldVariationIds = array_column($oldOrderItems, 'object_id');
                foreach ($newOrderItems as $newItem) {
                    if (!in_array($newItem['object_id'], $pluckOldVariationIds)) {
                        $oldOrderItems[] = $newItem;
                    }
                }

                static::triggerEventsOnStockChanged($oldOrderItems);

                return static::makeSuccessResponse(
                    $isUpdated,
                    __('Order updated successfully', 'fluent-cart')
                );
            }
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order update failed.', 'fluent-cart')]
        ]);
    }

    public static function updateOrderAddressId($data, Order $order)
    {

        $addressType = Arr::get($data, 'address_type') ?? 'billing';
        $addressId = Arr::get($data, 'address_id');
        $addressRelation = $addressType === 'billing' ? 'billing_address' : 'shipping_address';

        $address = CustomerAddresses::query()->find($addressId);
        if (!empty($address)) {
            $order->load($addressRelation);
            $currentAddress = $order->{$addressRelation};
            if (empty($currentAddress)) {
                return static::createOrderAddress($address->toArray(), $order->id);
            } else {
                return static::mergeOrderAddress($currentAddress, $address->toArray());
            }
        }
    }

    public static function updateOrderAddress($data)
    {
        $orderId = sanitize_text_field(Arr::get($data, 'order_id'));
        $addressId = sanitize_text_field(Arr::get($data, 'id'));
        $orderAddress = OrderAddress::query()->where('order_id', $orderId)->where('id', $addressId)->first();
        if (empty($orderAddress)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('The address information does not match', 'fluent-cart')]
            ]);
        }

        $updateData = Arr::only($data, ['name', 'first_name', 'last_name', 'full_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country']);
        // sanitize the data before updating
        $updateData = array_map('sanitize_text_field', $updateData);
        return $orderAddress->update($updateData);

    }

    /**
     * Delete an order and associated data by ID.Including order meta, order items, transactions,
     *
     * @param int $id Required. The ID of the order to delete.
     * @param array $params Optional. Additional parameters for order deletion.
     *        [
     *              // Include optional parameters, if any.
     * ]
     *
     */
    public static function delete($id, $params = [])
    {
        $DB = App::db();
        $DB->beginTransaction();

        try {
            /** @var Order $order */
            $order = static::getQuery()->with("order_items")->find($id);
            $deletedOrder = clone $order;
            $deletedOrderItems = json_decode(json_encode(Arr::get($order, 'order_items', [])), true);
            // $getOrderNoActionableStatuses = ['unshippable'];
            // if(in_array($deletedOrder->shipping_status, $getOrderNoActionableStatuses)) {
            //     $deletedOrder->shipping_status = OrderMetaResource::find($deletedOrder->id, ['meta_key' => 'shipping_previous_status']);
            // }
            if (!empty($order)) {
                if ($order->status === Status::ORDER_COMPLETED) {
                    return static::makeErrorResponse([
                        ['code' => 400, 'message' => __('This order cannot be deleted because order status is completed.', 'fluent-cart')]
                    ]);
                }
                $order->orderMeta()->delete();
                $order->order_items()->delete();
                $order->transactions()->delete();
                $order->delete();
            }

            AppliedCouponResource::delete($order->id);
            $DB->commit();


            if (!empty($deletedOrder)) {
                (new OrderDeleted($deletedOrder))->dispatch();
            }
            if (!empty($deletedOrderItems)) {
                static::triggerEventsOnStockChanged($deletedOrderItems);
            }

            return static::makeSuccessResponse(
                '',
                __('Selected order and associated data has been deleted', 'fluent-cart')
            );

        } catch (\Exception $e) {
            $DB->rollBack();
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to delete', 'fluent-cart')]
            ]);
        }
    }

    /**
     * View details of an order by ID.
     *
     * This function retrieves details of an order by the specified ID. It includes information about the customer, order items with variants, transactions, discount meta, shipping meta,
     * and order settings.
     *
     * @param int $id Required. The ID of the order to view.
     *
     */
    public static function view(int $id)
    {
        $orders = static::search(
            ['fct_orders.id' => $id],
            function (Builder $query) {
                return $query
                    ->with(
                        [
                            'parentOrder'    => function ($query) {
                                return $query->select('id')
                                    ->with('subscriptions');
                            },
                            'subscriptions',
                            'activities.user',
                            'labels',
                            'customer',
                            'children'       => function ($query) {
                                return $query->select('id', 'parent_id', 'created_at');
                            },
                            //'order_items.variants.product_detail',
                            'order_items.variants.media',
                            'transactions',
                            'order_addresses',
                            'billing_address',
                            'shipping_address',
                            'appliedCoupons' => function ($query) {
                                $query->select('*');
                            }
                        ]
                    );
            }
        );

        if (empty($orders[0])) {
            return new \WP_Error('403', __('Order not found!', 'fluent-cart'));
        }

        $subscriptions = Arr::get($orders, '0.subscriptions');

        if (empty($subscriptions)) {
            $config = Arr::get($orders, '0.config', null);
            $upgradedFrom = is_array($config)
                ? Arr::get($config, 'upgraded_from', null)
                : (is_string($config) ? Arr::get(json_decode($config, true), 'upgraded_from', null) : null);

            $orders[0]['subscriptions'] = $upgradedFrom
                ? []
                : Arr::get($orders, '0.parent_order.subscriptions', []);
        }

        $data = [];

        if (isset($orders[0])) {
            $order = $orders[0];
            $selectedLabels = Collection::make($order['labels'])->pluck('label_id');
            $order['custom_checkout_url'] = PaymentHelper::getCustomPaymentLink(Arr::get($order, 'uuid'));

            $data = [
                'order'           => $order,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'discount_meta'   => OrderMetaResource::find($order['id'], ['meta_key' => 'order_discount']),
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'shipping_meta'   => OrderMetaResource::find($order['id'], ['meta_key' => 'order_shipping']),
                'order_settings'  => [
                    // 'has_vendor_refund' => PaymentMethodFactory::instance()->hasVendorRefund($order->payment_method)
                ],
                'selected_labels' => $selectedLabels,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'tax_id' => OrderMetaResource::find($order['id'], ['meta_key' => 'tax_id'])
            ];
        }

        return $data;
    }

    /**
     * Retrieve an overview of reports based on specified parameters.
     *
     * It calculates total sales, net sales, total discounts, total shipping tax, average order
     * value, and customer order count based on the reports data.
     *
     * @param array $params Required. Additional parameters for report overview.
     *        $params = [
     *              //(Required)
     *               "status" => [
     *                  "column" => "status",
     *                  "operator" => "in",
     *                  "value" => "Order success status e.g. completed,
     *               ],
     *
     *               //(Required)
     *               "payment_status" => [
     *                  "column" => "payment_status",
     *                  "operator" => "in",
     *                  "value" => "Transaction success status e.g. paid,
     *               ],
     *
     *               //(Optional)
     *               "created_at" => [
     *                  "column" => "created_at",
     *                  "operator" => "between"
     *                  "value" => "from and to date"
     *              ]
     *        ]
     *
     */
    public static function reportOverview($params = [])
    {
        return static::getQuery()->when(
            $params,
            function ($query) use ($params) {
                return $query->search($params);
            }
        )
            ->selectRaw('sum(total_amount) as total_sales')
            ->selectRaw('sum(total_amount - manual_discount_total - shipping_total - tax_total) as net_sales')
            ->selectRaw('sum(discount_total) as total_discounts')
            ->selectRaw('sum(shipping_total) as total_shipping_tax')
            ->selectRaw('avg(total_amount) as average_order_value')
            ->selectRaw('count(*) as customer_order_count')
            ->get()->first();
    }

    /**
     * Retrieve order summary based on payment methods and specified parameters.
     *
     * This function generates order summary by payment method, applying filters provided in the parameters.
     *
     * It retrieves the count of orders, total transactions, and groups the results by payment method.
     *
     * @param array $params Required. Additional parameters for order summary generation.
     *        $params = [
     *              //(Required)
     *               "status" => [
     *                  "column" => "status",
     *                  "operator" => "in",
     *                  "value" => "Order success status e.g. completed,
     *               ],
     *
     *               //(Required)
     *               "payment_status" => [
     *                  "column" => "payment_status",
     *                  "operator" => "in",
     *                  "value" => "Transaction success status e.g. paid,
     *               ],
     *
     *               //( Optional )
     *               'created_at' => [
     *                  'column' => 'created_at',
     *                  'operator' => 'between'
     *                  'value' => 'from and to date'
     * ]
     * ]
     *
     * @return Collection of orders
     */
    public static function orderSummaryByPayment(array $params = [])
    {
        return static::getQuery()->select('payment_method')
            ->when(
                $params,
                function ($query) use ($params) {
                    return $query->search($params);
                }
            )
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(total_amount) as transactions')
            ->groupBy('payment_method')
            ->get();
    }

    private static function addOrUpdateOrderMeta($params = [])
    {
        $orderId = Arr::get($params, 'order_id', null);
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $key = Arr::get($params, 'meta_key', '');
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $value = Arr::get($params, 'meta_value', '');
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        $isExist = OrderMetaResource::find($orderId, ['meta_key' => $key]);

        if ($isExist) {
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            return OrderMetaResource::update($value, $orderId, ['meta_key' => $key]);
        }
        return OrderMetaResource::create($params);
    }

    private static function triggerEventsOnStockChanged($orderItems)
    {
        if (!empty($orderItems)) {
            $productIds = [];
            foreach ($orderItems as $orderItem) {
                $productIds[] = Arr::get($orderItem, 'post_id');
            }
            if (!empty($productIds)) {
//                (new StockChanged($productIds))->dispatch();
            }
        }
    }

    public static function updateStatuses(array $params = [])
    {

        $order = Arr::get($params, 'order');
        if (empty($order)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Order not found!', 'fluent-cart')]
            ]);
        }

        $orderId = Arr::get($order, 'id');

        $order = static::getQuery()->with("order_items.variants.product_detail")->where('id', $orderId)->first();

        $action = Arr::get($params, 'action');

        $changeType = $action === 'change_shipping_status' ? 'shipping_status' : 'order_status';
        $actionActivity = [];

        if ($action === 'change_shipping_status') {
            $newStatus = Arr::get($params, 'statuses.shipping_status', null);
            $oldStatus = Arr::get($order, 'shipping_status');
            $validStatuses = Status::getEditableShippingStatuses();
            $actionActivity = [
                'title'   => __('Shipping status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Shipping status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];

            $orderItems = OrderItem::query()->where('fulfillment_type', 'physical')->where('order_id', $orderId)->get();
            $updateData = [];
            foreach ($orderItems as $item) {
                $updateData[] = [
                    'id'                 => $item->id,
                    'fulfilled_quantity' => in_array($newStatus, ['shipped', 'delivered']) ? $item->quantity : '0'
                ];
            }
            OrderItem::query()->batchUpdate($updateData);
        }
        if ($action === 'change_order_status') {
            $newStatus = Arr::get($params, 'statuses.order_status', null);
            $oldStatus = Arr::get($order, 'status');
            $validStatuses = Status::getEditableOrderStatuses();
            $shippingStatus = Arr::get($order, 'shipping_status');
            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];
        }

        if ($newStatus !== null) {
            if (isset($validStatuses[$newStatus])) {
                if ($newStatus != $oldStatus) {

                    $getOrderNoActionableStatuses = [Status::SHIPPING_UNSHIPPABLE];

                    if ($action === 'change_order_status') {
                        if ($oldStatus === Status::ORDER_CANCELED) {
                            return static::makeErrorResponse([
                                ['code' => 400, 'message' => __('You cannot change the order status once it has been canceled.', 'fluent-cart')]
                            ]);
                        }

                        $order = $order->updateStatus('status', $newStatus);

                        if ($newStatus === Status::ORDER_CANCELED) {
                            if (in_array($shippingStatus, $getOrderNoActionableStatuses)) {
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                                $shippingStatus = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                            }
                            (new OrderStatusUpdated($order, $shippingStatus, $newStatus, Arr::get($params, 'manage_stock', true), $actionActivity, $changeType))->dispatch();
                        } else {
                            (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, $actionActivity, $changeType))->dispatch();
                        }
                    }

                    if ($action === 'change_shipping_status') {

                        if (in_array($newStatus, $getOrderNoActionableStatuses)) {
                            static::addOrUpdateOrderMeta([
                                'order_id'   => $orderId,
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                                'meta_key'   => 'shipping_previous_status',
                                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                                'meta_value' => $oldStatus
                            ]);
                        }
                        if (in_array($oldStatus, $getOrderNoActionableStatuses)) {
                            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                            $oldStatus = OrderMetaResource::find($orderId, ['meta_key' => 'shipping_previous_status']);
                        }

                        if ($action !== 'change_shipping_status' && Arr::get($params, 'manage_stock') == 'true') {
                            $validationSucceeded = static::validateStock(Arr::get($order, 'order_items', []));

                            if (Arr::get($validationSucceeded, 'status') === true) {
                                return static::makeErrorResponse([
                                    ['code' => 400, 'message' => Arr::get($validationSucceeded, 'message')]
                                ]);
                            }
                        }

                        $order = $order->updateStatus('shipping_status', $newStatus);

                        (new OrderStatusUpdated($order, $oldStatus, $newStatus, Arr::get($params, 'manage_stock'), $actionActivity, $changeType))->dispatch();

                        $orderItems = json_decode(json_encode(Arr::get($order, 'order_items', [])), true);
                        static::triggerEventsOnStockChanged($orderItems);
                    }

                    return static::makeSuccessResponse(
                        $order,
                        __('Status has been updated', 'fluent-cart')
                    );
                }
                return static::makeErrorResponse([
                    ['code' => 400, 'message' => __('Order already has the same status', 'fluent-cart')]
                ]);
            }
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Provided status is not valid', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update status', 'fluent-cart')]
        ]);
    }

    private static function validateStock($orderItems)
    {
        $outOfStockVariants = [];

        foreach ($orderItems as $orderItem) {
            $quantity = (int)Arr::get($orderItem, 'quantity', 0);
            $stock = (int)Arr::get($orderItem, 'variants.available', 0);
            // $manageStock = (int)Arr::get($orderItem, 'variants.product_detail.manage_stock');
            $manageStock = (int)Arr::get($orderItem, 'variants.manage_stock');
            $variationTitle = Arr::get($orderItem, 'variants.variation_title');

            if ($manageStock == 1 && $stock - $quantity < 0) {
                $outOfStockVariants[] = $variationTitle;
            }
        }

        if (!empty($outOfStockVariants)) {
            $message = (count($outOfStockVariants) > 1)
                ? sprintf(
                    /* translators: %s is the list of out of stock variants */
                    __('%s are out of stock', 'fluent-cart'), implode(', ', $outOfStockVariants))
                : sprintf(
                    /* translators: %s is the out of stock variant */
                    __('%s is out of stock', 'fluent-cart'), reset($outOfStockVariants));


            return [
                'status'  => true,
                'message' => $message
            ];
        }

        return false;
    }

    /**
     * Delete orders and its associated data.
     *
     * @param array $orderIds The ids of the order to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function bulkDeleteByOrderIds($orderIds, $params = [])
    {
        $failedOrderIds = [];
        $deletedOrderIds = [];

        foreach ($orderIds as $order) {
            $isDeleted = static::delete($order);

            if (is_wp_error($isDeleted)) {
                $failedOrderIds[] = $order;
            } else {
                $deletedOrderIds[] = $order;
            }
        }

        if (count($failedOrderIds) > 0) {
            $failedOrderIds = implode(' , ', $failedOrderIds);
            return count($deletedOrderIds) > 0
                ? static::makeSuccessResponse('', sprintf(
                    /* translators: %s: The order ID(s) that could not be deleted. */
                    __("The order ID - %s cannot be deleted at the moment as these orders status is not canceled. And remaining order and its associated data have been deleted", 'fluent-cart'), $failedOrderIds))
                : static::makeErrorResponse([['code' => 400, 'message' => sprintf(
                    /* translators: %s: The order ID(s) that could not be deleted. */
                    __("The order ID - %s cannot be deleted at the moment as these orders status is not canceled.", 'fluent-cart'), $failedOrderIds)]]);
        }

        if (count($deletedOrderIds) > 0 && count($failedOrderIds) < 1) {
            return static::makeSuccessResponse('', __('Selected order and associated data have been deleted', 'fluent-cart'));
        }
    }

    public static function updatePaymentStatus(array $params = [])
    {
        $order = Arr::get($params, 'order');
        $transaction = Arr::get($params, 'transaction');
        $newStatus = Arr::get($params, 'status');

        if (empty($transaction)) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Transaction not found!', 'fluent-cart')]
            ]);
        }

        if ($transaction->status == $newStatus) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Transaction already has the same status', 'fluent-cart')]
            ]);
        }

        if ($transaction->order_id != $order->id) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('The selected transaction does not match with the provided order', 'fluent-cart')]
            ]);
        }

        $data = [];
        $totalPaid = ($order->total_paid - $transaction->total) < 0 ? 0 : $transaction->total;

        if ($newStatus == Status::PAYMENT_PAID) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'total_paid'     => ['+', $transaction->total],
            ];
        } elseif ($newStatus == Status::PAYMENT_REFUNDED) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'refunded_at'    => DateTime::gmtNow(),
                'total_paid'     => ['-', $totalPaid],
                'total_refund'   => ['+', $transaction->total],
            ];
        } elseif ($newStatus == (Status::PAYMENT_PENDING || Status::PAYMENT_FAILED)) {
            $data[] = [
                'id'             => $order->id,
                'payment_status' => $newStatus,
                'total_paid'     => ['-', $totalPaid],
            ];
        }

        $updatedStatus = $transaction->updateStatus($newStatus);

        if (!empty($data) && $updatedStatus) {
            $oldStatus = Arr::get($order, 'payment_status');
            $actionActivity = [
                'title'   => 'Payment status updated',
                'content' => sprintf(
                    /* translators: %1$s is the old status, %2$s is the new status */
                    __('Payment status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $newStatus)
            ];

            static::getQuery()->batchUpdate($data);

            (new OrderStatusUpdated($order, $oldStatus, $newStatus, false, $actionActivity, 'payment_status'))->dispatch();

            return static::makeSuccessResponse(
                $order,
                __('Payment Status has been updated', 'fluent-cart')
            );

        } else {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to update payment status', 'fluent-cart')]
            ]);
        }
    }

    private static function mergeOrderAddress(OrderAddress $address, array $addressData)
    {
        $keysToInclude = ['type', 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        foreach ($keysToInclude as $key) {
            $address->{$key} = $addressData[$key];
        }

        if ($address->save()) {
            return $address;
        }
        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update address', 'fluent-cart')]
        ]);
    }

    private static function createOrderAddress(array $address, $orderId)
    {
        $keysToInclude = ['order_id', 'type', 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        $address = Arr::only($address, $keysToInclude);
        $address['order_id'] = $orderId;

        if (!empty($address)) {
            return OrderAddressResource::create($address);
        }
    }

    public static function getOrderByHash($orderHash)
    {
        return (new Orders())->getByHash($orderHash);
    }

}
