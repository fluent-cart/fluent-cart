<?php

namespace FluentCart\App\Listeners\Order;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\AuthService;
use FluentCart\Framework\Support\Arr;

class OrderPaid
{
    public static function handle(\FluentCart\App\Events\Order\OrderPaid $event)
    {
        if ($event->order) {
            $event->order->recountTotalPaid();
        }

        if ($event->order->customer) {
            $event->order->customer->recountStat();
            self::maybeCreateUser($event->order);
        }
    }

    public static function maybeCreateUser(Order $order)
    {
        $customer = $order->customer;
        if (!$customer || $customer->getWpUserId(true)) {
            return; // we already have a user or no customer
        }

        $willCreate = Arr::get($order->config, 'create_account_after_paid') === 'yes' || $order->type === 'subscription' || (new StoreSettings())->get('user_account_creation_mode') === 'all';
        if (!$willCreate) {
            return;
        }

        $createdUserId = \FluentCart\App\Services\AuthService::createUserFromCustomer($customer, true);
        if (is_wp_error($createdUserId)) {
            $order->addLog(__('User creation failed: ', 'fluent-cart') . $createdUserId->get_error_message(), 'error');
            return;
        }

        $customer->user_id = $createdUserId;
        $customer->save();

        $order->addLog(__('User created successfully', 'fluent-cart'), __('User account has been created automatically on payment success. Created User ID: ', 'fluent-cart') . $createdUserId, 'info');
        $action = App::request()->get('fluent-cart') ?? '';
        if (!get_current_user_id() && empty($action)) {
            // this is a browser request. So we can make the user logged in automatically
            $user = get_user_by('ID', $createdUserId);
            if ($user) {
                AuthService::makeLogin($user);
            }
        }
    }

}
