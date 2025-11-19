<?php

namespace FluentCart\App\Modules\Subscriptions\Http\Handlers;

use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class AdminMenuHandler
{
    public function register()
    {
        add_action('fluent_cart/loading_app', function () {
            Vite::enqueueScript('fluent_cart_subscriptions', 'admin/Modules/Subscriptions/subscription.js');
        });

        add_filter('fluent_cart/global_admin_menu_items', [$this, 'addSubscriptionAdminMenu'], 10, 2);
    }

    public function addSubscriptionAdminMenu ($items, $args)
    {
        $baseUrl = Arr::get($args, 'base_url');

        $items['subscriptions'] = [
            'label' => __('Subscriptions', 'fluent-cart'),
            'link'  => $baseUrl . 'subscriptions',
            'permission' => ['subscriptions/view']
        ];

        return $items;
    }

}

