<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SubscriptionsManager;
use FluentCart\App\Modules\Subscriptions\Services\EarlyPaymentFeature;
use FluentCart\App\Services\OrderService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;


class CustomerSubscriptionController extends BaseFrontendController
{
    public function getSubscriptions(Request $request): \WP_REST_Response
    {
        // Call the method and store the response
        $errorResponse = $this->checkUserLoggedIn();

        // Check if there is an error response and return it if exists
        if ($errorResponse !== null) {
            // Return error if user is not logged in
            return $this->sendSuccess([
                'message'       => __('Success', 'fluent-cart'),
                'subscriptions' => [
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

        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }


        // Query the Subscription model to retrieve subscriptions for the logged-in customer
        // Include associated order and product data using 'with' for eager loading
        // Sort the results by 'id' in descending order and fetch the data
        $subscriptions = Subscription::query()
            ->where('customer_id', $customer->id)
            ->with(['product'])
            ->whereNotIn('status', [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED])
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);


        $formattedSubscriptions = $subscriptions->map(function ($subscription) {
            return OrderService::transformSubscription($subscription);
        });

        return $this->sendSuccess([
            'message'       => __('Success', 'fluent-cart'),
            'subscriptions' => [
                'data'         => $formattedSubscriptions,
                'total'        => $subscriptions->total(),
                'per_page'     => $subscriptions->perPage(),
                'current_page' => $subscriptions->currentPage(),
                'last_page'    => $subscriptions->lastPage()
            ],
        ]);
    }

    public function getSubscription($subscription_uuid): \WP_REST_Response
    {
        $errorResponse = $this->checkUserLoggedIn();

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        // Get the current logged-in customer
        $customer = CustomerResource::getCurrentCustomer();

        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('customer_id', $customer->id)
            ->with(['product', 'variation', 'billing_addresses'])
            ->where('uuid', $subscription_uuid)
            ->first();

        if (!$subscription || in_array($subscription->status, [Status::SUBSCRIPTION_PENDING, Status::SUBSCRIPTION_INTENDED])) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        $formattedData = [
            'uuid'                      => $subscription->uuid,
            'status'                    => $subscription->status,
            'overridden_status'         => $subscription->overridden_status,
            'vendor_subscription_id'    => $subscription->vendor_subscription_id,
            'next_billing_date'         => $subscription->next_billing_date,
            'billing_info'              => $subscription->billingInfo,
            'current_payment_method'    => $subscription->current_payment_method,
            'payment_method'            => $subscription->payment_method,
            'payment_info'              => $subscription->payment_info,
            'bill_times'                => $subscription->bill_times,
            'bill_count'                => $subscription->bill_count,
            'variation_id'              => $subscription->variation_id,
            'product_id'                => $subscription->product_id,
            'config'                    => $subscription->config,
            'collection_method'         => $subscription->collection_method,
            'reactivate_url'            => $subscription->getReactivateUrl(),
            // item_name resolves to "<product> - <attributes>" (or the raw name),
            // so it carries the labeled combination on a single line.
            'title'                     => $subscription->display_item_name,
            'subtitle'                  => '',
            'can_upgrade'               => $subscription->canUpgrade(),
            'can_switch_payment_method' => $subscription->canSwitchPaymentMethod(),
            'switchable_payment_methods' => $subscription->switchablePaymentMethods(),
            'can_update_payment_method' => $subscription->canUpdatePaymentMethod(),
            'can_pause'                 => $subscription->canPause(),
            'can_resume'                => $subscription->canResume(),
            'order'                     => [
                'uuid' => $subscription->order ? $subscription->order->uuid : ''
            ],
            'billing_addresses'         => $subscription->billing_addresses,
            'recurring_amount'          => $subscription->recurring_amount,
            'currency'                  => $subscription->currency,
            'can_early_pay'             => EarlyPaymentFeature::canPay($subscription),
            'remaining_installments'    => max(0, $subscription->bill_times - $subscription->bill_count),
            'card_update_url'           => $subscription->url,
            // Auto-charged (system) subscriptions: the saved card is charged
            // automatically on each renewal date — the portal says so, and surfaces
            // the last failure so the customer knows to update the card.
            'is_auto_charged'           => $subscription->isSystem(),
            'auto_charge_error'         => Arr::get((array) $subscription->system_charge_state, 'last_error', ''),
        ];


        // Let's find all the transactions related to this order
        $orderIds = array_filter([$subscription->order->id, $subscription->order->parent_id]);
        if ($subscription->order->type === Status::ORDER_TYPE_SUBSCRIPTION) {
            $renewalOrderIds = $subscription->order->renewals->pluck('id')->toArray();
            $orderIds = array_merge($orderIds, $renewalOrderIds);
            $orderIds = array_values(array_unique($orderIds));
        }

        $transactions = OrderTransaction::query()
            ->whereIn('order_id', $orderIds)
            ->with(['order'])
            ->orderBy('id', 'DESC')
            ->get();

        $formattedData['transactions'] = $transactions->map(function ($transaction) {
            $transformedTransaction = OrderService::transformTransaction($transaction);
            if ($transaction->status === Status::TRANSACTION_PENDING
                && !empty($transformedTransaction['custom_checkout_url'])
            ) {
                $transformedTransaction['show_pay_now'] = true;
            }
            return $transformedTransaction;
        });

        $formattedData = apply_filters('fluent_cart/customer_portal/subscription_data', $formattedData, [
            'subscription' => $subscription,
            'customer'     => $customer
        ]);

        $sectionParts = apply_filters('fluent_cart/customer/subscription_details_section_parts', [
            'end_of_subscription' => ''
        ], [
            'subscription'  => $subscription,
            'formattedData' => $formattedData
        ]);

        $sectionParts = array_map('wp_kses_post', $sectionParts);

        return $this->sendSuccess([
            'message'       => __('Success', 'fluent-cart'),
            'subscription'  => $formattedData,
            'section_parts' => $sectionParts
        ]);
    }

    public function updatePaymentMethod(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError(['message' => __('Customer not found', 'fluent-cart')]);
        }

        $data = $request->input('data');
        $method = $data['method'];
        $subscriptionUuid = $subscription_uuid;

        if (!$method || !$subscriptionUuid) {
            return $this->sendError([
                'message' => __('Method and Subscription Id required.', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscriptionUuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        if (App::gateway()->has($method)) {
            try {
                App::gateway($method)->subscriptions->cardUpdate($data, $subscription->id);
            } catch (\Exception $e) {
                return $this->sendError([
                    'message' => $e->getMessage()
                ]);
            }

            return $this->sendSuccess([
                'status'  => 'success',
                'message' => __('Payment method updated successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Could not update payment method', 'fluent-cart')
        ]);
    }

    public function getOrCreatePlan(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError(['message' => __('Customer not found', 'fluent-cart')]);
        }

        $data = $request->input('data');
        $method = sanitize_text_field(Arr::get($data, 'method', ''));
        $reason = sanitize_text_field(Arr::get($data, 'reason', ''));

        if (!$method || !$subscription_uuid) {
            return $this->sendError([
                'message' => __('Missing required parameters', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();
        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        if (APP::gateway()->has($method)) {
            try {
                APP::gateway($method)->subscriptions->getOrCreateNewPlan($subscription->id, $reason);
            } catch (\Exception $e) {
                return $this->sendError([
                    'message' => $e->getMessage()
                ]);
            }

            return $this->sendSuccess([
                'status'  => 'success',
                'message' => __('Plan created successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Could not get or create plan', 'fluent-cart')
        ]);
    }

    public function switchPaymentMethod(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $data = $request->input('data');
        $newPaymentMethod = sanitize_text_field(Arr::get($data, 'newPaymentMethod', ''));
        $currentPaymentMethod = sanitize_text_field(Arr::get($data, 'currentPaymentMethod', ''));

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError(['message' => __('Customer not found', 'fluent-cart')]);
        }

        if (!$newPaymentMethod || !$currentPaymentMethod || !$subscription_uuid) {
            return $this->sendError([
                'message' => __('Missing required parameters', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();
        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        if (!$subscription->canSwitchPaymentMethod()) {
            return $this->sendError([
                'message' => __('This subscription cannot be moved to another payment gateway. Update the payment method on file instead.', 'fluent-cart')
            ]);
        }

        if (App::gateway()->has($newPaymentMethod)) {
            try {
                App::gateway($newPaymentMethod)->subscriptions->switchPaymentMethod($data, $subscription->id);
            } catch (\Exception $e) {
                return $this->sendError([
                    'message' => $e->getMessage()
                ]);
            }

            return $this->sendSuccess([
                'status'  => 'success',
                'message' => __('Payment method switched successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Could not switch payment method', 'fluent-cart')
        ]);

    }

    // needed in case of two step switch payment method
    public function confirmSubscriptionSwitch(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $data = $request->input('data');
        $newVendorSubscriptionId = sanitize_text_field(Arr::get($data, 'newVendorSubscriptionId', ''));
        $method = sanitize_text_field(Arr::get($data, 'method', ''));

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError(['message' => __('Customer not found', 'fluent-cart')]);
        }

        if (!$newVendorSubscriptionId || !$method || !$subscription_uuid) {
            return $this->sendError([
                'message' => __('Missing required parameters', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();
        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        if (!$subscription->canSwitchPaymentMethod()) {
            return $this->sendError([
                'message' => __('This subscription cannot be moved to another payment gateway. Update the payment method on file instead.', 'fluent-cart')
            ]);
        }

        if (APP::gateway()->has($method)) {
            try {
                APP::gateway($method)->subscriptions->confirmSubscriptionSwitch($data, $subscription->id);
            } catch (\Exception $e) {
                return $this->sendError([
                    'message' => $e->getMessage()
                ]);
            }

            return $this->sendSuccess([
                'status'  => 'success',
                'message' => __('Subscription switch confirmed successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Could not confirm subscription switch', 'fluent-cart')
        ]);

    }

    public function cancelAutoRenew(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        $result = $subscription->cancelRemoteSubscription([
            'reason' => 'cancelled_by_customer',
            'note'   => __('Cancelled by Customer from customer portal', 'fluent-cart'),
        ]);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => __('Unable to cancel subscription at this time. Please try again later or contact support.', 'fluent-cart')
            ]);
        }

        return [
            'message' => __('Your subscription has been successfully cancelled', 'fluent-cart')
        ];

    }

    public function initiateEarlyPayment(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$subscription) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        if (!EarlyPaymentFeature::canPay($subscription)) {
            return $this->sendError([
                'message' => __('Early payment is not available for this subscription.', 'fluent-cart')
            ]);
        }

        $url = add_query_arg([
            'fluent-cart'       => 'early-installment-payment',
            'subscription_hash' => $subscription->uuid,
        ], home_url('/'));

        return $this->sendSuccess([
            'message'      => __('Early payment URL generated.', 'fluent-cart'),
            'checkout_url' => $url,
        ]);
    }

    private function canEarlyPay(Subscription $subscription): bool
    {
        return App::isProActive()
            && $subscription->bill_times > 0
            && $subscription->bill_count < $subscription->bill_times
            && in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING]);
    }

    public function pauseSubscription(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        $reason = __('Paused by customer from customer portal', 'fluent-cart');
        $result = $subscription->pauseSubscription($reason);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return [
            'message' => __('Your subscription has been successfully paused', 'fluent-cart')
        ];
    }

    public function resumeSubscription(Request $request, $subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        $reason = __('Resumed by customer from customer portal', 'fluent-cart');
        $result = $subscription->resumeSubscription($reason);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return [
            'message' => __('Your subscription has been successfully resumed', 'fluent-cart')
        ];
    }

    public function getSetupIntentRemainingAttempts($subscription_uuid)
    {
        $errorResponse = $this->checkUserLoggedIn();

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $customer = CustomerResource::getCurrentCustomer();
        if (!$customer) {
            return $this->sendError([
                'message' => __('Customer not found', 'fluent-cart')
            ]);
        }

        $subscription = Subscription::query()
            ->where('uuid', $subscription_uuid)
            ->where('customer_id', $customer->id)
            ->first();

        if (empty($subscription)) {
            return $this->sendError([
                'message' => __('Subscription not found', 'fluent-cart')
            ]);
        }

        // Only return attempts if current payment method is stripe
        if ($subscription->current_payment_method !== 'stripe' || empty($subscription->vendor_customer_id)) {
            return $this->sendError([
                'message' => __('Payment method not supported', 'fluent-cart')
            ]);
        }

        $subscriptionsManager = new SubscriptionsManager();
        $remaining = $subscriptionsManager->getRemainingRateLimit($subscription->vendor_customer_id);

        return $this->sendSuccess([
            'remaining' => max(0, $remaining)
        ]);
    }

}
