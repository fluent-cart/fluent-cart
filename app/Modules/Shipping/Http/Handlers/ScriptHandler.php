<?php

namespace FluentCart\App\Modules\Shipping\Http\Handlers;

use FluentCart\App\Vite;

class ScriptHandler
{
    public function register()
    {
        add_action('fluent_cart/loading_app', function () {
            Vite::enqueueScript('fluent_cart_shipping', 'admin/Modules/Shipping/shipping.js');
        });
    }
}
