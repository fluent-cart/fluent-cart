<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

//use FluentCart\Api\Resource\FrontendResource\CustomerResource;
use FluentCart\Api\Resource\CustomerAddressResource;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\OrderDownloadPermissionResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests\CustomerProfileAccountDetailsRequest;
use FluentCart\App\Http\Requests\FrontendRequests\CustomerRequests\CustomerProfileRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\FileSystem\FileManager;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Relations\HasMany;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Validator\Validator;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;


class CustomerOrderController extends BaseFrontendController
{

    public function getOrders(Request $request): \WP_REST_Response
    {

        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendSuccess([
                'orders' => [
                    'data'         => [],
                    'total'        => 0,
                    'per_page'     => 10,
                    'current_page' => 1,
                    'last_page'    => 1
                ]
            ]);
        }

        $perPage = (int)$request->get('per_page', 10);
        $page = (int)$request->get('page', 1);
        $search = $request->getSafe('search', 'sanitize_text_field');

        $orders = Order::query()
            ->select(['invoice_no', 'id', 'parent_id', 'total_amount', 'uuid', 'type', 'status', 'created_at'])
            ->with(['order_items' => function ($query) {
                $query->select('order_id', 'post_title', 'title', 'quantity', 'payment_type');
            }])
            ->where('customer_id', $customer->id)
            ->where(function ($query) {
                $query
                    ->where(function ($query) {
                        $query->where('parent_id', '')->orWhereNull('parent_id');
                    })
                    ->orWhere('type', '!=', 'renewal');
            })
            ->searchBy($search)
            ->withCount('renewals')
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        $orders->transform(function ($order) {
            return [
                'created_at'     => $order->created_at->format('Y-m-d H:i:s'),
                'invoice_no'     => $order->invoice_no,
                'total_amount'   => $order->total_amount,
                'uuid'           => $order->uuid,
                'type'           => $order->type,
                'status'         => $order->status,
                'renewals_count' => $order->renewals_count,
                'order_items'    => $order->order_items->map(function ($item) {
                    return [
                        'post_title'   => $item->post_title,
                        'title'        => $item->title,
                        'quantity'     => $item->quantity,
                        'payment_type' => $item->payment_type,
                    ];
                }),
            ];
        });

        return $this->sendSuccess([
            'orders' => $orders
        ]);
    }

    public function orderDetails($order_uuid): \WP_REST_Response
    {
        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();

        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            // Return error if user is not logged in
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $order = Order::query()
            ->where('uuid', $order_uuid)
            ->where('customer_id', $customer->id)
            ->with(['customer', 'transactions', 'shipping_address', 'billing_address'])
            ->with(['order_items' => function ($query) {
                $query->with([
                    'variantImages',
                    'product' => function ($productQuery) {
                        $productQuery->addAppends(['view_url']);
                    }
                ]);
            }])
            ->first();

        if (!$order) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart')
            ]);
        }

        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            // We will redirect to the parent order details
            $parentOrder = Order::query()
                ->where('id', $order->parent_id)
                ->first();

            if ($parentOrder && $parentOrder->type == Status::ORDER_TYPE_SUBSCRIPTION) {
                return $this->sendError([
                    'message'      => __('This is a renewal order. Please check the parent order details.', 'fluent-cart'),
                    'parent_order' => [
                        'uuid' => $parentOrder->uuid,
                    ]
                ]);
            }
        }

        $orderItems = [];
        $variationIds = [];
        $productIds = [];
        foreach ($order->order_items as $item) {
            if ($item->payment_type === 'signup_fee') {
                continue; // Skip signup fee items
            }

            $metaLines = [];
            $extraAmount = 0;

            if ($item->payment_type == 'subscription' && $signupFee = Arr::get($item->other_info, 'signup_fee')) {
                $metaLines[] = [
                    'label' => Arr::get($item->other_info, 'signup_fee_name', __('Signup Fee', 'fluent-cart')),
                    'value' => Helper::toDecimal($signupFee, true, $order->currency)
                ];
                $extraAmount = (int)$signupFee;
            }

            $orderItems[] = [
                'variation_id'  => $item->object_id,
                'product_id'    => $item->post_id,
                'post_title'    => $item->post_title,
                'title'         => $item->title,
                'quantity'      => $item->quantity,
                'unit_price'    => $item->unit_price,
                'subtotal'      => $item->subtotal,
                'payment_type'  => $item->payment_type,
                'meta_lines'    => $metaLines,
                'extra_amount'  => $extraAmount,
                'image'         => Arr::get($item, 'productImage.meta_value.0.url', ''),
                'variant_image' => Arr::get($item, 'variantImages.meta_value.0.url', ''),
                'url'           => $item->product->view_url ?? ''

            ];
            $variationIds[] = $item->object_id;
            $productIds[] = $item->post_id;
        }

        $formattedOrderData = [
            'fulfillment_type' => $order->fulfillment_type,
            'type'             => $order->type,
            'created_at'       => $order->created_at->format('Y-m-d H:i:s'),
            'invoice_no'       => $order->invoice_no,
            'currency'         => $order->currency,
            'uuid'             => $order->uuid,
            'order_items'      => $orderItems,
            'status'           => $order->status,
            'payment_status'   => $order->payment_status,
            'shipping_status'  => $order->shipping_status,

            'billing_address_text'  => $order->billing_address ? $order->billing_address->getAddressAsText(true, false) : '',
            'shipping_address_text' => $order->shipping_address ? $order->shipping_address->getAddressAsText(true, false) : '',

            'subtotal'              => $order->subtotal,
            'total_amount'          => $order->total_amount,
            'total_paid'            => $order->total_paid,
            'total_refund'          => $order->total_refund,
            'shipping_total'        => $order->shipping_total,
            'coupon_discount_total' => $order->coupon_discount_total,
            'manual_discount_total' => $order->manual_discount_total,
            'tax_total'             => $order->tax_total,
            'tax_behavior'          => $order->tax_behavior,
            'shipping_tax'          => $order->shipping_tax,
            'payment_method'        => $order->payment_method,
        ];

        $formattedOrderData['subscriptions'] = $order->subscriptions->map(function ($subscription) {
            return OrderService::transformSubscription($subscription);
        });

        $formattedOrderData['downloads'] = ProductDownload::query()->whereIn('post_id', $productIds)->get()->filter(function ($download) use ($variationIds) {
            $ids = $download->product_variation_id;
            return empty($ids) || array_intersect($variationIds, $ids);
        })->map(function ($download) use ($order) {
            return [
                'file_size'    => $download->file_size,
                'title'        => $download->title,
                'download_url' => Helper::generateDownloadFileLink(
                    $download,
                    $order->id
                ),
            ];
        })->toArray();


        // Let's find all the transactions related to this order
        $orderIds = array_filter([$order->id, $order->parent_id]);
        if ($order->type === Status::ORDER_TYPE_SUBSCRIPTION) {
            $renewalOrderIds = Order::query()
                ->where('parent_id', $order->id)
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->get()->pluck('id')->toArray();

            $orderIds = array_merge($orderIds, $renewalOrderIds);
            $orderIds = array_values(array_unique($orderIds));
        }

        $transactions = OrderTransaction::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', [
                Status::TRANSACTION_SUCCEEDED,
                Status::TRANSACTION_REFUNDED
            ])
            ->orderBy('id', 'DESC')
            ->with(['order'])
            ->get();

        $formattedOrderData['transactions'] = $transactions->map(function ($transaction) {
            return OrderService::transformTransaction($transaction);
        });

        $formattedOrderData = apply_filters('fluent_cart/customer/order_data', $formattedOrderData, [
            'order'    => $order,
            'customer' => $customer
        ]);

        $formattedOrderData['id'] = $order->id;

        $hooksContents = apply_filters('fluent_cart/customer/order_details_section_parts', [
            'before_summary'      => '',
            'after_summary'       => '',
            'after_licenses'      => '',
            'after_subscriptions' => '',
            'after_downloads'     => '',
            'after_transactions'  => '',
            'end_of_order'        => ''
        ], [
            'order'         => $order,
            'formattedData' => $formattedOrderData
        ]);

        return $this->sendSuccess([
            'order'         => $formattedOrderData,
            'section_parts' => $hooksContents
        ]);
    }

    public function downloadableProducts($order_uuid): \WP_REST_Response
    {


        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();

        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            // Return error if user is not logged in
            return $errorResponse;
        }


        $order = Order::query()
            ->where('uuid', $order_uuid)
            ->with('customer')
//            ->where(function (Builder $query) {
//                $query->where('payment_status', Status::PAYMENT_PAID)
//                    ->whereIn('status', [Status::ORDER_COMPLETED, Status::ORDER_PROCESSING]);
//            })
            ->first();


        if (empty($order) || empty($order->customer) || wp_get_current_user()->ID != $order->customer->user_id) {

            return $this->sendSuccess([
                'message' => __('Success', 'fluent-cart'),
                'data'    => []
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Success', 'fluent-cart'),
            'data'    => $order->getDownloads('customer-profile')
        ]);
    }

    /**
     * @param $order
     * @return bool|string
     */
    public function getCustomPaymentLink($order)
    {
        if (!intval($order->total_amount - $order->total_paid)) {
            return false;
        }
        return PaymentHelper::getCustomPaymentLink($order->uuid);
    }

    public function getTransactionBillingAddress(Request $request, $transaction_uuid)
    {
        // get current customer
        $customer = CustomerResource::getCurrentCustomer();

        if (empty($customer)) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $transaction = OrderTransaction::query()->where('uuid', $transaction_uuid)->first();

        if (empty($transaction)) {
            return $this->sendError([
                'message' => __('Transaction not found', 'fluent-cart')
            ]);
        }

        $order = Order::query()->where('customer_id', $customer->id)->find($transaction->order_id);

        if (empty($order)) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart')
            ]);
        }

        $vatTaxId = $order->getMeta('vat_tax_id', '');

        // get order_address by $order->id
        $billingAddress = OrderAddress::query()
            ->where('order_id', $order->id)
            ->where('type', 'billing');
        if (empty($billingAddress)) {
            $billingAddress = $billingAddress->where('order_id', $order->parent_id);
        }
        $billingAddress = $billingAddress->first();

        $formatData = [
            'address_1'  => '',
            'address_2'  => '',
            'city'       => '',
            'state'      => '',
            'postcode'   => '',
            'country'    => '',
            'name'       => '',
            'vat_tax_id' => '',
            'address_id' => ''
        ];

        if (empty($billingAddress)) {
            return $this->sendSuccess([
                'message' => '',
                'data'    => $formatData
            ]);
        }

        $formatData = [
            'address_1'  => $billingAddress->address_1,
            'address_2'  => $billingAddress->address_2,
            'city'       => $billingAddress->city,
            'state'      => $billingAddress->state,
            'postcode'   => $billingAddress->postcode,
            'country'    => $billingAddress->country,
            'name'       => $billingAddress->name,
            'vat_tax_id' => $vatTaxId,
            'address_id' => $billingAddress->id
        ];

        return $this->sendSuccess([
            'message' => __('Success', 'fluent-cart'),
            'data'    => $formatData
        ]);
    }

    public function saveTransactionBillingAddress(Request $request, $transaction_uuid)
    {
        $transactionUuid = sanitize_text_field($transaction_uuid);
        $address_id = sanitize_text_field(Arr::get($request->all(), 'address_id'));
        $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();

        $vatTaxId = sanitize_text_field(Arr::get($request->all(), 'vat_tax_id'));
        $orderId = $transaction->order_id;

        // get order by $transaction->order_id
        $customer = CustomerResource::getCurrentCustomer();

        $order = Order::query()->where('customer_id', $customer->id)->find($orderId);

        if (empty($order)) {
            return $this->sendError([
                'message' => __('Order not found', 'fluent-cart')
            ]);
        }

        $address = null;

        if ($address_id) {
            $address = OrderAddress::query()
                ->where('id', $address_id)
                ->where('order_id', $order->id)
                ->where('type', 'billing')
                ->first();
        }


        $rules = App::localization()->getValidationRule($request->all());

        // Validate input
        $validated = $request->validate($rules);
        // Sanitize
        $sanitized = $request->getSafe([
            'name'      => 'sanitize_text_field',
            'address_1' => 'sanitize_text_field',
            'address_2' => 'sanitize_text_field',
            'city'      => 'sanitize_text_field',
            'state'     => 'sanitize_text_field',
            'postcode'  => 'sanitize_text_field',
            'country'   => 'sanitize_text_field',
        ]);

        $sanitized['order_id'] = $orderId;
        $sanitized['type'] = 'billing';

        OrderMeta::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key' => 'vat_tax_id'
            ],
            [
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $vatTaxId // replace with your value variable
            ]
        );

        if (empty($address)) {
            // create new order address with $request data
            $isCreated = OrderAddress::create($sanitized);

            if (is_wp_error($isCreated)) {
                return $this->sendError([
                    'message' => $isCreated->get_error_message()
                ]);
            }

            return $this->sendSuccess([
                'message'    => __('Billing address created successfully', 'fluent-cart'),
                'address_id' => $isCreated
            ]);
        }

        // update address
        $address->fill($sanitized);

        if (!$address->save()) {
            return $this->sendError([
                'message' => __('Failed to update billing address', 'fluent-cart')
            ]);
        }


        return $this->sendSuccess([
            'message'           => __('Billing address updated successfully', 'fluent-cart'),
            'formatted_address' => $address->getAddressAsText()
        ]);
    }

}
