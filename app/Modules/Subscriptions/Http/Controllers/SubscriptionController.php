<?php

namespace FluentCart\App\Modules\Subscriptions\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
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
}
