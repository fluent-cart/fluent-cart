<?php

namespace FluentCart\App\Http\Controllers;


use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Events\Order\OrderDeleting;
use FluentCart\App\Events\Order\OrderDeleted;
use FluentCart\App\Events\Order\RenewalOrderDeleted;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\OrderItemHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Http\Requests\CustomerRequest;
use FluentCart\App\Http\Requests\OrderRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\CustomerMeta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderOperation;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Reminders\ReminderService;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Validator\ValidationException;
use FluentCartPro\App\Hooks\Handlers\OrderActionsHandler;

class OrderController extends Controller
{
    protected function getTestOrdersQuery()
    {
        return Order::query()
            ->where('mode', Status::ORDER_MODE_TEST);
    }

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
        $orderItems = Arr::get($data, 'order_items', []);

        $variationPaymentTypes = static::getVariationPaymentTypes($orderItems);

        foreach ($orderItems as $item) {
            $paymentTypeError = static::getPaymentTypeConflict($item, $variationPaymentTypes);
            if ($paymentTypeError) {
                return $this->sendError([
                    'message' => $paymentTypeError
                ], 400);
            }
        }

        $hasSubscription = static::hasSubscription($orderItems);
        if ($hasSubscription) {
            $type = 'subscription';
            // right now we don't support subscription with manual order
            $isSubscriptionAllowedInManualOrder = apply_filters('fluent_cart/order/is_subscription_allowed_in_manual_order', true, [
                'order_items' => $orderItems
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
            'order_id' => $order->id,
            'uuid'     => $order->uuid
        ]);
    }

    public static function hasSubscription($orderItems): bool
    {
        foreach ($orderItems as $item) {
            if (Arr::get($item, 'payment_type') == 'subscription' || Arr::get($item, 'other_info.payment_type') == 'subscription') {
                return true;
            }
        }

        return false;
    }

    /**
     * The variation row decides whether a line is recurring, so every label the payload
     * carries has to agree with it. A recurring line must additionally be labelled in
     * other_info: that is the only copy AdminOrderProcessor reads, and the interval and
     * installment count travel beside it.
     *
     * @return string empty when the line is consistent, else the rejection message
     */
    protected static function getPaymentTypeConflict($item, $variationPaymentTypes): string
    {
        $variationId = (int)Arr::get($item, 'object_id', 0);

        if (!isset($variationPaymentTypes[$variationId])) {
            return '';
        }

        $isSubscriptionVariation = $variationPaymentTypes[$variationId] === 'subscription';

        foreach (['payment_type', 'other_info.payment_type'] as $labelKey) {
            $label = Arr::get($item, $labelKey);
            if (is_null($label) || $label === '') {
                continue;
            }

            if (($label === 'subscription') !== $isSubscriptionVariation) {
                return $isSubscriptionVariation
                    ? __('Subscription product cannot be placed as a one time item.', 'fluent-cart')
                    : __('One time product cannot be placed as a subscription item.', 'fluent-cart');
            }
        }

        if ($isSubscriptionVariation && Arr::get($item, 'other_info.payment_type') !== 'subscription') {
            return __('Subscription product must be placed as a subscription item.', 'fluent-cart');
        }

        return '';
    }

    /**
     * @return array variation id => stored payment_type, for the lines that resolve
     */
    protected static function getVariationPaymentTypes($orderItems): array
    {
        $variationIds = [];
        foreach ($orderItems as $item) {
            $variationId = (int)Arr::get($item, 'object_id', 0);
            if ($variationId > 0) {
                $variationIds[$variationId] = $variationId;
            }
        }

        if (!$variationIds) {
            return [];
        }

        $variations = ProductVariation::query()
            ->whereIn('id', $variationIds)
            ->get(['id', 'payment_type']);

        $paymentTypes = [];
        foreach ($variations as $variation) {
            $paymentTypes[(int)$variation->id] = $variation->payment_type;
        }

        return $paymentTypes;
    }

    public function updateOrder(OrderRequest $request, $order_id)
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

        // Changing the order address recalculates tax server-side
        // (OrderResource::updateOrderAddressId → reapplyTaxAfterUpdate). Return the
        // refreshed order with the tax appends so the admin UI can update the tax
        // summary, totals and payment status without a full page reload.
        $freshOrder = Order::query()->where('id', $order_id)
            ->addAppends([
                'business_info',
                'customer_tax_number',
                'is_b2b_order',
                'display_tax_lines',
                'display_shipping_tax_lines',
                'is_reverse_charge_tax_order',
                'tax_summary',
            ])
            ->first();

        return $this->sendSuccess([
            'message' => __('Address updated successfully', 'fluent-cart'),
            'order'   => $freshOrder,
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
     * Refund against an order transaction.
     *
     * `refund_info.amount` is in CENTS, matching every money value in a read response and
     * the stored column. So {"amount": 2500} refunds $25.00. roundCent() below only
     * normalizes float artifacts; it does not scale. See dev-docs/PRICING-AND-TAX.md §6.
     *
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

        $refundInfo = (array)$request->get('refund_info', []);

        // $this->validate() reports failures only by exception, and outside a
        // REST_REQUEST context the framework swallows that exception (no
        // handle_exception listener) — execution would continue and crash on
        // $refundInfo['transaction_id'] below. Fail closed: run the validator
        // directly and return the per-field 422 payload in every context.
        $validator = $this->app->validator->make($refundInfo, [
            'transaction_id' => 'required',
            'amount'         => 'required',
        ], [
            'transaction_id.required' => __('Transaction ID is required', 'fluent-cart'),
            'amount.required'         => __('Refund amount is required', 'fluent-cart'),
        ]);

        if ($validator->validate()->fails()) {
            return $this->sendError($validator->errors(), 422);
        }

        $transaction = OrderTransaction::query()->where('order_id', $orderId)->findOrFail($refundInfo['transaction_id']);
        $refundAmount = Helper::roundCent($refundInfo['amount']);

        // refund on our end
        $result = (new Refund())->processRefund($transaction, $refundAmount, $refundInfo);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        $vendorRefundId = $result['vendor_refund_id'];
        $isManuallyRefunded = Arr::get($result, 'manual_refund.status', 'no');

        $responseData = [
            'fluent_cart_refund' => [
                'status'  => 'success',
                'message' => __('Refund processed on FluentCart.', 'fluent-cart')
            ],
        ];


        if ($isManuallyRefunded === 'yes') {
            $message = __('Refund processed manually.', 'fluent-cart');
            $source  = Arr::get($result, 'manual_refund.source');
            if ($source) {
                $message = sprintf(
                    __('Refund processed manually. source: %s', 'fluent-cart'),
                    $source
                );
            }
            $responseData['gateway_refund'] = [
                'status'  => 'success',
                'message' => $message
            ];
        } else {
            $responseData['gateway_refund'] = [
                'status'  => is_wp_error($vendorRefundId) ? 'failed' : 'success',
                'message' => !is_wp_error($vendorRefundId)
                    ? sprintf(__('Refund processed on %s', 'fluent-cart'), ucfirst($transaction->payment_method))
                    : sprintf(__('ERROR processing refund on %s: %s', 'fluent-cart'), ucfirst($transaction->payment_method), $vendorRefundId->get_error_message())
            ];

            if (is_wp_error($vendorRefundId)) {
                fluent_cart_warning_log('Refund failed on ' . ucfirst($transaction->payment_method), $vendorRefundId->get_error_message(), [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                    'log_type'    => 'api'
                ]);
            }
        }

        $cancelSubscription = Arr::get($refundInfo, 'cancelSubscription') == 'true';

        if ($cancelSubscription && $transaction->subscription_id && $transaction->subscription) {
            $vendorSubscriptionCancelled = $transaction->subscription->cancelRemoteSubscription([
                'reason' => 'refunded',
                'effective_from' => 'immediately'
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
            $childOrderIds = Order::query()->where('parent_id', $order->id)->pluck('id')->toArray();
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


        $DB = \FluentCart\App\App::db();
        $connectedOrderIds = [$order->id];
        $isTestMode = $order->mode === Status::ORDER_MODE_TEST;

        if ($order->type === 'subscription') {
            $childOrderIds = Order::query()->where('parent_id', $order->id)->pluck('id')->toArray();
            $connectedOrderIds = array_merge($childOrderIds, $connectedOrderIds);
        }

        try {
            $DB->beginTransaction();

            if ($order->type === 'subscription') {
                $subscriptionIds = Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->pluck('id')->toArray();
                if ($subscriptionIds) {
                    SubscriptionMeta::query()->whereIn('subscription_id', $subscriptionIds)->delete();
                }

                Subscription::query()->whereIn('parent_order_id', $connectedOrderIds)->delete();
            }

            // Dispatch inside transaction so stock restore is atomic with deletion.
            // Must run before deleteOrderRelatedData() which removes stock_movement meta and order items.
            (new OrderDeleting($order, $connectedOrderIds, $isTestMode, $order->type))->dispatch();

            // Pre-load relations before cleanup so the delete events have address data
            $order->load(['customer', 'shipping_address', 'billing_address']);

            $this->deleteOrderRelatedData($connectedOrderIds, $isTestMode);
            $DB->commit();
        } catch (\Exception $e) {
            $DB->rollBack();
            return $this->sendError([
                'message' => __('Failed to delete order', 'fluent-cart'),
            ], 400);
        }

        if ($order->type === 'renewal') {
            (new RenewalOrderDeleted($order))->dispatch();
        } else {
            (new OrderDeleted($order, $connectedOrderIds))->dispatch();
        }

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

    /**
     * Delete order related data (transactions, items, meta, addresses, orders)
     */
    private function deleteOrderRelatedData(array $orderIds, bool $isTestMode = false): void
    {
        OrderTransaction::query()->whereIn('order_id', $orderIds)->delete();
        OrderAddress::query()->whereIn('order_id', $orderIds)->delete();
        OrderItem::query()->whereIn('order_id', $orderIds)->delete();
        OrderMeta::query()->whereIn('order_id', $orderIds)->delete();
        OrderTaxRate::query()->whereIn('order_id', $orderIds)->delete();
        OrderOperation::query()->whereIn('order_id', $orderIds)->delete();
        AppliedCoupon::query()->whereIn('order_id', $orderIds)->delete();
        Cart::query()->whereIn('order_id', $orderIds)->delete();
        OrderDownloadPermission::query()->whereIn('order_id', $orderIds)->delete();
        LabelRelationship::query()->where('labelable_type', Order::class)
            ->whereIn('labelable_id', $orderIds)->delete();

        if ($isTestMode) {
            Activity::query()->where('module_type', Order::class)
                ->whereIn('module_id', $orderIds)->delete();
        }

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
        $taxNumber = Arr::get($data, 'order.customer_tax_number', '');
        if (!empty($taxNumber)) {
            $data['tax_id'] = $taxNumber;
        }
        unset($data['order']['customer_tax_number']);

        $data['can_send_payment_reminder'] = (new ReminderService())->canSendPaymentReminder($data['order']);

        return $data;
    }

    public function getTransactionDetails($orderId, $transactionId)
    {
        $orderId = (int)$orderId;
        $transactionId = (int)$transactionId;

        $belongsToOrder = OrderTransaction::query()
            ->where('id', $transactionId)
            ->where('order_id', $orderId)
            ->exists();

        if (!$belongsToOrder) {
            return $this->entityNotFoundError(
                __('Transaction not found', 'fluent-cart'),
                __('Back to orders', 'fluent-cart'),
                '/orders'
            );
        }

        $data = $this->getDetails($orderId);

        if (!is_array($data) || empty($data['order'])) {
            return $data;
        }

        // The path names one transaction, so the sibling rows on the same order
        // are not part of this response.
        $data['order']['transactions'] = array_values(array_filter(
            (array)Arr::get($data, 'order.transactions', []),
            function ($transaction) use ($transactionId) {
                return (int)Arr::get($transaction, 'id') === $transactionId;
            }
        ));

        return $data;
    }

    public function createCustom(Request $request, OrderItemHelper $orderItemHelper, Order $order)
    {
        try {
            $orderItem = $orderItemHelper->processCustom(
                $request->product,
                $order->id
            );

            return $this->sendSuccess([
                'message'    => __('Custom item has been added to the order!', 'fluent-cart'),
                'order_item' => $orderItem
            ]);

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
        $db = Order::query()->getConnection();
        $db->beginTransaction();

        try {
            $locked = Order::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('Order not found', 'fluent-cart')
                ], 404);
            }

            $dueAmount = intval($locked->total_amount - $locked->total_paid);

            if ($dueAmount <= 0) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('Order has already been paid', 'fluent-cart')
                ], 423);
            }

            if ($locked->status === Status::ORDER_CANCELED) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('Unable to mark paid for canceled order', 'fluent-cart')
                ], 423);
            }

            // Reuse an existing pending transaction without vendor_charge_id instead of
            // creating a new one. Queried fresh (not via the route-bound relation) so it
            // reflects the state under the lock.
            $transaction = OrderTransaction::query()
                ->where('order_id', $locked->id)
                ->where('status', Status::TRANSACTION_PENDING)
                ->where(function ($query) {
                    $query->whereNull('vendor_charge_id')
                        ->orWhere('vendor_charge_id', '');
                })
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            $newTransactionData = [
                'total'               => $dueAmount,
                'status'              => Status::TRANSACTION_SUCCEEDED,
                'payment_method'      => sanitize_text_field($request->payment_method),
                'vendor_charge_id'    => sanitize_text_field($request->vendor_charge_id),
                'payment_mode'        => sanitize_text_field($locked->mode),
                'payment_method_type' => sanitize_text_field($request->payment_method),
                'order_type'          => sanitize_text_field($locked->type),
                'currency'            => sanitize_text_field($locked->currency),
            ];

            if ($transaction) {
                // Don't include transaction_type in the update — the existing value is always 'charge'
                // and overwriting it with the request value would break syncSubscriptionStates bill_count.
                $transaction->update($newTransactionData);
            } else {
                $transaction = OrderTransaction::query()->create(
                    array_merge($newTransactionData, [
                        'order_id'         => $locked->id,
                        'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                    ])
                );
            }

            // Persist the settled balance while the row lock is held so the next request
            // to acquire it computes due = 0. payment_status is deliberately left alone:
            // syncOrderStatuses() owns the atomic pending → paid claim that dispatches
            // OrderPaid exactly once, and it runs after commit so third-party hook
            // callbacks (emails, integrations, subscription activation) never execute
            // while the order row is locked.
            $locked->total_paid = (int) OrderTransaction::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', Status::getTransactionSuccessStatuses())
                ->sum('total');

            $note = sanitize_text_field($request->get('mark_paid_note', ''));
            if ($note) {
                $locked->note = $note;
            }
            $locked->save();

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        (new StatusHelper($locked))->syncOrderStatuses($transaction);

        return $this->response->sendSuccess([
            'message' => __('Order has been marked as paid', 'fluent-cart')
        ]);
    }

    public function handleBulkActions(Request $request)
    {

        $action = sanitize_text_field($request->get('action', ''));
        $orderIds = $request->get('order_ids', []);

        if ($action == 'delete_test_orders') {
            return $this->handleDeleteTestOrdersBulkAction($request);
        }

        $orderIds = array_map(function ($id) {
            return (int)$id;
        }, $orderIds);

        $orderIds = array_filter($orderIds);

        if (!$orderIds) {
            return $this->sendError([
                'message' => __('Orders selection is required', 'fluent-cart')
            ]);
        }

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

        // The capture_payments branch was removed: it called
        // $order->capturePayments(), a method that has never existed anywhere
        // in the codebase, so the action fataled on the first order (audit
        // item #43). No UI sends it — the orders bulk bar submits
        // delete_test_orders only. Bulk payment capture, if wanted, is a
        // gateway feature to design (authorize/capture per gateway), not a
        // branch to resurrect as-is.

        return $this->sendError([
            'message' => __('Selected action is invalid', 'fluent-cart')
        ]);

    }

    protected function handleDeleteTestOrdersBulkAction(Request $request)
    {
        $batchSize = max(1, (int)apply_filters('fluent_cart/order/delete_test_orders_batch_size', 50));
        $lastOrderId = max(0, (int)$request->get('last_order_id', 0));
        $totalCount = (int)$this->getTestOrdersQuery()->count();

        $testOrders = $this->getTestOrdersQuery()
            ->select('id')
            ->orderBy('id')
            ->when($lastOrderId > 0, function ($query) use ($lastOrderId) {
                return $query->where('id', '>', $lastOrderId);
            })
            ->limit($batchSize)
            ->get();

        $batchOrderIds = $testOrders->pluck('id')->map(function ($id) {
            return (int)$id;
        })->toArray();

        if (!$batchOrderIds) {
            return $this->sendSuccess([
                'message' => $lastOrderId
                    ? __('Test order deletion completed.', 'fluent-cart')
                    : __('No test orders found to delete', 'fluent-cart'),
                'total_count' => $totalCount,
                'batch_size' => $batchSize,
                'batch_count' => 0,
                'deleted_count' => 0,
                'failed_count' => 0,
                'deleted_order_ids' => [],
                'failed_order_ids' => [],
                'last_attempted_order_id' => $lastOrderId,
                'has_more' => false
            ]);
        }

        $isDeleted = OrderResource::bulkDeleteByOrderIds($batchOrderIds);

        if (is_wp_error($isDeleted)) {
            $deletedOrderIds = [];
            $failedOrderIds = $batchOrderIds;
        } else {
            $deletedOrderIds = array_values(array_unique(array_map('intval', Arr::get($isDeleted, 'data.deleted_order_ids', []))));
            $failedOrderIds = array_values(array_unique(array_map('intval', Arr::get($isDeleted, 'data.failed_order_ids', []))));
        }

        $deletedCount = count($deletedOrderIds);
        $failedCount = count($failedOrderIds);
        $lastAttemptedOrderId = (int)end($batchOrderIds);
        $hasMore = $this->getTestOrdersQuery()
            ->where('id', '>', $lastAttemptedOrderId)
            ->exists();

        return $this->sendSuccess([
            'message'       => $hasMore
                ? __('Deleting test orders...', 'fluent-cart')
                : __('Test order deletion completed.', 'fluent-cart'),
            'total_count' => $totalCount,
            'batch_size' => $batchSize,
            'batch_count' => count($batchOrderIds),
            'deleted_count' => $deletedCount,
            'failed_count'  => $failedCount,
            'deleted_order_ids' => $deletedOrderIds,
            'failed_order_ids'  => $failedOrderIds,
            'last_attempted_order_id' => $lastAttemptedOrderId,
            'has_more' => $hasMore
        ]);
    }

    public function updateTransactionStatus(Request $request, $order, OrderTransaction $transaction)
    {

        $order = Order::query()->find($order);
        $newStatus = sanitize_text_field($request->get('status', ''));

        $validStatuses = Status::getEditableTransactionStatuses();
        if (!isset($validStatuses[$newStatus])) {
            return $this->sendError([
                'message' => __('Provided transaction status is not valid', 'fluent-cart')
            ]);
        }

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

        // Money already counted into the order cannot be changed from a dropdown; returning
        // it is a refund, which records a refund transaction through the refund action (FC-SEC-09).
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return $this->sendError([
                'message' => __('A succeeded transaction cannot be changed here. Use the refund action to return the payment.', 'fluent-cart')
            ], 422);
        }

        $transaction->updateStatus($newStatus);

        if ($newStatus === Status::TRANSACTION_SUCCEEDED) {
            // 'succeeded' is a transaction word, not an order payment status: derive the
            // order's paid state and total_paid from its transactions, as mark-as-paid does.
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        } else {
            $order->updatePaymentStatus($newStatus);
        }

        return [
            'transaction' => $transaction,
            'message'     => __('Payment status has been successfully updated', 'fluent-cart')
        ];
    }

    public function syncPendingTransaction(Request $request, $order, OrderTransaction $transaction)
    {
        $order = Order::query()->find($order);

        if (!$order || $transaction->order_id != $order->id) {
            return $this->sendError([
                'message' => __('The selected transaction does not match with the provided order', 'fluent-cart')
            ]);
        }

        $result = $transaction->syncPendingTransaction();

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'     => __('Transaction has been synced from the payment gateway successfully!', 'fluent-cart'),
            'transaction' => $result
        ]);
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

        $applicableMethods = ShippingMethod::getApplicableForCountry($countryCode, $state);

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

    /**
     * Calculate tax for an admin order given an address and item list.
     * No DB writes — pure calculation helper.
     *
     * Accepts: { country, state, city, postcode, items: [{post_id, object_id, subtotal, discount_total}] }
     * Returns: { tax_total, shipping_tax, tax_lines, tax_behavior, tax_country }
     */
    public function calculateTax(Request $request)
    {
        $address = [
            'country'  => sanitize_text_field($request->get('country', '')),
            'state'    => sanitize_text_field($request->get('state', '')),
            'city'     => sanitize_text_field($request->get('city', '')),
            'postcode' => sanitize_text_field($request->get('postcode', '')),
        ];

        $rawItems = $request->get('items', []);
        if (!is_array($rawItems)) {
            $rawItems = [];
        } elseif (count($rawItems) > 100) {
            $rawItems = array_slice($rawItems, 0, 100);
        }

        $items = [];
        foreach ($rawItems as $item) {
            $items[] = [
                'post_id'         => (int) Arr::get($item, 'post_id', 0),
                'object_id'       => (int) Arr::get($item, 'object_id', 0),
                'subtotal'        => (int) Arr::get($item, 'subtotal', 0),
                'discount_total'  => (int) Arr::get($item, 'discount_total', 0),
                'shipping_charge' => (int) Arr::get($item, 'shipping_charge', 0),
                'quantity'        => max(1, (int) Arr::get($item, 'quantity', 1)),
            ];
        }

        $result = \FluentCart\App\Services\Tax\AdminOrderTaxService::calculate($items, $address);

        if ($result === null) {
            return $this->sendSuccess([
                'tax_total'    => 0,
                'shipping_tax' => 0,
                'tax_lines'    => [],
                'tax_behavior' => 0,
                'tax_country'  => '',
            ]);
        }

        $taxLines = Arr::get($result, 'tax_lines', []);
        $strippedTaxLines = array_values(array_map(function ($line) {
            return [
                'label'        => Arr::get($line, 'label', ''),
                'rate_percent' => Arr::get($line, 'rate_percent', 0),
                'tax_amount'   => (int) Arr::get($line, 'tax_amount', 0),
                'inclusive'    => (bool) Arr::get($line, 'inclusive', false),
            ];
        }, $taxLines));

        return $this->sendSuccess([
            'tax_total'    => (int) Arr::get($result, 'tax_total', 0),
            'shipping_tax' => (int) Arr::get($result, 'shipping_tax', 0),
            'tax_behavior' => (int) Arr::get($result, 'tax_behavior', 0),
            'tax_country'  => Arr::get($result, 'tax_country', ''),
            'tax_lines'    => $strippedTaxLines,
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
            'message' => __('Dispute accepted!', 'fluent-cart')
        ]);
    }

    public function syncOrderStatuses(Request $request, Order $order)
    {
        $latestTransaction = OrderTransaction::query()
            ->where('order_id', $order->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$latestTransaction) {
            return $this->sendError([
                'message' => __('No transaction found for this order', 'fluent-cart')
            ], 404);
        }

        (new StatusHelper($order))->syncOrderStatuses($latestTransaction);

        // Reload order to get updated data
        $order = Order::query()->find($order->id);

        return $this->sendSuccess([
            'message'  => __('Order statuses synced successfully', 'fluent-cart'),
            'order'    => $order,
            'payment_status' => $order->payment_status,
            'status'   => $order->status,
        ]);
    }

}
