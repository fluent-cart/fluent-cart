<?php

namespace FluentCart\App\Http\Controllers;


use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Events\Order\OrderBulkAction;
use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Events\Order\OrderDeleted;
use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Events\Order\RenewalOrderDeleted;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\OrderItemHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Requests\CustomerRequest;
use FluentCart\App\Http\Requests\OrderEditRequest;
use FluentCart\App\Http\Requests\OrderRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Validator\ValidationException;
use FluentCartPro\App\Hooks\Handlers\OrderActionsHandler;

class OrderController extends Controller
{
    public function index(Request $request): \WP_REST_Response
    {
        $orders = OrderFilter::fromRequest($request)->paginate();

        $orders = apply_filters('fluent_cart/orders_list', $orders);

        return $this->sendSuccess(
            [
                'orders' => $orders,
            ]
        );
    }

    /**
     * @throws \Exception
     */
    public function store(OrderRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $type = 'payment';
        $hasSubscription = static::hasSubscription(Arr::get($data, 'order_items', []));
        if ($hasSubscription) {
            $type = 'subscription';
            // right now we don't support subscription with manual order
            $isSubscriptionAllowedInManualOrder = apply_filters('fluent_cart/order/is_subscription_allowed_in_manual_order', false, [
                'order_items' => Arr::get($data, 'order_items', [])
            ]);

            if (!$isSubscriptionAllowedInManualOrder) {
                return $this->sendError([
                    'message' => __('Subscription order with Manual Order is not supported yet!', 'fluent-cart')
                ], 400);
            }

        }


        $data['type'] = apply_filters('fluent_cart/order/type', $type, []);
        $order = OrderResource::updatedPlaceOrder($data);


        if (is_wp_error($order)) {
            return $order;
        }

        // isCreated is an orderHelper instance
        (new OrderCreated($order, null, $order->customer))->dispatch();

        return $this->response->sendSuccess([
            'message'  => __('Order created successfully!', 'fluent-cart'),
            'order_id' => $order->id
        ]);
    }


    public static function hasSubscription($orderItems): bool
    {
        // check order items for subscription, payment_type == subscription
        foreach ($orderItems as $item) {
            if (Arr::get($item, 'payment_type') == 'subscription' || Arr::get($item, 'other_info.payment_type') == 'subscription') {
                return true;
            }
        }
        return false;
    }

    public function updateOrder(OrderEditRequest $request, $order_id)
    {
        $order = Order::query()->find($order_id);

        if ($order->isSubscription()) {
            return $this->sendError([
                'message' => __('Subscription Order cannot be edited.', 'fluent-cart')
            ], 400);
        }


        $requestData = $request->getSafe($request->sanitize());

        $totalPaid = Arr::get($request->all(), 'total_paid');
        $updatedTotal = Arr::get($requestData, 'total_amount');

        if ($totalPaid > 0 && floatval($updatedTotal > $totalPaid)
            && isset($requestData['payment_status'])
            && $requestData['payment_status'] !== Status::PAYMENT_PARTIALLY_REFUNDED
        ) {
            $requestData['payment_status'] = Status::PAYMENT_PARTIALLY_PAID;
        }

        $status = Arr::get($requestData, 'status');
        if ($status == Status::ORDER_COMPLETED) {
            return $this->sendError([
                'message' => esc_html__('Completed status can not be updated', 'fluent-cart')
            ], 400);
        }

        // if new shipping total is already adjusted in total amount, then no need to adjust again, right now not adjusted before
        // ToDo: adjust changed shipping total in total amount prior to this
        $shippingTotal = Arr::get($requestData, 'shipping_total', 0);
        $oldShippingTotal = Arr::get($order, 'shipping_total', 0);
        if ($shippingTotal != $oldShippingTotal) {
            $diff = $shippingTotal - $oldShippingTotal;
            if ($diff < 0) {
                $requestData['total_amount'] = $updatedTotal - abs($diff);
            } else {
                $requestData['total_amount'] = $updatedTotal + $diff;
            }
        }

        $data = [
            'orderData'         => $requestData,
            'deletedItems'      => Arr::get($requestData, 'deletedItems', []),
            'discount'          => Arr::get($requestData, 'discount', ''),
            'shipping'          => Arr::get($requestData, 'shipping', ''),
            'couponCalculation' => Arr::get($requestData, 'couponCalculation', []),
        ];

        $isUpdated = OrderResource::update($data, $order->id);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }

        return $this->response->sendSuccess($isUpdated);
    }

    public function updateOrderAddressId(Request $request, $order_id)
    {
        $order = Order::query()->find($order_id);


        if (!$order) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart')
            ], 404);
        }
        $data = OrderResource::updateOrderAddressId($request->only([
            'address_id',
            'address_type'
        ]), $order);

        if (is_wp_error($data)) {
            return $this->sendError($data->get_error_message());
        }
        return $this->sendSuccess([
            'message' => 'Address updated successfully'
        ]);


    }

    public function generateMissingLicenses(Request $request, Order $order)
    {
        if (!$order) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart')
            ], 404);
        }

        $generatedLicenseCount = $order->licenses->count();
        $expectedLicenseCount = apply_filters('fluent_cart/order/expected_license_count', 0, [
            'order_items' => $order->order_items
        ]);

        if ($generatedLicenseCount >= $expectedLicenseCount) {
            return $this->sendError([
                'message' => __('No missing licenses found!', 'fluent-cart')
            ], 400);
        }

        do_action('fluent_cart/order/generateMissingLicenses', ['order' => $order]);

    }

    /**
     * @throws ValidationException
     */
    public function refundOrder(Request $request, $orderId)
    {
        $order = Order::query()->findOrFail($orderId);

        if (!$order->canBeRefunded()) {
            return $this->sendError([
                'message' => __('Order can not be refunded.', 'fluent-cart')
            ], 400);
        }

        $refundInfo = $request->get('refund_info', []);

        $this->validate($refundInfo, [
            'transaction_id' => 'required',
            'amount'         => 'required',
        ], [
            'transaction_id.required' => __('Transaction ID is required', 'fluent-cart'),
            'amount.required'         => __('Refund amount is required', 'fluent-cart'),
        ]);

        $transaction = OrderTransaction::query()->findOrFail($refundInfo['transaction_id']);
        $refundAmount = Helper::toCent($refundInfo['amount']);

        // refund on our end
        $result = (new Refund())->processRefund($transaction, $refundAmount, $refundInfo);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        $vendorRefundId = $result['vendor_refund_id'];

        $responseData = [
            'fluent_cart_refund' => [
                'status'  => 'success',
                'message' => 'Refund processed on FluentCart.'
            ],
            'gateway_refund'     => [
                'status'  => is_wp_error($vendorRefundId) ? 'failed' : 'success',
                'message' => !is_wp_error($vendorRefundId) ? 'Refund processed on ' . ucfirst($transaction->payment_method) : 'ERROR: ' . $vendorRefundId->get_error_message()
            ]
        ];

        if (is_wp_error($vendorRefundId)) {
            fluent_cart_warning_log('Refund failed on ' . ucfirst($transaction->payment_method), $vendorRefundId->get_error_message(), [
                'module_name' => 'order',
                'module_id'   => $order->id,
                'log_type'    => 'api'
            ]);
        }

        $cancelSubscription = Arr::get($refundInfo, 'cancelSubscription') == 'true';

        if ($cancelSubscription && $transaction->subscription_id && $transaction->subscription) {
            $vendorSubscriptionCancelled = $transaction->subscription->cancelRemoteSubscription([
                'reason' => 'refunded'
            ]);
            if (is_wp_error($vendorSubscriptionCancelled)) {
                $responseData['subscription_cancel']['status'] = 'failed';
                $responseData['subscription_cancel']['message'] = $vendorSubscriptionCancelled->get_error_message();
            } else {
                $vendorResult = $vendorSubscriptionCancelled['vendor_result'];
                $responseData['subscription_cancel']['status'] = is_wp_error($vendorResult) ? 'failed' : 'success';
                $responseData['subscription_cancel']['message'] = is_wp_error($vendorResult)
                    ? $vendorResult->get_error_message()
                    : __('Subscription cancelled successfully', 'fluent-cart');
            }
        }

        return $this->sendSuccess(
            $responseData
        );
    }


    public function createAndChangeCustomer(CustomerRequest $request, $order_id)
    {

        $data = $request->getSafe($request->sanitize());
        $isCreated = CustomerResource::create($data);

        if (is_wp_error($isCreated)) {
            return $this->sendError(
                [
                    'message' => __('Failed to attach customer', 'fluent-cart')
                ]
            );
        }

        $customerId = Arr::get($isCreated, 'data.id');

        $isChanged = $this->updateOrderCustomer($customerId, $order_id);
        if (is_wp_error($isChanged)) {
            return $this->sendError(
                [
                    'message' => $isChanged->get_error_message()
                ]
            );
        }
        return $this->sendSuccess($isChanged);

    }

    public function changeCustomer(Request $request, $order_id)
    {
        $customerId = $request->get('customer_id');
        $customerId = sanitize_text_field($customerId);

        if (!$customerId) {
            return $this->sendError([
                'message' => __('Customer id is required', 'fluent-cart')
            ], 423);
        }

        $isChanged = $this->updateOrderCustomer($customerId, $order_id);
        if (is_wp_error($isChanged)) {
            return $this->sendError(
                [
                    'message' => $isChanged->get_error_message()
                ]
            );
        }
        return $this->sendSuccess($isChanged);

    }

    private function updateOrderCustomer($customerId, $orderId)
    {

        /**
         *  1. Check if it's a different customer
         *  2. Update main order.customer_id
         *  3. update subscription.customer_id
         *  4. update license.customer_id
         *
         *
         *  // critical thinking
         *  5. If it's a renewal then change the parent order's data as well it's all child resources
         *  6. Recount Customer's stat (New as well as the old one!)
         */

        $order = Order::query()->findOrFail($orderId);

        if ($order->customer_id == $customerId) {
            return [
                'message' => __('Customer is already attached to this order', 'fluent-cart')
            ];
        }
        $newCustomer = Customer::query()->findOrFail($customerId);
        $oldCustomerId = $order->customer_id;

        CustomerAddresses::query()->where('customer_id', $oldCustomerId)->update(['customer_id' => $customerId]);
        CustomerMeta::query()->where('customer_id', $oldCustomerId)->update(['customer_id' => $customerId]);

        $connectedOrderIds = [$order->id];
        if ($order->parent_id && $order->type == 'renewal') {
            $connectedOrderIds[] = $order->parent_id;
            $parentOrderIdsOrders = Order::query()->where('parent_id', $order->parent_id)->get()->pluck('id')->toArray();
            $connectedOrderIds = array_merge($parentOrderIdsOrders, $connectedOrderIds);

        } else if ($order->type == 'subscription') {
            $childOrderIds = Order::query()->where('parent_id', $order->id)->get()->pluck('id')->toArray();
            $connectedOrderIds = array_merge($childOrderIds, $connectedOrderIds);
        }
        Order::query()->whereIn('id', $connectedOrderIds)->update(['customer_id' => $customerId]);
        Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->update(['customer_id' => $customerId]);

        $newCustomer->recountStat();
        $oldCustomer = Customer::query()->find($oldCustomerId);
        if (!empty($oldCustomer)) {
            $oldCustomer->recountStat();
        }

        do_action('fluent_cart/order_customer_changed', [
            'order'               => $order,
            'old_customer'        => $oldCustomer,
            'new_customer'        => $newCustomer,
            'connected_order_ids' => $connectedOrderIds
        ]);

        fluent_cart_success_log(
            __('Customer changed', 'fluent-cart'),
            sprintf(
                /* translators: 1: old customer name, 2: new customer name */
                __('Customer changed from %1$s to %2$s', 'fluent-cart'), $oldCustomer->full_name, $newCustomer->full_name),
            [
                'module_name' => 'order',
                'module_id'   => $orderId,
                'log_type'    => 'activity'
            ]);

        return [
            'message' => __('Customer changed successfully', 'fluent-cart')
        ];
    }

    public function deleteOrder(Request $request, $order_id)
    {

        $order = Order::query()->find($order_id);

        if (empty($order)) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart'),
                'data'    => [
                    'order_id' => $order_id,
                    'status'   => 'error'
                ],
                'errors'  => []
            ], 404);
        }

        $order_id = $order->id; // Get the single order ID
        // Find the order with additional details

        $canBeDeleted = $order->canBeDeleted();
        if (is_wp_error($canBeDeleted)) {

            return $this->sendError([
                'message' => $canBeDeleted->get_error_message(),
                'data'    => [
                    'order_id'   => $order_id,
                    'invoice_no' => $order->invoice_no,
                    'status'     => 'error',
                    'reason'     => $canBeDeleted->get_error_code()
                ],
                'errors'  => [
                    $canBeDeleted->get_error_message()
                ]
            ], 400);
        }


        $connectedOrderIds = [$order->id];

        if ($order->type === 'subscription') {
            $childOrderIds = Order::query()->where('parent_id', $order->id)->get()->pluck('id')->toArray();
            $connectedOrderIds = array_merge($childOrderIds, $connectedOrderIds);
            Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->delete();
        } else if ($order->type === 'renewal') {

            $this->deleteOrderRelatedData([$order->id], $order->type);

            (new RenewalOrderDeleted($order))->dispatch();

            return $this->sendSuccess([
                'message' => sprintf(
                    /* translators: %s is the order/invoice number */
                    __('Order %s deleted successfully', 'fluent-cart'), $order_id),
                'data'    => [
                    'order_id'   => $order_id,
                    'invoice_no' => $order->invoice_no,
                    'status'     => 'success'
                ],
                'errors'  => []
            ]);

        }


        $this->deleteOrderRelatedData($connectedOrderIds, $order->type);

        (new OrderDeleted($order, $connectedOrderIds))->dispatch();

        return $this->sendSuccess([
            'message' => sprintf(
                /* translators: %s is the order id */
                __('Order %s deleted successfully', 'fluent-cart'), $order_id),
            'data'    => [
                'order_id'   => $order_id,
                'invoice_no' => $order->invoice_no,
                'status'     => 'success'
            ],
            'errors'  => []
        ]);

    }

    /**
     * Delete order related data (transactions, items, meta, addresses, orders)
     */
    private function deleteOrderRelatedData(array $orderIds, $type = 'renewal')
    {
        OrderTransaction::query()->whereIn('order_id', $orderIds)->delete();
        if ($type !== 'renewal') {
            OrderAddress::query()->whereIn('order_id', $orderIds)->delete();
        }
        OrderItem::query()->whereIn('order_id', $orderIds)->delete();
        OrderMeta::query()->whereIn('order_id', $orderIds)->delete();
        Order::query()->whereIn('id', $orderIds)->delete();
    }


    public function getDetails($orderId)
    {
        $data = OrderResource::view($orderId);

        if (is_wp_error($data) || empty($data['order'])) {
            return $this->entityNotFoundError(
                __('Order not found', 'fluent-cart'),
                __('Back to orders', 'fluent-cart'),
                '/orders'
            );
        }

        $data['order'] = apply_filters('fluent_cart/order/view', $data['order'], []);

        // check if the order has generated license
        $data['order']['has_missing_licenses'] = false;

        $expectedLicenseCount = apply_filters('fluent_cart/order/expected_license_count', 0, [
            'order_items' => Arr::get($data, 'order.order_items', [])
        ]);

        $generatedLicenseCount = count(Arr::get($data, 'order.licenses', []));
        if ($expectedLicenseCount && ($expectedLicenseCount > $generatedLicenseCount)) {
            $data['order']['has_missing_licenses'] = true;
        }

        $data['order']['order_operation'] = OrderOperation::query()->where('order_id', $orderId)
            ->first();

        $url = URL::appendQueryParams(
            (new StoreSettings())->getReceiptPage(),
            [
                'order_hash' => Arr::get($data, 'order.uuid')
            ]
        );

        if (empty($data['order']['receipt_url'])) {
            $data['order']['receipt_url'] = $url;
        }
        $meta = OrderMeta::query()->where('order_id', $orderId)
            ->where('meta_key', 'vat_tax_id')
            ->first();

        if ($meta) {
            $data['tax_id'] =  $meta->meta_value;
        }


        return $data;
    }

    public function createCustom(Request $request, OrderItemHelper $orderItemHelper, Order $order)
    {
        try {
            return $orderItemHelper->processCustom(
                $request->product,
                $order->id
            );

        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }

    }

    //    public function calculate(Request $request, OrderHelper $orderHelper)
//    {
//        return $orderHelper->calculate($request->order);
//    }

    public function updateStatuses(Request $request, Order $order)
    {

        $data = [
            'order'        => $order,
            'statuses'     => $request->get('statuses', []),
            'manage_stock' => $request->get('manage_stock'),
            'action'       => $request->get('action')
        ];
        $isUpdated = OrderResource::updateStatuses($data);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function updateOrderAddress(Request $request, $orderId, $addressId)
    {

        $data = $request->all();

        $isUpdated = OrderResource::updateOrderAddress($data);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }


    public function markAsPaid(Request $request, Order $order)
    {
        $dueAmount = intval($order->total_amount - $order->total_paid);

        if ($dueAmount <= 0) {
            return $this->sendError([
                'message' => __('Order has already been paid', 'fluent-cart')
            ], 423);
        }

        if (Arr::get($order, 'status') === 'canceled') {
            return $this->sendError([
                'message' => __('Unable to mark paid for canceled order', 'fluent-cart')
            ], 423);
        }

        $transaction = $order->transactions->where('status', Status::TRANSACTION_PENDING)
            ->where('payment_method', 'offline_payment')
            ->first();

        $newTransactionData = [
            'total'               => $dueAmount,
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'payment_method'      => sanitize_text_field($request->payment_method),
            'vendor_charge_id'    => sanitize_text_field($request->vendor_charge_id),
            'payment_mode'        => sanitize_text_field($order->mode),
            'payment_method_type' => sanitize_text_field($request->payment_method),
            'order_type'          => sanitize_text_field($order->type),
            'transaction_type'    => sanitize_text_field($request->transaction_type),
            'currency'            => sanitize_text_field($order->currency),
        ];

        if ($transaction) {
            $transaction->update($newTransactionData);
        } else {
            $transaction = OrderTransaction::query()->create(
                array_merge($newTransactionData, [
                    'order_id' => $order->id
                ])
            );
        }

        $order->note = sanitize_text_field($request->get('mark_paid_note', ''));

        $oldStatus = $order->status;

        if ($order->payment_status !== 'partially_refunded') {
            $order->payment_status = Status::PAYMENT_PAID;
        }

        $order->status = Status::ORDER_PROCESSING;
        $order->total_paid = $order->total_amount;
        $order->save();

        $actionActivity = [
            'title'   => __('Order status updated', 'fluent-cart'),
            'content' => sprintf(
                /* translators: 1: old status, 2: new status */
                __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldStatus, $order->status)
        ];

        // dispatching events related to order status update and payment paid
        (new OrderPaid($order, $order->customer, $transaction))->dispatch();

        (new OrderStatusUpdated($order, $oldStatus, $order->status, true, $actionActivity, 'order_status'))->dispatch();

        // if digital
        if ($order->fulfillment_type == 'digital' && $order->status === Status::ORDER_PROCESSING) {
            $order->status = Status::ORDER_COMPLETED;
            $order->completed_at = DateTime::gmtNow();
            $order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    /* translators: 1: old status, 2: new status */
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), Status::ORDER_PROCESSING, $order->status)
            ];

            (new OrderStatusUpdated($order, Status::ORDER_PROCESSING, $order->status, true, $actionActivity, 'order_status'))->dispatch();
        }


        $eventData = [
            'order'       => $order,
            'transaction' => $transaction,
            'customer'    => $order->customer
        ];

        if ($order->type === 'subscription' || $order->type === 'renewal') {
            $subscription = \FluentCart\App\Models\Subscription::query()->where('parent_order_id', $order->id)->first();
            if ($subscription) {
                $eventData['subscription'] = $subscription;
            }
        }

        do_action('fluent_cart/order_paid_done', $eventData);

        return $this->response->sendSuccess([
            'message' => __('Order has been marked as paid', 'fluent-cart')
        ]);
    }

    public function handleBulkActions(Request $request)
    {

        $action = sanitize_text_field($request->get('action', ''));
        $orderIds = $request->get('order_ids', []);

        $orderIds = array_map(function ($id) {
            return (int)$id;
        }, $orderIds);

        $orderIds = array_filter($orderIds);

        if (!$orderIds) {
            return $this->sendError([
                'message' => __('Orders selection is required', 'fluent-cart')
            ]);
        }

        $orders = Order::query()->whereIn('id', $orderIds)->get();


        if ($action == 'delete_orders') {

            $isDeleted = OrderResource::bulkDeleteByOrderIds($orderIds);

            if (is_wp_error($isDeleted)) {
                return $isDeleted;
            }
            return $this->response->sendSuccess($isDeleted);

            // $DB = App::db();
            // $DB->beginTransaction();

            // try {

            //     OrderResource::bulkDeleteByOrderIds($orderIds);
            //     OrderTransaction::bulkDeleteByOrderIds($orderIds);
            //     OrderMetaResource::bulkDeleteByOrderIds($orderIds);
            //     OrderItemResource::bulkDeleteByOrderIds($orderIds);

            //     $DB->commit();

            //     return [
            //         'message' => __('Selected orders and their associated resources have been deleted permanently', 'fluent-cart')
            //     ];
            // } catch (\Exception $e) {
            //     $DB->rollBack();
            //     return static::makeErrorResponse([
            //         ['code' => 400, 'message' => __('Failed to delete orders and their associated resources', 'fluent-cart')]
            //     ]);
            // }


        }
        if ($action == 'change_shipping_status') {
            $newStatus = sanitize_text_field($request->get('new_status', ''));
            if (!$newStatus) {
                return $this->sendError([
                    'message' => __('Please select status', 'fluent-cart')
                ]);
            }

            $validStatuses = Helper::getShippingStatuses();
            if (!isset($validStatuses[$newStatus])) {
                return $this->sendError([
                    'message' => __('Provided shipping status is not valid', 'fluent-cart')
                ]);
            }

            // foreach ($orders as $order) {
            //     $order->updateShippingStatus($newStatus);
            // }

            return [
                'message' => __('Shipping Status has been changed for the selected orders', 'fluent-cart')
            ];

        }
        if ($action == 'change_order_status') {

            $newStatus = sanitize_text_field($request->get('new_status', ''));
            if (!$newStatus) {
                return $this->sendError([
                    'message' => __('Please select status', 'fluent-cart')
                ]);
            }

            $validStatuses = Status::getEditableOrderStatuses();
            if (!isset($validStatuses[$newStatus])) {
                return $this->sendError([
                    'message' => __('Provided order status is not valid', 'fluent-cart')
                ]);
            }

            $failedOrderIds = [];
            $updatedOrderIds = [];

            foreach ($orders as $order) {
                // $order->updateStatus('status', $newStatus);
                $isUpdated = OrderResource::updateStatuses([
                    'order'                 => $order,
                    'action'                => 'change_order_status',
                    'statuses.order_status' => $newStatus,
                    'manage_stock'          => sanitize_text_field($request->get('manage_stock')),
                ]);

                if (is_wp_error($isUpdated)) {
                    $failedOrderIds[] = $order->id;
                } else {
                    $updatedOrderIds[] = $order->id;
                }
            }

            if (count($failedOrderIds) > 0) {
                $failedOrderIds = implode(' , ', $failedOrderIds);
                return count($updatedOrderIds) > 0
                    ? $this->sendSuccess([
                        'message' => sprintf(
                            /* translators: %s is the order ids */
                            __("The order ID - %s cannot be updated because they are either already cancelled or have the same status. And remaining order status has been successfully changed", 'fluent-cart'), $failedOrderIds)
                    ])
                    :
                    $this->sendError([
                        'message' => sprintf(
                            /* translators: %s is the order ids */
                            __("The order ID - %s cannot be updated because they are either already cancelled or have the same status.", 'fluent-cart'), $failedOrderIds)
                    ], 423);
            }

            if (count($updatedOrderIds) > 0 && count($failedOrderIds) < 1) {
                return $this->sendSuccess([
                    'message' => __('Order Status has been changed for the selected orders', 'fluent-cart')
                ]);
            }
        }

        if ($action == 'capture_payments') {
            foreach ($orders as $order) {
                $order->capturePayments();
            }

            return [
                'message' => __('Selected payments has been successfully captured', 'fluent-cart')
            ];
        }

        if ($action == 'change_payment_status') {
            $newStatus = sanitize_text_field($request->get('new_status', ''));
            if (!$newStatus) {
                return $this->sendError([
                    'message' => __('Please select status', 'fluent-cart')
                ]);
            }

            $validStatuses = Status::getEditableTransactionStatuses();
            if (!isset($validStatuses[$newStatus])) {
                return $this->sendError([
                    'message' => __('Provided payment status is not valid', 'fluent-cart')
                ]);
            }

            $failedOrderIds = [];
            $updatedOrderIds = [];
            $count = 0;
            $customerIds = [];

            foreach ($orders as $order) {
                $transaction = $order->latest_transaction;
                $isUpdated = OrderResource::updatePaymentStatus([
                    'order'       => $order,
                    'status'      => $newStatus,
                    'transaction' => $transaction,
                ]);

                if (is_wp_error($isUpdated)) {
                    $failedOrderIds[] = $order->id;
                } else {
                    $updatedOrderIds[] = $order->id;
                    $count++;
                    $customerIds[] = $order->customer_id;
                }
            }

            if ($count > 0 && count($customerIds) > 0) {
                (new OrderBulkAction($customerIds))->dispatch();
            }

            if (count($failedOrderIds) > 0) {
                $failedOrderIds = implode(' , ', $failedOrderIds);
                return count($updatedOrderIds) > 0
                    ? $this->sendSuccess([
                        'message' => sprintf(
                            /* translators: %s is the order ids */
                            __("The order ID - %s cannot be updated at the moment because the transaction either already has the same status or does not match the provided order. The remaining orders statuses have been updated successfully.", 'fluent-cart'), $failedOrderIds)
                    ])
                    :
                    $this->sendError([
                        'message' => sprintf(
                            /* translators: %s is the order ids */
                            __("The order ID - %s cannot be updated at the moment because its payment status is either the same as before or has already been refunded.", 'fluent-cart'), $failedOrderIds)
                    ], 423);
            }

            if (count($updatedOrderIds) > 0 && count($failedOrderIds) < 1) {
                return $this->sendSuccess([
                    'message' => sprintf(
                        /* translators: %s is the payment status */
                        __("Selected orders payment status has been marked as %s", 'fluent-cart'),
                        $newStatus
                    )
                ]);
            }
        }

        return $this->sendError([
            'message' => __('Selected action is invalid', 'fluent-cart')
        ]);

    }

    public function updateTransactionStatus(Request $request, $order, OrderTransaction $transaction)
    {

        $order = Order::query()->find($order);
        $newStatus = $request->get('status');
        if ($transaction->status == $newStatus) {
            return $this->sendError([
                'reload'  => true,
                'message' => __('Transaction already has the same status', 'fluent-cart')
            ]);
        }

        if ($transaction->order_id != $order->id) {
            return $this->sendError([
                'message' => __('The selected transaction does not match with the provided order', 'fluent-cart')
            ]);
        }

        $transaction->updateStatus($newStatus);
        $order->updatePaymentStatus($newStatus);

        return [
            'transaction' => $transaction,
            'message'     => __('Payment status been successfully updated', 'fluent-cart')
        ];
    }

    public function getStats($orderUuid): \WP_REST_Response
    {
        $order = OrderResource::find($orderUuid);
        return $this->sendSuccess([
            'widgets' => apply_filters('fluent_cart/widgets/single_order', [], $order)
        ]);
    }

    public function getShippingMethods(Request $request): \WP_REST_Response
    {
        $countryCode = $request->get('country_code');
        $state = $request->get('state') ?? '';
        $orderItems = $this->prepareOrderItemsWithVariations($request->get('order_items'));

        $enabledMethods = $this->getEnabledShippingMethodsWithCharges($orderItems);

        if (empty($countryCode)) {
            return $this->sendSuccess([
                'shipping_methods'       => [],
                'other_shipping_methods' => $enabledMethods,
            ]);
        }

        $applicableMethods = ShippingMethod::query()
            ->applicableToCountry($countryCode, $state)
            ->get();

        $applicableIds = $applicableMethods->pluck('id')->toArray();

        return $this->sendSuccess([
            'shipping_methods'       => $enabledMethods->whereIn('id', $applicableIds)->values(),
            'other_shipping_methods' => $enabledMethods->whereNotIn('id', $applicableIds)->values(),
        ]);
    }

    protected function getEnabledShippingMethodsWithCharges(array $orderItems)
    {
        return ShippingMethod::query()
            ->where('is_enabled', '1')
            ->get()
            ->each(function ($method) use ($orderItems) {
                $method->shipping_charge = CartHelper::calculateShippingMethodCharge($method, $orderItems);
            });
    }

    public function updateShipping(Request $request)
    {
        $orderItems = $request->get('order_items');
        $shippingMethodId = $request->get('shipping_id');

        $orderItems = $this->prepareOrderItemsWithVariations($orderItems);


        $method = ShippingMethod::query()->find($shippingMethodId);

        $totalShippingCharge = CartHelper::calculateShippingMethodCharge($method, $orderItems);

        return $this->sendSuccess([
            'message'         => __('Shipping updated', 'fluent-cart'),
            'shipping_charge' => $totalShippingCharge,
            'order_items'     => $orderItems
        ]);

    }

    protected function prepareOrderItemsWithVariations($orderItems)
    {
        $itemCollection = (new Collection($orderItems))->keyBy('id');
        $ids = $itemCollection->keys()->toArray();
        $variations = ProductVariation::query()->with('shippingClass')->whereIn('id', $ids)->get();
        $orderItems = $itemCollection->toArray();

        foreach ($variations as &$variation) {
            $shippingCharge = CartHelper::calculateShippingCharge($variation, $itemCollection->get($variation->id)['quantity']);
            $variation->quantity = Arr::get($orderItems, $variation->id . '.' . 'quantity', 1);
            $variation->discount_total = Arr::get($orderItems, $variation->id . '.' . 'discount_total', 0);
            $variation->shipping_charge = $shippingCharge;
            $variation->unit_price = $variation->item_price;

        }

        $orderItems = $variations->mapWithKeys(function ($item) {
            return [
                $item->id => [
                    'id'               => $item->id,
                    'quantity'         => $item->quantity,
                    'shipping_charge'  => $item->shipping_charge,
                    'unit_price'       => $item->unit_price,
                    'other_info'       => $item->other_info,
                    'discount_total'   => $item->discount_total,
                    'fulfillment_type' => $item->fulfillment_type,
                ]
            ];
        });
        $orderItems = $orderItems->toArray();

        return $orderItems;
    }

    public function acceptDispute(Request $request, $order, $transaction)
    {
        $order = Order::query()->find($order);
        $transaction = OrderTransaction::query()->find($transaction);
        $response = $transaction->acceptDispute([
            'dispute_note' => $request->getSafe('dispute_note', 'sanitize_text_field'),
        ]);

        if (is_wp_error($response)) {
            return $this->sendError([
                'message' => $response->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Dipute accepeted!', 'fluent-cart')
        ]);
    }

}
