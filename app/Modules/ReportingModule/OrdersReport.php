<?php

namespace FluentCart\App\Modules\ReportingModule;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Support\Arr;

class OrdersReport
{
    /**
     * Get Order Stats by a date range which will contain
     * total_orders, paid_orders, total_paid_order_items, total_paid_amounts
     * @param string $fromDate datetime string for from date for the report
     * @param string $toData datetime string for from date for the report. Default: Today's Date Time
     * @param bool $withCompare if provide the compare values or not
     * @return array contains
     */
    public function getOrderStats($fromDate, $toDate = false, $withCompare = false)
    {
        if (!$toDate) {
            $toDate = gmdate('Y-m-d 23:59:59', current_time('timestamp'));
        }

        if (!$fromDate) {
            $fromDate = '1970-01-01 00:00:00';
        }

        $params = [
            "payment_status" => ["column" => "payment_status", "operator" => "in", "value" => Status::getTransactionSuccessStatuses()],
            "created_at" => ["column" => "created_at", "operator" => "between", "value" => [$fromDate, $toDate]]
        ];

        $currentOrders = Order::search(Arr::only($params, ['created_at']))->count();

        $stat = Order::search($params)
            ->selectRaw('count(*) as paid_orders')
            ->selectRaw('sum(total_amount) as paid_amounts')
            ->get()->first();


        $paidOrders = $stat->paid_orders;
        $paidOrderItems = $stat->paid_orders;
        $paidAmounts = $stat->paid_amounts;

        if ($withCompare) {
            $dayDiff = strtotime($toDate) - strtotime($fromDate);
            $fromDate = gmdate('Y-m-d 00:00:00', strtotime($fromDate) - $dayDiff);
            $toDate = gmdate('Y-m-d 23:59:59', strtotime($toDate) - $dayDiff);
            Arr::set($params, 'created_at.value', [$fromDate, $toDate]);
            $prevCurrentOrders = Order::search(Arr::only($params, ['created_at']))->count();
            $prevPaidOrders = Order::search($params)->count();
            $prevPaidOrderItems = Order::search($params)->count();
            $prevPaidAmounts = Order::search($params)->sum('total_amount');
        }

        return [
            'total_orders' => [
                'title' => __('All Orders', 'fluent-cart'),
                'current_count' => $currentOrders,
                'compare_count' => ($withCompare) ? $prevCurrentOrders : null
            ],
            'paid_orders' => [
                'title' => __('Paid Orders', 'fluent-cart'),
                'current_count' => $paidOrders,
                'compare_count' => ($withCompare) ? $prevPaidOrders : null
            ],
            'total_paid_order_items' => [
                'title' => __('Paid Order Items', 'fluent-cart'),
                'current_count' => $paidOrderItems,
                'compare_count' => ($withCompare) ? $prevPaidOrderItems : null
            ],
            'total_paid_amounts' => [
                'title' => __('Order Value (Paid)', 'fluent-cart'),
                'current_count' => $paidAmounts,
                'compare_count' => ($withCompare) ? $prevPaidAmounts : null,
                'is_cents' => true
            ]
        ];
    }
}
