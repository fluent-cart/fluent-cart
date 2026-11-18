<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request;

class WidgetsController extends Controller
{
    public function __invoke(Request $request): \WP_REST_Response
    {
        $filter = $request->get('filter') ?? '';
        $filter = str_replace('fluent_cart_', '', $filter);
        $data = $request->get('data', []);

        // These slots also need the permission of the page they sit on.
        $pagePermissions = [
            'single_subscription_page'      => 'subscriptions/view',
            'single_subscription_page_main' => 'subscriptions/view',
            'single_order_page_main'        => 'orders/view',
            'customer_page_main'            => 'customers/view',
        ];

        if (!PermissionManager::hasAnyPermission(['customers/view', 'orders/view']) ||
            (isset($pagePermissions[$filter]) && !PermissionManager::hasPermission($pagePermissions[$filter]))) {
            return $this->sendError([
                'message' => __('You do not have permission to access this resource', 'fluent-cart')
            ]);
        }

        if (in_array($filter, ['single_order_page', 'single_order_page_main'], true)) {
            $order = Order::find((int)($data['order_id'] ?? 0));
            if (!$order) {
                return $this->sendError([
                    'message' => __('Order not found', 'fluent-cart')
                ]);
            }

            $data['order'] = $order;
        }

        if ($filter === 'customer_page_main') {
            $customer = Customer::find((int)($data['customer_id'] ?? 0));
            if (!$customer) {
                return $this->sendError([
                    'message' => __('Customer not found', 'fluent-cart')
                ]);
            }

            $data['customer'] = $customer;
        }

        if (in_array($filter, ['single_subscription_page', 'single_subscription_page_main'], true)) {
            $subscription = Subscription::find((int)($data['subscription_id'] ?? 0));
            if (!$subscription) {
                return $this->sendError([
                    'message' => __('Subscription not found', 'fluent-cart')
                ]);
            }

            $data['subscription'] = $subscription;
        }

        return $this->sendSuccess([
            'widgets' => apply_filters('fluent_cart/widgets/' . $filter, [], $data)
        ]);
    }
}
