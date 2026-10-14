<?php

namespace FluentCart\App\Services\Widgets;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\MultiCurrency\Services\BaseAmountSql;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
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

        [$revenueSql, $revenueBindings] = BaseAmountSql::toBase(
            'o.total_paid - o.total_refund - o.tax_total - o.shipping_tax',
            'o.'
        );
        [$refundSql, $refundBindings] = BaseAmountSql::toBase('o.total_refund', 'o.');
        [$trustedSql, $trustedBindings] = BaseAmountSql::trustedRate('o.');

        $sales = App::db()->table('fct_orders as o')->selectRaw("
            COUNT(o.id) AS orders,
            COALESCE(SUM({$revenueSql}), 0) / 100 AS net_revenue,
            COALESCE(SUM({$refundSql}), 0) / 100 AS total_refunds,
            COALESCE(SUM(CASE WHEN {$trustedSql} = 0 THEN 1 ELSE 0 END), 0) AS excluded_orders
        ", array_merge($revenueBindings, $refundBindings, $trustedBindings))
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
                'currency'      => BaseCurrency::get(),
                'excluded_orders' => (int) $sales->excluded_orders,
            ],
            [
                'title'         => __('Refund', 'fluent-cart'),
                'current_count' => $sales->total_refunds,
                'icon'          => 'Failed',
                'url'           => URL::getDashboardUrl('reports/refunds'),
                'has_currency'  => true,
                'currency'      => BaseCurrency::get(),
                'excluded_orders' => (int) $sales->excluded_orders,
            ],
        ];
    }
}
