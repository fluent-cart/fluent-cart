<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\Api\Resource\OrderItemResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\ReportingModule\OrdersReport;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\DashBoardReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ReportingController extends Controller
{
    public function getOrderQuickStats(Request $request): array
    {
        $compare = true;
        $fromRange = $request->get('day_range', '-0 days');
        if ($fromRange == 'this_month') {
            $fromDate = gmdate('Y-m-01 00:00:00');
        } else if ($fromRange == 'all_time') {
            $fromDate = false;
            $compare = false;
        } else {
            $fromDate = gmdate('Y-m-d 00:00:00', strtotime($fromRange));
        }

        return [
            'stats' => (new OrdersReport())->getOrderStats($fromDate, false, $compare),
            'from_date' => $fromDate,
            'to_date' => gmdate('Y-m-d 23:59:59', current_time('timestamp'))
        ];
    }

    /**
     * @deprecated since v1.4. Use GET reports/overview (OverviewReportController::getOverview) instead.
     */
    public function getReportOverview(Request $request): array
    {
        _deprecated_function(__METHOD__, '1.4', 'GET /fluent-cart/v2/reports/overview');

        $params = $request->get('params');
        $params["status"] = ["column" => "status", "operator" => "in", "value" => Status::getOrderSuccessStatuses()];
        $queryParam = Arr::only($params, ['created_at', 'status', 'payment_status']);

        $reportOverview = OrderResource::reportOverview($queryParam);

        $ordersByPaymentMethod = OrderResource::orderSummaryByPayment($queryParam);

        return [
            '_deprecated'             => 'This endpoint is deprecated since v1.4 and will be removed in a future release. Use GET /fluent-cart/v2/reports/overview instead.',
            'data'                    => $reportOverview,
            'orders_by_payment_method' => $ordersByPaymentMethod,
        ];
    }

    public function searchRepeatCustomer(Request $request): array
    {
        $params = $request->get('params');

        return [
            'repeat_customers' => CustomerHelper::getRepeatCustomerBySearch($params)->paginate(Arr::get($params, 'per_page'), ['*'], 'page', Arr::get($params, 'current_page'))
        ];
    }

    /**
     * @deprecated since v1.4. Use GET reports/fetch-top-sold-products (DefaultReportController::getTopSoldProducts) instead.
     */
    public function getTopProductsSold(Request $request): array
    {
        _deprecated_function(__METHOD__, '1.4', 'GET /fluent-cart/v2/reports/fetch-top-sold-products');

        return [
            '_deprecated'      => 'This endpoint is deprecated since v1.4 and will be removed in a future release. Use GET /fluent-cart/v2/reports/fetch-top-sold-products instead.',
            'top_products_sold' => OrderItemResource::topProductsSold($request->get('params')),
        ];
    }

    public function getReportMeta(Request $request): array
    {

        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);

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
        // shows them all and defaults to the first (multi-currency plan §13).
        $currencyWithSign = [];
        foreach ($currencyCodes as $code) {
            $currencyWithSign[$code] = CurrenciesHelper::getCurrencySign($code) ?: $code;
        }

        // With more than one currency, offer every order converted to the
        // base currency at its own frozen rate (multi-currency plan §2.4 Tier B)
        $excludedOrders = 0;
        if (count($currencyCodes) > 1) {
            $currencyWithSign[ReportCurrency::ALL] = CurrenciesHelper::getCurrencySign($baseCurrency) ?: $baseCurrency;
            $excludedOrders = ReportCurrency::excludedOrderCount($startDate, $endDate);
        }

        return [
            'currencies' => $currencyWithSign,
            'currency_order_counts' => array_column($orderCurrencies, 'order_count', 'currency'),
            'default_currency' => $currencyCodes[0],
            'base_currency' => $baseCurrency,
            'converted_excluded_orders' => $excludedOrders,
            'min_date' => $min_created_at,
            'storeMode' => (new StoreSettings())->get('order_mode'),
            'first_order_date' => Order::query()->min('created_at')
        ];

    }

    //Dashboard Report Controllers

    public function getDashBoardSummary(Request $request)
    {

        return DashBoardReportService::getSummary();
    }
    public function getRecentOrders(Request $request)
    {

        return DashBoardReportService::getRecentOrders();
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
//
//        $startDate = null;
//        $endDate = null;

        if (empty($startDate) && empty($endDate)) {
            // Retrieve the first order's created_at date and set the start date
            $firstOrder = Order::query()->orderBy('created_at', 'asc')->first();

            $startDate = $firstOrder ? new DateTime($firstOrder->created_at) : null;

            // Set the end date to the current date
            $endDate = new DateTime();
        } else {
            $startDate = !empty($startDate) ? new DateTime($startDate) : null;
            $endDate = !empty($endDate) ? new DateTime($endDate) : null;
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
        $params = ReportHelper::processParams($request->get('params'), ['orderStatus'], true);

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
