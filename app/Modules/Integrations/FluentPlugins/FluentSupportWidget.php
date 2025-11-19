<?php
namespace FluentCart\App\Modules\Integrations\FluentPlugins;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\URL;

class FluentSupportWidget
{
    public function register()
    {
        add_filter('fluent_support/customer_extra_widgets', array($this, 'getPurchaseWidgets'), 10, 2);
    }

    public function getPurchaseWidgets($widgets, $customer)
    {

        $customer = Customer::query()->where('email', $customer->email)->first();

        if (!$customer) {
            return $widgets;
        }

        $html = (new FluentCRMDeepIntegration())->getStatsHtml($customer);

        if($html) {
            $css = 'ul.fc_full_listed {
    border-radius: 4px;
    list-style: none;
    margin: 0;
    padding: 0;
}

ul.fc_full_listed li {
    border-bottom: 1px solid #ebeef4;
    display: block;
    margin: 0;
    padding: 5px 0;
}

ul.fc_full_listed>li span.fc_list_sub {
    font-weight: 500;
}
ul.fc_full_listed>li span.fc_list_value {
    float: right;
}';

            $html .= '<style>'.$css.'</style>';
        }

        $widgets['fluent_cart_purchases'] = [
            'header'    => __('Purchases', 'fluent-cart'),
            'body_html' => $html,
        ];
        return $widgets;
    }
}
