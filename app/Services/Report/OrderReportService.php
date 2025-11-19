<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class OrderReportService extends ReportService
{
    public function groupBy($params = [])
    {
        $groupKey = $params['groupKey'];

        $query = App::db()->table('fct_orders as o');

        $query = $this->applyFilters($query, $params);

        if (in_array($groupKey, ['billing_country', 'shipping_country'])) {
            $type = $groupKey === 'billing_country' ? 'billing' : 'shipping';

            $query->leftJoin(
                'fct_order_addresses as a',
                fn ($join) => $join->on('o.id', '=', 'a.order_id')->where('a.type', '=', $type)
            );

            $query->selectRaw("COALESCE(a.country, 'Uncategorized') AS `{$groupKey}`");
        } else {
            $query->selectRaw("COALESCE(o.{$groupKey}, 'Uncategorized') AS `{$groupKey}`");
        }

        $query->selectRaw("COUNT(o.id) AS orders,

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 AS net_sale,

            SUM(o.total_paid) / COUNT(o.id) / 100 AS average_order_gross,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / COUNT(o.id) / 100 AS average_order_net")
        ->groupBy($groupKey)
        ->orderBy($groupKey);

        return $query->get();
    }

    public function getOrderValueDistribution(array $params)
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw("SUM(CASE WHEN total_amount <= 10000 THEN 1 ELSE 0 END) AS `0-100`,
                SUM(CASE WHEN total_amount > 10000 AND total_amount <= 20000 THEN 1 ELSE 0 END) AS `100-200`,
                SUM(CASE WHEN total_amount > 20000 AND total_amount <= 30000 THEN 1 ELSE 0 END) AS `200-300`,
                SUM(CASE WHEN total_amount > 30000 AND total_amount <= 40000 THEN 1 ELSE 0 END) AS `300-400`,
                SUM(CASE WHEN total_amount > 40000 AND total_amount <= 50000 THEN 1 ELSE 0 END) AS `400-500`,
                SUM(CASE WHEN total_amount > 50000 AND total_amount <= 60000 THEN 1 ELSE 0 END) AS `500-600`,
                SUM(CASE WHEN total_amount > 60000 AND total_amount <= 70000 THEN 1 ELSE 0 END) AS `600-700`,
                SUM(CASE WHEN total_amount > 70000 AND total_amount <= 80000 THEN 1 ELSE 0 END) AS `700-800`,
                SUM(CASE WHEN total_amount > 80000 AND total_amount <= 90000 THEN 1 ELSE 0 END) AS `800-900`,
                SUM(CASE WHEN total_amount > 90000 AND total_amount <= 100000 THEN 1 ELSE 0 END) AS `900-1000`,
                SUM(CASE WHEN total_amount > 100000 THEN 1 ELSE 0 END) AS `1000+`");

        $query = $this->applyFilters($query, $params);

        return $query->first();
    }

    public function getOrderLineChart($params = [])
    {
        $monthBetween = $params['endDate']->diffInMonths($params['startDate']) + 1;

        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $variationIds = array_map('intval', $params['variationIds'] ?? []);

        $itemsSub = App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS total_items')
            ->groupBy('order_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when($variationIds, fn($q) => $q->whereIn('object_id', $variationIds));
            
        $orderQuery = App::db()->table('fct_orders as o')
            ->joinSub($itemsSub, 'oi_sum', fn($join) => $join->on('oi_sum.order_id', '=', 'o.id'));

        $orderQuery = $this->applyFilters($orderQuery, $params);

        $orderData = $orderQuery->selectRaw("{$group['field']},

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 AS net_revenue,

            COUNT(o.id) AS order_count,

            SUM(o.total_refund) / 100 AS total_refund,

            SUM(o.shipping_total) / 100 AS shipping_total,

            SUM(o.tax_total + o.shipping_tax) / 100 AS tax_total,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(
                    o.total_paid
                    - o.total_refund
                    - o.tax_total
                    - o.shipping_tax
                ) / COUNT(o.id) / 100
            END AS average_net,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(o.total_paid) / COUNT(o.id) / 100
            END AS average_gross,

            SUM(COALESCE(oi_sum.total_items, 0)) AS total_item_count,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE oi_sum.total_items / COUNT(o.id)
            END AS average_order_items_count,

            ROUND(AVG(o.total_paid / 100)) AS average_order_gross")
        ->groupByRaw($group['by'])
        ->get();
        
        $summary = [
            'net_revenue'               => 0,
            'gross_sale'                => 0,
            'order_count'               => 0,
            'total_item_count'          => 0,
            'average_net'               => 0,
            'average_order_items_count' => 0,
            'average_gross'             => 0,
            'total_refund'              => 0,
            'tax_total'                 => 0,
            'shipping_total'            => 0
        ];

        $groups = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], array_keys($summary)
        );

        foreach ($orderData as $group) {
            $summary['net_revenue'] += $group->net_revenue;
            $summary['gross_sale'] += $group->gross_sale;
            $summary['order_count'] += $group->order_count;
            $summary['total_item_count'] += $group->total_item_count;
            $summary['total_refund'] += $group->total_refund;
            $summary['tax_total'] += $group->tax_total;
            $summary['shipping_total'] += $group->shipping_total;

            $groups[$group->group] = (array) $group;
        }

        if ($orderData->count()) {
            $summary['average_net'] = $summary['net_revenue'] / $summary['order_count'];
            $summary['average_order_items_count'] = $summary['total_item_count'] / $summary['order_count'];
            $summary['average_gross'] = $summary['gross_sale'] / $summary['order_count'];

            $summary['monthly_net'] = $summary['net_revenue'] / $monthBetween;
            $summary['monthly_gross'] = $summary['gross_sale'] / $monthBetween;
            $summary['monthly_orders'] = $summary['order_count'] / $monthBetween;
            $summary['monthly_items'] = $summary['total_item_count'] / $monthBetween;
        }

        return [
            'chartData' => array_values($groups),
            'summary'   => $summary,
        ];
    }

    public function getNewVsReturningCustomer($params = [])
    {
        $orders = App::db()->table('fct_orders as o')
            ->leftJoin('fct_customers as c', 'c.id', '=', 'o.customer_id');

        $orders = $this->applyFilters($orders, $params);

        $rows = $orders->selectRaw("CASE
                WHEN
                    c.first_purchase_date IS NOT NULL
                    AND c.first_purchase_date >= '{$params['startDate']}'
                THEN 'New'
                ELSE 'Returning'
            END AS customer_type,

            COUNT(DISTINCT o.customer_id) AS customer_count,

            COUNT(o.id) AS order_count,

            SUM(o.total_paid) / 100 AS gross_sales,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 AS net_sales")
        ->groupBy('customer_type')
        ->get();

        $result = [];

        if ($rows->count()) {
            foreach ($rows as $index => $item) {
                $result[$index] = $item;

                $result[$index]->average_net = $item->net_sales / $item->order_count;
                $result[$index]->average_gross = $item->gross_sales / $item->order_count;
            }
        } else {
            $metrics = [
                'customer_type'  => 'new',
                'customer_count' => 0,
                'order_count'    => 0,
                'net_sales'      => 0,
                'average_net'    => 0,
                'gross_sales'    => 0,
                'average_gross'  => 0,
            ];
    
            $result = [
                $metrics,
                wp_parse_args(['customer_type' => 'returning'], $metrics),
            ];
        }

        return $result;
    }

    public function getReportByDayAndHour(array $params): array
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw("HOUR(o.created_at) AS hour_24,

                DAYOFWEEK(o.created_at) AS day_of_week,

                COUNT(o.id) AS order_count,

                SUM(o.total_paid) / 100 AS gross_sale")
            ->groupByRaw('hour_24, day_of_week')
            ->orderByRaw('hour_24, day_of_week');

        $query = $this->applyFilters($query, $params);

        $ordersData = $query->get();

        $daysOfWeek = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday'
        ];

        $structuredData = [];
        $grossSaleByHour = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $timeLabel = gmdate('g A', mktime($hour, 0));
            $structuredData[$hour] = [
                'hour'      => $timeLabel,
                'Sunday'    => 0,
                'Monday'    => 0,
                'Tuesday'   => 0,
                'Wednesday' => 0,
                'Thursday'  => 0,
                'Friday'    => 0,
                'Saturday'  => 0,
            ];

            $grossSaleByHour[$hour] = [
                'hour'        => $timeLabel,
                'gross_sale'  => 0,
                'order_count' => 0,
            ];
        }

        $grossSaleByDay = array_fill(1, 7, ['day' => 0, 'gross_sale' => 0, 'order_count' => 0]);

        foreach ($ordersData as $order) {
            $hour = (int) $order->hour_24;
            $dayOfWeek = $daysOfWeek[$order->day_of_week];
            $orderCount = $order->order_count;
            
            $structuredData[$hour][$dayOfWeek] += $orderCount;

            $grossSaleByDay[$order->day_of_week]['day'] = $order->day_of_week;
            $grossSaleByDay[$order->day_of_week]['order_count'] += $orderCount;
            $grossSaleByDay[$order->day_of_week]['gross_sale'] += $order->gross_sale;

            $grossSaleByHour[$hour]['gross_sale'] += $order->gross_sale;
            $grossSaleByHour[$hour]['order_count'] += $orderCount;
        }

        return [
            'orderByDayAndHour' => $structuredData,
            'grossSaleByDay'    => array_values($grossSaleByDay),
            'grossSaleByHour'   => array_values($grossSaleByHour),
        ];
    }

    public function getItemCountDistribution(array $params): array
    {
        $itemsSub = App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS item_count')
            ->groupBy('order_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when($params['variationIds'], fn($q) => $q->whereIn('object_id', $params['variationIds']));
            
        $query = App::db()->table('fct_orders as o')
            ->selectRaw('COUNT(*) AS order_count, oi_sum.item_count')
            ->joinSub($itemsSub, 'oi_sum', fn($join) => $join->on('oi_sum.order_id', '=', 'o.id'))
            ->groupBy('oi_sum.item_count');

        $query = $this->applyFilters($query, $params);

        return $query->get()->toArray();
    }

    public function calculateFluctuations($currentData, $previousData)
    {
        $fluctuations = [];

        foreach ($currentData as $key => $currentValue) {
            $lastValue = $previousData[$key] ?? 0;
            if ($lastValue > 0) {
                $fluctuations[$key] = (($currentValue - $lastValue) / $lastValue) * 100;
            } else {
                $fluctuations[$key] = ($currentValue > 0) ? 100 : 0;  // 100% increase if current value is greater than 0, else 0% change
            }
        }

        return $fluctuations;
    }

    public function getOrderCompletionTime(array $params): array
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw("TIMESTAMPDIFF(
                    HOUR, o.created_at, o.completed_at
                ) AS hour,

                COUNT(o.id) AS orders")
            ->whereNotNull('o.completed_at')
            ->groupBy('hour')
            ->orderBy('hour');

        $query = $this->applyFilters($query, $params);

        return $query->get()->toArray();
    }
}
