<?php

namespace FluentCart\App\Modules\IntegrationActions;

class GlobalIntegrationActionHandler
{
    public function register()
    {
        add_action('fluentcart_loaded', [$this, 'init']);
    }

    public function init()
    {
        add_action('init', function () {
            do_action('fluent_cart/register_integration_action');
        });

    }

    public static function getAll()
    {
        return apply_filters('fluent_cart/integration/get_global_integration_actions', []);
    }
}