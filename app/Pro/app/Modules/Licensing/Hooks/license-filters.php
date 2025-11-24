<?php

defined('ABSPATH') || exit;

use FluentCart\Framework\Support\Arr;

add_filter('fluent_cart/license/customer_licenses', function ($customerId, $data = []) {
    $params = Arr::get($data, 'params', []);
    return \FluentCartPro\App\Modules\Licensing\Models\License::query()
        ->where('customer_id', $customerId)
        ->paginate(Arr::get($params, 'per_page', 20), ['*'], 'page', Arr::get($params, 'page', 1));
}, 10, 2);

add_filter('fluent_cart/customer/view', function ($customer, $args) {
    $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
        ->with([
            'product'        => function ($q) {
                return $q->select('ID', 'post_title');
            },
            'productVariant' => function ($q) {
                return $q->select('id', 'variation_title');
            }
        ])
        ->where('customer_id', $customer->id)
        ->get();

    if (!$licenses->isEmpty()) {
        $customer->licenses = $licenses;
    }

    return $customer;
}, 10, 2);

add_filter('fluent_cart/subscription/view', function ($subcription, $args) {
    $orderIds = [];

    foreach ($subcription->related_orders as $order) {
        $orderIds[] = $order->id;
    }

    if ($orderIds) {
        $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
            ->with(['productVariant' => function ($q) {
                return $q->select('id', 'variation_title');
            }, 'product'             => function ($q) {
                return $q->select('ID', 'post_title');
            }])
            ->whereIn('order_id', $orderIds)
            ->get();

        if (!$licenses->isEmpty()) {
            $subcription->licenses = $licenses;
        }
    }

    return $subcription;

}, 10, 2);

add_filter('fluent_cart/order/view', function ($order, $args) {

    $upgradedFrom = null;

    $config = Arr::get($order, 'config');

    if (!empty($config)) {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (!is_array($config)) {
            $config = [];
        }

        $upgradedFrom = Arr::get($config, 'upgraded_from', $upgradedFrom);
    }


    $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
        ->with([
            'productVariant' => function ($q) {
                return $q->select('id', 'variation_title');
            },
            'product'        => function ($q) {
                return $q->select('ID', 'post_title');
            },
        ])
        ->whereIn('order_id', array_filter([
            $order['id'],
            $order['parent_id'] ?? null,
            $upgradedFrom,
        ]))
        ->get();

    if (!$licenses->isEmpty()) {
        $order['licenses'] = $licenses;
    }

    return $order;

}, 10, 2);

add_filter('fluent_cart/customer_portal/subscription_data', function ($subscriptionData, $args) {

    $subscription = Arr::get($args, 'subscription', null);

    if (!$subscription) {
        return $subscriptionData;
    }

    $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
        ->where('subscription_id', $subscription->id)
        ->where('customer_id', $subscription->customer_id)
        ->get();

    if ($licenses->isEmpty()) {
        return $subscriptionData;
    }

    $subscriptionData['licenses'] = $licenses->map(function ($license) {
        return \FluentCartPro\App\Modules\Licensing\Services\LicenseHelper::formatLicense($license);
    });

    return $subscriptionData;

}, 10, 2);

add_filter('fluent_cart/customer/order_data', function ($orderData, $args) {
    $order = Arr::get($args, 'order', null);
    if (!$order) {
        return $orderData;
    }

    $orderIds = [$order->id];
    if (!empty($order->parent_id)) {
        $orderIds[] = $order->parent_id;
    }

    $licenses = \FluentCartPro\App\Modules\Licensing\Models\License::query()
        ->whereIn('order_id', $orderIds)
        ->get();

    if ($licenses->isEmpty()) {
        return $orderData;
    }

    $orderData['licenses'] = $licenses->map(function ($license) {
        return \FluentCartPro\App\Modules\Licensing\Services\LicenseHelper::formatLicense($license);
    });

    return $orderData;
}, 10, 2);
