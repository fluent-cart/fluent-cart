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
    }
}

