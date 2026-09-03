<?php

namespace FluentCart\App\Modules\Subscriptions;

use FluentCart\App\App;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;
use FluentCart\Framework\Support\Arr;

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

        // Checkout gateway capability gate — new subscription carts, renewals, and reactivations.
        (new \FluentCart\App\Modules\Subscriptions\Services\SubscriptionGatewayGate())->register();

        // Auto-charge engine for system (token-charged) subscription renewal invoices.
        (new \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService())->register();

        add_filter('fluent_cart/admin_filter_options', function ($filterOptions, $args) {
            $filterOptions['subscription_filter_options'] = SubscriptionFilter::getTableFilterOptions();
            return $filterOptions;
        }, 10, 2);  
    }
}
