<?php

namespace FluentCart\App\Services\Widgets;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\URL;


class DashboardWidget extends BaseWidget
{

    public function widgetName(): string
    {
        return 'dashboard_stats';
    }

    public function widgetData(): array
    {
        $startDate = (new DateTime())->subMonth()->startOfDay();
        $endDate = (new DateTime())->endOfDay();

        $counts = Product::query()->selectRaw("
            COUNT(*) as total_products
        ")
        ->whereNotIn('post_status', ['trash', 'auto-draft'])
        ->first();

        $sales = App::db()->table('fct_orders as o')->selectRaw("
            COUNT(o.id) AS orders,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 AS net_revenue,

            SUM(o.total_refund) / 100 AS total_refunds
        ")
        ->whereBetween('o.created_at', [$startDate, $endDate])
        ->whereNotIn('o.status', [Status::ORDER_ON_HOLD, Status::ORDER_FAILED])
        ->first();
                
        return [
            [
                'title'         => __('Total Products', 'fluent-cart'),
                'current_count' => $counts->total_products,
                'icon'          => 'Frame',
                'url'           => URL::getDashboardUrl('products', [
                    'active_view' => 'all',
                ]),
            ],
            [
                'title'         => __('Orders', 'fluent-cart'),
                'current_count' => $sales->orders,
                'icon'          => 'AllOrdersIcon',
                'url'           => URL::getDashboardUrl('orders'),
            ],
            [
                'title'         => __('Revenue', 'fluent-cart'),
                'current_count' => $sales->net_revenue,
                'icon'          => 'Currency',
                'url'           => URL::getDashboardUrl('reports/revenue'),
                'has_currency'  => true,
            ],
            [
                'title'         => __('Refund', 'fluent-cart'),
                'current_count' => $sales->total_refunds,
                'icon'          => 'Failed',
                'url'           => URL::getDashboardUrl('reports/refunds'),
                'has_currency'  => true,
            ],
        ];
    }
}
