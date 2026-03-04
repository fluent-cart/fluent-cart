<?php

namespace FluentCart\App\Modules\Subscriptions\Http\Controllers;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\EarlyPaymentFeature;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;

class SubscriptionController extends Controller
{
    public function index(Request $request): array
    {


        return [
            'data' => SubscriptionFilter::fromRequest($request)->paginate(),
        ];
    }

    public function getSubscriptionOrderDetails($subscriptionOrderId)
    {

        $subscription = Subscription::with([
            'labels',
            'customer.shipping_address' => function ($query) {
                $query->where('is_primary', 1);
            },
            'customer.billing_address'  => function ($query) {
                $query->where('is_primary', 1);
            },
        ])
            ->find($subscriptionOrderId);

        if (is_wp_error($subscription) || empty($subscription)) {
            return $this->entityNotFoundError(
                __('Subscription not found', 'fluent-cart'),
                __('Back to Subscription list', 'fluent-cart'),
                '/subscriptions'
            );
        }


        $subscription->related_orders = Order::query()
            ->with(['order_items' => function ($query) {
                $query->select('id', 'order_id', 'post_title', 'title', 'quantity', 'payment_type', 'line_meta');
            }])
            ->where('id', $subscription->parent_order_id)
            ->orWhere('parent_id', $subscription->parent_order_id)
            ->orderBy('id', 'DESC')
            ->get();


        $subscription = apply_filters('fluent_cart/subscription/view', $subscription, []);

        return $this->sendSuccess([
            'subscription'    => $subscription,
            'selected_labels' => $subscription->labels->pluck('label_id'),
        ]);

    }

    public function validateSubscription($subscription)
    {
        if (!$subscription) {
            $this->sendError(['message' => __('Subscription not found!', 'fluent-cart')], 404);
        }
    }

    public function cancelSubscription(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);

        if (empty($request->getSafe('cancel_reason', 'sanitize_text_field'))) {
            return $this->sendError([
                'message' => __('Please select cancel reason!', 'fluent-cart')
            ]);
        }

        $result = $subscription->cancelRemoteSubscription([
            'reason' => $request->getSafe('cancel_reason', 'sanitize_text_field')
        ]);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        $vendorCancelled = $result['vendor_result'];

        if (is_wp_error($vendorCancelled)) {
            return $this->sendError([
                'message' => 'Subscription cancelled locally. Vendor Response: ' . $vendorCancelled->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been cancelled successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function reactivateSubscription(Request $request, Order $order, Subscription $subscription)
    {
        return $this->sendError([
            'message' => __('Not available yet', 'fluent-cart')
        ]);
    }

    public function fetchSubscription(Request $request, Order $order, Subscription $subscription)
    {
        $result = $subscription->reSyncFromRemote();

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription fetched successfully from remote payment gateway!', 'fluent-cart'),
            'subscription' => $result
        ]);
    }

    public function pauseSubscription(Request $request, Order $order, Subscription $subscription)
    {
        return $this->sendError([
            'message' => __('Not available yet', 'fluent-cart')
        ]);

    }

    public function resumeSubscription(Request $request, Order $order, Subscription $subscription)
    {
        return $this->sendError([
            'message' => __('Not available yet', 'fluent-cart')
        ]);
    }

    public function generateEarlyPaymentLink(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);

        if (!EarlyPaymentFeature::isEnabled()) {
            return $this->sendError([
                'message' => __('Early payment is not enabled for this site.', 'fluent-cart')
            ]);
        }

        $subscriptionOrderId = null;
        if (property_exists($subscription, 'parent_order_id') && $subscription->parent_order_id) {
            $subscriptionOrderId = (int) $subscription->parent_order_id;
        }
        
        if ($subscriptionOrderId === null || $subscriptionOrderId !== (int) $order->id) {
            return $this->sendError([
                'message' => __('Invalid subscription for the specified order.', 'fluent-cart')
            ]);
        }

        if ($subscription->bill_times <= 0) {
            return $this->sendError([
                'message' => __('Early payment is only available for installment subscriptions.', 'fluent-cart')
            ]);
        }

        if (!in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            return $this->sendError([
                'message' => __('Subscription must be active to make early payments.', 'fluent-cart')
            ]);
        }

        $remaining = $subscription->bill_times - $subscription->bill_count;

        if ($remaining <= 0) {
            return $this->sendError([
                'message' => __('All installments have already been paid.', 'fluent-cart')
            ]);
        }

        $url = add_query_arg([
            'fluent-cart'       => 'early-installment-payment',
            'subscription_hash' => $subscription->uuid,
        ], home_url('/'));

        return $this->sendSuccess([
            'message'     => __('Early payment link generated.', 'fluent-cart'),
            'payment_url' => $url,
        ]);
    }
}
