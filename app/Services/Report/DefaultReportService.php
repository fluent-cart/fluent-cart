<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class DefaultReportService extends ReportService
{
    public function fetchTopSoldProducts(array $params)
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw('
                oi.post_id AS product_id,
                p.post_title AS product_name,
                SUM(oi.quantity) AS quantity_sold,
                SUM(o.total_amount) / 100 AS total_amount
            ')
            ->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('posts as p', 'p.ID', '=', 'oi.post_id')
            ->groupBy('oi.post_id', 'oi.post_title')
            ->orderByDesc('quantity_sold');

        unset($params['variationIds']);

        $query = $this->applyFilters($query, $params);

        $topSoldProducts = $query->limit(10)->get()->map(fn ($item) => [
            'product_id'    => (int) $item->product_id,
            'product_name'  => $item->product_name,
            'quantity_sold' => (int) $item->quantity_sold,
            'total_amount'  => round((float) $item->total_amount, 2),
            'media'         => null,
        ]);

        return [
            'topSoldProducts' => $topSoldProducts,
        ];
    }

    public function fetchTopSoldVariants(array $params): array
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw('
                oi.object_id AS variation_id,
                oi.title AS variation_name,
                oi.post_id AS product_id,
                p.post_title AS product_name,
                SUM(oi.quantity) AS quantity_sold,
                SUM(o.total_amount) / 100 AS total_amount
            ')
            ->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('posts as p', 'p.ID', '=', 'oi.post_id')
            ->groupBy('oi.object_id', 'oi.title')
            ->orderByDesc('quantity_sold');

        unset($params['variationIds']);

        $query = $this->applyFilters($query, $params);

        $topSoldVariants = $query->limit(10)->get()->map(fn ($item) => [
            'product_id'     => (int) $item->product_id,
            'product_name'   => $item->product_name,
            'variation_id'   => (int) $item->variation_id,
            'variation_name' => $item->variation_name,
            'quantity'       => (int) $item->quantity_sold,
            'total_amount'   => round((float) $item->total_amount, 2),
            'media_url'      => null,
        ]);

        return [
            'topSoldVariants' => $topSoldVariants,
        ];
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        // Calculate fluctuations for each metric
        $metrics = [
            'gross_sale'  => $this->calculateFluctuation($currentMetrics['gross_sale'], $previousMetrics['gross_sale']),
            'net_revenue' => $this->calculateFluctuation($currentMetrics['net_revenue'], $previousMetrics['net_revenue']),
            // 'subscription_revenue' => $this->calculateFluctuation($currentMetrics['subscription_revenue'], $previousMetrics['subscription_revenue']),
            'order_count' => $this->calculateFluctuation($currentMetrics['order_count'], $previousMetrics['order_count']),
            // 'new_customers' => $this->calculateFluctuation($currentMetrics['new_customers'], $previousMetrics['new_customers']),
            'total_item_count'        => $this->calculateFluctuation($currentMetrics['total_item_count'], $previousMetrics['total_item_count']),
            'total_refunded'          => $this->calculateFluctuation($currentMetrics['total_refunded'], $previousMetrics['total_refunded']),
            'total_refunded_amount'   => $this->calculateFluctuation($currentMetrics['total_refunded_amount'], $previousMetrics['total_refunded_amount']),
            'average_order_net'       => $this->calculateFluctuation($currentMetrics['average_order_net'], $previousMetrics['average_order_net']),
            'average_order_items'     => $this->calculateFluctuation($currentMetrics['average_order_items'], $previousMetrics['average_order_items']),
            'average_customer_orders' => $this->calculateFluctuation($currentMetrics['average_customer_orders'], $previousMetrics['average_customer_orders']),
            'average_customer_ltv'    => $this->calculateFluctuation($currentMetrics['average_customer_ltv'], $previousMetrics['average_customer_ltv']),
        ];

        return $metrics;
    }

    private function calculateFluctuation($currentValue, $previousValue)
    {
        if ($previousValue > 0) {
            return round((($currentValue - $previousValue) / $previousValue) * 100, 2);
        }

        return $currentValue > 0 ? 100 : 0;
    }

    public function getAllGraphMetricsSeparate($params = [])
    {
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $variationIds = array_map('intval', $params['variationIds'] ?? []);

        $itemsSub = App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS items_sold')
            ->groupBy('order_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when($variationIds, fn ($q) => $q->whereIn('object_id', $variationIds));

        $orderMetricsQuery = App::db()->table('fct_orders as o')
            ->joinSub($itemsSub, 'oi_sum', fn ($join) => $join->on('oi_sum.order_id', '=', 'o.id'));

        $orderMetricsQuery = $orderMetricsQuery->selectRaw("{$group['field']},

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 as net_revenue,

            SUM(o.total_refund) / 100 AS refund_amount,

            COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refund_count,

            COUNT(o.id) as order_count,

            COUNT(CASE WHEN o.parent_id > 0 THEN 1 END) as subscription_renewals,

            COUNT(CASE WHEN o.type = 'payment' THEN 1 END) AS onetime_count,
            COUNT(CASE WHEN o.type = 'renewal' THEN 1 END) AS renewal_count,
            COUNT(CASE WHEN o.type = 'subscription' THEN 1 END) AS subscription_count,

            SUM(CASE WHEN o.type = 'payment' THEN o.total_paid ELSE 0 END) / 100 AS onetime_gross,
            SUM(CASE WHEN o.type = 'renewal' THEN o.total_paid ELSE 0 END) / 100 AS renewal_gross,
            SUM(CASE WHEN o.type = 'subscription' THEN o.total_paid ELSE 0 END) / 100 AS subscription_gross,

            SUM(
                CASE WHEN o.type = 'payment' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS onetime_net,
            SUM(
                CASE WHEN o.type = 'renewal' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS renewal_net,
            SUM(
                CASE WHEN o.type = 'subscription' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS subscription_net,

            SUM(COALESCE(oi_sum.items_sold, 0)) AS items_sold,

            SUM(
                CASE
                    WHEN o.parent_id > 0
                    THEN o.total_paid - o.total_refund - o.tax_total - o.shipping_tax
                    ELSE 0
                END
            ) / 100 as subscription_revenue")
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $orderMetricsQuery = $this->applyFilters($orderMetricsQuery, $params);

        $orderMetrics = $orderMetricsQuery->get();

        return $this->combineMetricsResults($orderMetrics);
    }

    private function combineMetricsResults($orderMetrics): array
    {
        $metrics = [
            'orderGraph'               => [],
            'grossSaleGraph'           => [],
            'refundsGraph'             => [],
            'refundCountGraph'         => [],
            'netRevenueGraph'          => [],
            'itemsSoldGraph'           => [],
            'subscriptionRenewalGraph' => [],
            'subscriptionRevenueGraph' => [],
        ];

        $summary = [
            'gross_sale'                 => 0,
            'net_revenue'                => 0,
            'order_count'                => 0,
            'subscription_renewal_count' => 0,
            'total_item_count'           => 0,
            'total_refunded_amount'      => 0,
            'total_refunded'             => 0,
            'average_order_net'          => 0,
            'average_order_items'        => 0,
            'average_customer_orders'    => 0,
            'average_customer_ltv'       => 0,
            'onetime_count'              => 0,
            'renewal_count'              => 0,
            'subscription_count'         => 0,
            'onetime_gross'              => 0,
            'renewal_gross'              => 0,
            'subscription_gross'         => 0,
            'onetime_net'                => 0,
            'renewal_net'                => 0,
            'subscription_net'           => 0,
        ];

        // Process order metrics
        foreach ($orderMetrics as $row) {
            $period = $row->group;
            $metrics['grossSaleGraph'][$period] = (float) $row->gross_sale;
            $metrics['netRevenueGraph'][$period] = (float) $row->net_revenue;
            $metrics['orderGraph'][$period] = (int) $row->order_count;
            $metrics['subscriptionRenewalGraph'][$period] = (int) $row->subscription_renewals;
            $metrics['subscriptionRevenueGraph'][$period] = (float) $row->subscription_revenue;
            $metrics['refundsGraph'][$period] = (float) $row->refund_amount;
            $metrics['refundCountGraph'][$period] = (int) $row->refund_count;
            $metrics['itemsSoldGraph'][$row->group] = (int) $row->items_sold;

            $summary['gross_sale'] += (float) $row->gross_sale;
            $summary['net_revenue'] += (float) $row->net_revenue;
            $summary['order_count'] += (int) $row->order_count;
            $summary['total_refunded_amount'] += (float) $row->refund_amount;
            $summary['total_refunded'] += (int) $row->refund_count;
            $summary['total_item_count'] += (int) $row->items_sold;

            $summary['onetime_count'] += (int) $row->onetime_count;
            $summary['renewal_count'] += (int) $row->renewal_count;
            $summary['subscription_count'] += (int) $row->subscription_count;

            $summary['onetime_gross'] += (float) $row->onetime_gross;
            $summary['renewal_gross'] += (float) $row->renewal_gross;
            $summary['subscription_gross'] += (float) $row->subscription_gross;

            $summary['onetime_net'] += (float) $row->onetime_net;
            $summary['renewal_net'] += (float) $row->renewal_net;
            $summary['subscription_net'] += (float) $row->subscription_net;
        }

        return [
            'metrics' => $metrics,
            'summary' => $summary,
        ];
    }
}
