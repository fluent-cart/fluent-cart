<?php

namespace FluentCart\App\Modules\Subscriptions;

use FluentCart\App\App;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;

class SubscriptionModule
{
    public static function register()
    {
        $self = new static();
        App::getInstance()->addAction('fluentcart_loaded', [$self, 'init']);
    }

    public function init($app)
    {
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/subscriptions-api.php';
        });

        (new \FluentCart\App\Modules\Subscriptions\Http\Handlers\AdminMenuHandler())->register();

        add_filter('fluent_cart/admin_filter_options', function ($filterOptions, $args) {
            $filterOptions['subscription_filter_options'] = SubscriptionFilter::getTableFilterOptions();
            return $filterOptions;
        }, 10, 2);

    }

}
