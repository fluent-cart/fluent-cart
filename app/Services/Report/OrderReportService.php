<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\App;

class OrderReportService extends ReportService
{

    public function getOrderLineChart($params = [])
    {
        $params = $this->clampParamsToToday($params);
        $monthBetween = $params['endDate'] < $params['startDate']
            ? 1
            : ReportHelper::monthSpan($params['startDate'], $params['endDate']);

        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $variationIds = array_map('intval', $params['variationIds'] ?? []);

        // Bound by order date, not item date: an item row may predate its order
        $itemsSub = App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS total_items')
            ->groupBy('order_id')
            ->whereIn('order_id', fn($q) => $q->select('id')->from('fct_orders')
                ->whereBetween('created_at', [$params['startDate'], $params['endDate']]))
            ->when($variationIds, fn($q) => $q->whereIn('object_id', $variationIds));
            
        $orderQuery = ReportCurrency::ordersTable($params)
            ->leftJoinSub($itemsSub, 'oi_sum', fn($join) => $join->on('oi_sum.order_id', '=', 'o.id'));

        $orderQuery = $this->applyFilters($orderQuery, $params);

        $netSql = ReportHelper::netRevenueSql();
        $taxSql = ReportHelper::unrefundedTaxSql();

        $orderData = $orderQuery->selectRaw("{$group['field']},

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM({$netSql}) / 100 AS net_revenue,

            COUNT(o.id) AS order_count,

            SUM(o.total_refund) / 100 AS total_refund,

            SUM(o.shipping_total) / 100 AS shipping_total,

            SUM({$taxSql}) / 100 AS tax_total,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM({$netSql}) / COUNT(o.id) / 100
            END AS average_net,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(o.total_paid) / COUNT(o.id) / 100
            END AS average_gross,

            SUM(COALESCE(oi_sum.total_items, 0)) AS total_item_count,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(COALESCE(oi_sum.total_items, 0)) / COUNT(o.id)
            END AS average_order_items_count,

            COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refunded_orders,

            ROUND(AVG(o.total_paid / 100)) AS average_order_gross")
        ->groupByRaw($group['by'])
        ->get();
        
        $summary = [
            'net_revenue'               => 0,
            'net_sales'                 => 0,
            'gross_sale'                => 0,
            'order_count'               => 0,
            'total_item_count'          => 0,
            'average_net'               => 0,
            'average_order_items_count' => 0,
            'average_gross'             => 0,
            'total_refund'              => 0,
            'tax_total'                 => 0,
            'shipping_total'            => 0,
            'refunded_orders'           => 0,
        ];

        $groups = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], array_keys($summary)
        );

        // Net sales as the Revenue report counts them (shipping, fees and tax out), so the average order matches it
        $netSales = [];
        foreach (RevenueReportService::make()->getRevenueData($params)['groups'] as $revenueGroup) {
            $revenueGroup = (array) $revenueGroup;
            $netSales[(string) $revenueGroup['group']] = (float) $revenueGroup['net_sales'];
        }

        foreach ($orderData as $group) {
            $group->net_sales = $netSales[(string) $group->group] ?? 0;
            $group->average_net = $group->order_count ? $group->net_sales / $group->order_count : 0;
            $summary['net_sales'] += $group->net_sales;
            $summary['net_revenue'] += $group->net_revenue;
            $summary['gross_sale'] += $group->gross_sale;
            $summary['order_count'] += $group->order_count;
            $summary['total_item_count'] += $group->total_item_count;
            $summary['total_refund'] += $group->total_refund;
            $summary['tax_total'] += $group->tax_total;
            $summary['shipping_total'] += $group->shipping_total;
            $summary['refunded_orders'] += $group->refunded_orders;

            $groups[$group->group] = (array) $group;
        }

        if ($orderData->count()) {
            $summary['average_net'] = $summary['net_sales'] / $summary['order_count'];
            $summary['average_order_items_count'] = $summary['total_item_count'] / $summary['order_count'];
            $summary['average_gross'] = $summary['gross_sale'] / $summary['order_count'];

            $summary['monthly_net'] = $summary['net_revenue'] / $monthBetween;
            $summary['monthly_gross'] = $summary['gross_sale'] / $monthBetween;
            $summary['monthly_orders'] = $summary['order_count'] / $monthBetween;
            $summary['monthly_items'] = $summary['total_item_count'] / $monthBetween;
        }

        // The card row's rates: refunds as a share of orders, and orders per store day (elapsed days only) or month
        $summary['refund_rate'] = $summary['order_count'] ? $summary['refunded_orders'] / $summary['order_count'] * 100 : 0;
        $days = $params['endDate'] < $params['startDate'] ? 1
            : ReportHelper::local($params['startDate'])->startOfDay()->diff(ReportHelper::local($params['endDate']))->days + 1;
        $summary['orders_per_day'] = $summary['order_count'] / $days;
        $summary['orders_per_month'] = $summary['order_count'] / $monthBetween;

        return [
            'chartData' => array_values($groups),
            'summary'   => $summary,
        ];
    }

    public function getNewVsReturningCustomer($params = [])
    {
        $renewal = \FluentCart\App\Helpers\Status::ORDER_TYPE_RENEWAL;

        // Net sales as the Revenue report counts them (shipping, fees and tax out)
        $orders = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params);
        $netSql = ReportHelper::netSalesSql($params);
        $orders = $this->applyFilters($orders, $params);

        // Renewals first: they follow a paid parent order. New is the shared first-purchase rule (the Customers
        // report's), so a free order is neither new nor returning: it gets its own type
        $rows = $orders->selectRaw("CASE
                WHEN o.type = '{$renewal}' THEN (CASE WHEN " . ReportHelper::saleTypeSql() . " = 'installment_payment' THEN 'Installment payment' ELSE 'Renewal' END)
                WHEN o.customer_id IS NULL THEN 'Guest'
                WHEN o.total_paid <= 0 THEN 'Free order'
                WHEN " . ReportHelper::firstPurchaseSql($params) . " THEN 'New'
                ELSE 'Returning'
            END AS customer_type,

            COUNT(DISTINCT o.customer_id) AS customer_count,

            COUNT(o.id) AS order_count,

            SUM(o.total_paid) / 100 AS gross_sales,

            SUM({$netSql}) / 100 AS net_sales")
        ->groupBy('customer_type')
        ->get();

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->customer_type] = $row;
        }

        $result = [];
        foreach (['New', 'Returning', 'Renewal', 'Installment payment', 'Free order', 'Guest'] as $type) {
            $row = $byType[$type] ?? null;
            // Only stores with installment plans or free orders get those rows
            if (in_array($type, ['Installment payment', 'Free order'], true) && !$row) {
                continue;
            }
            $orderCount = $row ? (int) $row->order_count : 0;
            $grossSales = $row ? (float) $row->gross_sales : 0.0;
            $netSales = $row ? (float) $row->net_sales : 0.0;

            $result[] = (object) [
                'customer_type'  => $type,
                'customer_count' => $row ? (int) $row->customer_count : 0,
                'order_count'    => $orderCount,
                'gross_sales'    => $grossSales,
                'net_sales'      => $netSales,
                'average_gross'  => $orderCount ? $grossSales / $orderCount : 0,
                'average_net'    => $orderCount ? $netSales / $orderCount : 0,
            ];
        }

        return $result;
    }

    public const FAILED_ORDER_LIST = 10;

    /**
     * Orders created in the range whose payment failed: how many, and the latest few.
     *
     * @param array $params startDate, endDate, currency
     */
    public function getFailedOrders(array $params): array
    {
        $failed = function () use ($params) {
            return $this->applyFilters(ReportCurrency::ordersTable($params), array_merge($params, [
                'paymentStatus' => [\FluentCart\App\Helpers\Status::PAYMENT_FAILED],
                'orderStatus'   => null,
            ]));
        };

        $latest = $failed()
            ->leftJoin('fct_customers as c', 'c.id', '=', 'o.customer_id')
            ->select(['o.id', 'o.total_amount', 'o.payment_method', 'o.created_at', 'c.first_name', 'c.last_name', 'c.email'])
            ->orderBy('o.created_at', 'desc')
            ->orderBy('o.id', 'desc')
            ->limit(self::FAILED_ORDER_LIST)
            ->get();

        $items = $latest->isEmpty() ? [] : App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS items')
            ->whereIn('order_id', $latest->pluck('id')->all())
            ->groupBy('order_id')
            ->pluck('items', 'order_id')
            ->all();

        $showCustomers = (bool) ($params['showCustomers'] ?? true);

        return [
            'count'            => (int) $failed()->count(),
            'customers_hidden' => !$showCustomers,
            'orders'           => $latest->map(function ($order) use ($items, $showCustomers) {
                return [
                    'order_id'       => (int) $order->id,
                    'customer_name'  => $showCustomers ? (trim($order->first_name . ' ' . $order->last_name) ?: (string) $order->email) : '',
                    'total_amount'   => round((int) $order->total_amount / 100, 2),
                    'items'          => (int) ($items[$order->id] ?? 0),
                    'payment_method' => (string) $order->payment_method,
                    'created_at'     => (string) $order->created_at,
                ];
            })->values()->all(),
        ];
    }

    public function calculateFluctuations($currentData, $previousData)
    {
        return $this->percentChanges((array) $currentData, (array) $previousData, array_keys((array) $currentData));
    }

    /**
     * The Orders card split four ways: order type, order status, discounted orders, and physical orders by
     * shipping status. Same orders as the card, so every split adds up to its total.
     */
    public function getOrderMix(array $params): array
    {
        $discounted = '(o.coupon_discount_total > 0 OR o.manual_discount_total > 0)';
        // One scan: group by every dimension at once, then fold the small result in PHP
        $rows = $this->applyFilters(ReportCurrency::ordersTable($params), $params)
            ->selectRaw(ReportHelper::saleTypeSql() . " AS sale_type, o.status, o.fulfillment_type, o.shipping_status, {$discounted} AS discounted, COUNT(*) AS orders")
            ->groupByRaw('sale_type, o.status, o.fulfillment_type, o.shipping_status, discounted')
            ->get();

        $total = 0;
        $types = [];
        $statuses = [];
        $shipping = [];
        $discountedOrders = 0;
        foreach ($rows as $row) {
            $orders = (int) $row->orders;
            $total += $orders;
            $types[$row->sale_type] = ($types[$row->sale_type] ?? 0) + $orders;
            $statuses[$row->status] = ($statuses[$row->status] ?? 0) + $orders;
            $discountedOrders += $row->discounted ? $orders : 0;
            if ($row->fulfillment_type === 'physical') {
                $key = $row->shipping_status ?: \FluentCart\App\Helpers\Status::SHIPPING_UNSHIPPED;
                $shipping[$key] = ($shipping[$key] ?? 0) + $orders;
            }
        }

        $typeLabels = ReportHelper::saleTypeLabels();
        $share = function ($count, $of) {
            return $of ? round($count / $of * 100, 2) : 0;
        };
        $list = function (array $counts, array $labels, $of) use ($share) {
            // Most orders first; ties by key, so the order is stable
            uksort($counts, function ($a, $b) use ($counts) {
                return $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b);
            });
            $result = [];
            foreach ($counts as $key => $orders) {
                $result[] = ['key' => (string) $key, 'label' => $labels[$key] ?? (string) $key, 'orders' => $orders, 'share' => $share($orders, $of)];
            }

            return $result;
        };
        $physical = array_sum($shipping);

        return [
            'total'       => $total,
            'types'       => $list($types, $typeLabels, $total),
            'statuses'    => $list($statuses, \FluentCart\App\Helpers\Status::getOrderStatuses(), $total),
            'discounted'  => ['orders' => $discountedOrders, 'share' => $share($discountedOrders, $total)],
            'fulfillment' => [
                'orders'   => $physical,
                'statuses' => $list($shipping, \FluentCart\App\Helpers\Status::getShippingStatuses(), $physical),
            ],
        ];
    }
}
