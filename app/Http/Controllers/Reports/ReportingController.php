<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Api\Resource\OrderItemResource;
use FluentCart\Api\Resource\OrderResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\ReportingModule\OrdersReport;
use FluentCart\App\Modules\ReportingModule\SalesReport;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\CartReportService;
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

    public function getSalesGrowth(Request $request, SalesReport $salesReport): array
    {

        $endYear = $request->get('end_date', Order::query()->max('created_at'));
        $startYear = $request->get('start_date', Order::query()->min('created_at'));

        $filters = [
            "status" => ["column" => "status", "operator" => "in", "value" => Status::getOrderSuccessStatuses()],
            "payment_status" => ["column" => "payment_status", "operator" => "in", "value" => Status::getTransactionSuccessStatuses()],
            "created_at" => ["column" => "created_at", "operator" => "between", "value" => [$startYear, $endYear]]
        ];

        return [
            'sales_data' => $salesReport->getSalesGrowth($filters)
        ];
    }

    public function getReportOverview(Request $request): array
    {

        $params = $request->get('params');
        $params["status"] = ["column" => "status", "operator" => "in", "value" => Status::getOrderSuccessStatuses()];
        // $params["payment_status"] = [ "column" => "payment_status", "operator" => "in", "value" => Status::getTransactionSuccessStatuses() ];
        $queryParam = Arr::only($params, ['created_at', 'status', 'payment_status']);

        $reportOverview = OrderResource::reportOverview($queryParam);

        $ordersByPaymentMethod = OrderResource::orderSummaryByPayment($queryParam);

        return [
            'data' => $reportOverview,
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

    public function getTopProductsSold(Request $request): array
    {


        return [
            'top_products_sold' => OrderItemResource::topProductsSold($request->get('params'))
        ];
    }

    public function getReportMeta(Request $request): array
    {

        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);

        $data = Order::query()
            ->select('currency')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('MIN(created_at) as min_created_at')
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->groupBy('currency')
            ->orderBy('order_count', 'desc')
            ->get(['currency', 'min_created_at']);

        $min_created_at = $data->min('min_created_at');

        if ($min_created_at < $startDate) {
            $min_created_at = $startDate;
        }

        if (!$min_created_at) {
            $min_created_at = (new DateTime())->format('Y-m-d H:i:s');
        }

        $currentCurrency = (new StoreSettings())->getCurrency();

        $currencyWithSign = CurrenciesHelper::getCurrencyWithSign([$currentCurrency]);

        return [
            'currencies' => $currencyWithSign,
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

    public function getUnfulfilledOrders(Request $request)
    {

        return DashBoardReportService::getUnfulfilledOrders();
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
        $params = ReportHelper::processParams($request->get('params'), ['orderStatus']);

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

    //Cart report controllers

    public function getCartReport(Request $request)
    {

        $filters = [
        ];
        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);

        $groupKey = $request->get('params.groupKey', null);

        $service = CartReportService::make($filters)
            ->setSelects(['customer_id', 'user_id', 'order_id', 'cart_hash', 'cart_data', 'created_at', 'completed_at', 'updated_at', 'deleted_at'])
            ->setRange($startDate && $endDate ? $startDate : null, $startDate && $endDate ? $endDate : null)
            ->generate();


        return $service->getAbandonedCartItems();
    }

    public function getAbandonedCartItems(Request $request)
    {


        $filters = [
        ];
        $startDate = $request->get('params.startDate', null);
        $endDate = $request->get('params.endDate', null);

        $groupKey = $request->get('params.groupKey', null);

        $service = CartReportService::make($filters)
            ->setSelects(['customer_id', 'user_id', 'order_id', 'cart_hash', 'cart_data', 'created_at', 'completed_at', 'updated_at', 'deleted_at'])
            ->setRange($startDate && $endDate ? $startDate : null, $startDate && $endDate ? $endDate : null)
            ->generate();

        return $service->getAbandonedCartItems();
    }
}
