<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\DashBoardReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ReportingController extends Controller
{

    public function getReportMeta(Request $request): array
    {

        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);
        // Store days, compared as UTC against the GMT column (see ReportHelper::processParams)
        $startDate = $startDate ? ReportHelper::toGmt(ReportHelper::siteDay($startDate)->startOfDay())->format('Y-m-d H:i:s') : null;
        $endDate = $endDate ? ReportHelper::toGmt(ReportHelper::siteDay($endDate)->endOfDay())->format('Y-m-d H:i:s') : null;

        $orderCurrencies = ReportCurrency::orderCurrencies($startDate, $endDate);

        $minDates = array_filter(array_column($orderCurrencies, 'min_created_at'));
        $min_created_at = $minDates ? min($minDates) : null;

        if ($min_created_at < $startDate) {
            $min_created_at = $startDate;
        }

        if (!$min_created_at) {
            $min_created_at = (new DateTime())->format('Y-m-d H:i:s');
        }

        $baseCurrency = BaseCurrency::get();
        $currencyCodes = array_column($orderCurrencies, 'currency');
        if (!$currencyCodes) {
            $currencyCodes = [$baseCurrency];
        }

        // Every currency with orders in range, most orders first — the picker
        // shows them all and defaults to the first
        // (dev-docs/multi-currency/07-reports.md, "Two modes").
        $currencyWithSign = [];
        foreach ($currencyCodes as $code) {
            $currencyWithSign[$code] = CurrenciesHelper::getCurrencySign($code) ?: $code;
        }

        // With more than one currency, offer every order converted to the
        // base currency at its own frozen rate (dev-docs/multi-currency/07-reports.md)
        $excludedOrders = 0;
        if (count($currencyCodes) > 1) {
            $currencyWithSign[ReportCurrency::ALL] = CurrenciesHelper::getCurrencySign($baseCurrency) ?: $baseCurrency;
            $excludedOrders = ReportCurrency::excludedOrderCount($startDate, $endDate);
        }

        $firstOrder = Order::query()->min('created_at');

        return [
            'currencies' => $currencyWithSign,
            'currency_order_counts' => array_column($orderCurrencies, 'order_count', 'currency'),
            'default_currency' => $currencyCodes[0],
            'base_currency' => $baseCurrency,
            'converted_excluded_orders' => $excludedOrders,
            // The admin reads these as store dates
            'min_date' => ReportHelper::local(new DateTime($min_created_at))->format('Y-m-d H:i:s'),
            'storeMode' => (new StoreSettings())->get('order_mode'),
            'first_order_date' => $firstOrder ? ReportHelper::local(new DateTime($firstOrder))->format('Y-m-d H:i:s') : null
        ];

    }

    //Dashboard Report Controllers

    public function getRecentOrders(Request $request)
    {

        return DashBoardReportService::getRecentOrders(ReportHelper::canSeeCustomers());
    }

    public function getRecentActivities(Request $request)
    {

        $groupKey = Arr::get($request->all(), 'groupKey', 'all');
        return DashBoardReportService::getRecentActivities($groupKey);
    }
    public function getDashBoardStats(Request $request): array
    {
        $filters = [
            'currency' => $request->get('params.currency', null),
            'payment_status' => $request->get('params.paymentStatus', null) === 'all' ? '' : $request->get('params.paymentStatus', null),
        ];
        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);

        // Store days in the site timezone; the service compares them as UTC against the GMT column
        if (empty($startDate) && empty($endDate)) {
            $firstOrder = Order::query()->min('created_at');
            $startDate = $firstOrder ? ReportHelper::local(new DateTime($firstOrder))->startOfDay() : null;
            $endDate = ReportHelper::local(new DateTime())->endOfDay();
        } else {
            $startDate = !empty($startDate) ? ReportHelper::siteDay($startDate)->startOfDay() : null;
            $endDate = !empty($endDate) ? ReportHelper::siteDay($endDate)->endOfDay() : null;
        }

        if (!$startDate || !$endDate) {
            return [
                'dashBoardStats' => [
                    'total_orders' => [
                        'title' => __('All Orders', 'fluent-cart'),
                        'icon' => 'AllOrdersIcon',
                        'current_count' => 0,
                        'compare_count' => 0,
                    ],
                    'paid_orders' => [
                        'title' => __('Paid Orders', 'fluent-cart'),
                        'icon' => 'Money',
                        'current_count' => 0,
                        'compare_count' => 0,
                    ],
                    'total_paid_order_items' => [
                        'title' => __('Paid Order Items', 'fluent-cart'),
                        'icon' => 'OrderItemsIcon',
                        'current_count' => 0,
                        'compare_count' => 0,
                    ],
                    'total_paid_amounts' => [
                        'title' => __('Order Value (Paid)', 'fluent-cart'),
                        'icon' => 'OrderValueIcon',
                        'current_count' => 0,
                        'compare_count' => 0,
                        'is_cents' => true,
                    ],
                ]
            ];
        }
        $interval = $startDate->diff($endDate)->days;

        $previousEndDate = (clone $startDate)->modify('-1 day')->endOfDay();
        $previousStartDate = (clone $previousEndDate)->modify("-$interval days")->setTime(0, 0, 0);

        $service = DashBoardReportService::make($filters)
            ->setSelects(['id', 'total_amount', 'manual_discount_total', 'tax_total', 'shipping_total', 'total_refund', 'created_at', 'subtotal', 'coupon_discount_total', 'payment_status'])
            ->setRange($startDate && $endDate ? $previousStartDate : null, $startDate && $endDate ? $endDate : null)
            ->setAmountColumns(['total_amount', 'manual_discount_total', 'tax_total', 'shipping_total', 'total_refund', 'subtotal', 'coupon_discount_total']);

        return $service->getDashboardStats($startDate, $endDate, $previousStartDate, $previousEndDate);

    }

    public function getSalesGrowthChart(Request $request): array
    {
        // Same order scope as the Sales report: unpaid orders add no revenue and must not add orders either
        $params = ReportHelper::processParams($request->get('params'), ['orderStatus', 'paymentStatus'], true);

        $service = DashBoardReportService::make();

        return [
            'salesGrowthChart' => $service->getSalesGrowthChart($params)
        ];
    }

    public function getCountryHeatMap(Request $request)
    {

        $filters = [
            'currency' => $request->get('params.currency', null),
        ];

        $service = DashBoardReportService::make($filters)
            ->setAmountColumns(['total_amount', 'manual_discount_total', 'tax_total', 'shipping_total', 'total_refund', 'subtotal', 'coupon_discount_total']);

        return $service->getCountryHeatMap();

    }

}
