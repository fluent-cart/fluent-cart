<?php

namespace FluentCart\App\Services\Widgets;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\MultiCurrency\Services\BaseAmountSql;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\RevenueReportService;
use FluentCart\App\Services\URL;


class DashboardWidget extends BaseWidget
{

    public function widgetName(): string
    {
        return 'dashboard_stats';
    }

    public function widgetData(): array
    {
        $counts = Product::query()->selectRaw("
            COUNT(*) as total_products
        ")
        ->whereNotIn('post_status', ['trash', 'auto-draft'])
        ->first();

        // The Revenue report's last 30 store days in all currencies, so the tiles match the report they link to
        [$start, $end] = ReportHelper::presetRange('last_30', new DateTime('now', wp_timezone()));
        $params = [
            'startDate' => ReportHelper::toGmt($start), 'endDate' => ReportHelper::toGmt($end), 'currency' => ReportCurrency::ALL,
            'paymentStatus' => Status::getReportStatuses(), 'variationIds' => [], 'groupKey' => 'monthly',
        ];
        $sales = RevenueReportService::make()->getRevenueData($params)['summary'];

        // Orders with no trusted rate cannot convert: the money tiles leave them out and say how many, Orders counts them
        [$trustedSql, $trustedBindings] = BaseAmountSql::trustedRate('o.');
        $orders = App::db()->table('fct_orders as o')
            ->whereBetween('o.created_at', [$params['startDate'], $params['endDate']])
            ->whereIn('o.payment_status', $params['paymentStatus'])
            ->selectRaw("COUNT(*) AS orders, COALESCE(SUM({$trustedSql} = 0), 0) AS excluded", $trustedBindings)
            ->first();
        $excluded = (int) $orders->excluded;

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
                'current_count' => (int) $orders->orders,
                'icon'          => 'AllOrdersIcon',
                'url'           => URL::getDashboardUrl('orders'),
            ],
            [
                'title'         => __('Revenue', 'fluent-cart'),
                'current_count' => $sales['net_sales'],
                'icon'          => 'Currency',
                'url'           => URL::getDashboardUrl('reports/revenue'),
                'has_currency'  => true,
                'currency'      => BaseCurrency::get(),
                'excluded_orders' => $excluded,
            ],
            [
                'title'         => __('Refund', 'fluent-cart'),
                'current_count' => $sales['total_refunded_amount'],
                'icon'          => 'Failed',
                'url'           => URL::getDashboardUrl('reports/refunds'),
                'has_currency'  => true,
                'currency'      => BaseCurrency::get(),
                'excluded_orders' => $excluded,
            ],
        ];
    }
}
