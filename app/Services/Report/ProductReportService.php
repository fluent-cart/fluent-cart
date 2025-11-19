<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class ProductReportService extends ReportService
{    
    public function getProductTopChart(array $params)
    {
        $orderItemsSubQuery = App::db()->table('fct_order_items')
            ->selectRaw('order_id, object_id, post_id, title, post_title, SUM(quantity) AS sum_quantity')
            ->groupByRaw('order_id, object_id, post_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['variationIds']), fn($q) =>
                $q->whereIn('object_id', $params['variationIds'])
            );

        $query = App::db()->table('fct_orders as o')
            ->joinSub($orderItemsSubQuery, 'oi', function ($join) {
                $join->on('oi.order_id', '=', 'o.id');
            })
            ->leftJoin('posts as p', 'p.ID', '=', 'oi.post_id')
            ->leftJoin('fct_product_variations as pv', 'pv.id', '=', 'oi.object_id')
            ->selectRaw("
                DATE_FORMAT(o.created_at, '%Y-%m') as month,

                oi.object_id,

                SUM(oi.sum_quantity) as total_quantity,

                pv.variation_title AS latest_title,
                
                p.post_title AS latest_post_title
            ")
            ->groupByRaw("DATE_FORMAT(o.created_at, '%Y-%m'), oi.object_id")
            ->orderByRaw("DATE_FORMAT(o.created_at, '%Y-%m')");

        $query = $this->applyFilters($query, $params);

        $raw = $query->get();

        $result = [];

        foreach ($raw as $item) {
            $month = $item->month;
            if (!isset($result[$month])) {
                $result[$month] = [];
            }
            $result[$month][] = [
                'name'         => $item->latest_title,
                'post_title'   => $item->latest_post_title,
                'value'        => (int)$item->total_quantity,
                'variation_id' => (int)$item->object_id,
            ];
        }

        return $result;
    }

    public function getProductReportData($params = [])
    {
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $orderItemsSubQuery = App::db()->table('fct_order_items')
            ->selectRaw('order_id, object_id, SUM(quantity) AS sum_quantity')
            ->groupByRaw('order_id, object_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when(!empty($params['variationIds']), fn($q) =>
                $q->whereIn('object_id', $params['variationIds'])
            );

        $query = App::db()->table('fct_orders as o')
            ->joinSub($orderItemsSubQuery, 'oi', function ($join) {
                $join->on('oi.order_id', '=', 'o.id');
            })
            ->selectRaw("
                {$group['field']},

                SUM(oi.sum_quantity) AS units_sold,

                SUM(o.total_paid) / 100 AS gross_sale,

                SUM(o.total_refund) / 100 AS total_refunds,

                SUM(
                    o.total_paid
                    - o.total_refund
                    - o.tax_total
                    - o.shipping_tax
                ) / 100 AS net_sale,

                ROUND(SUM(o.total_paid) / NULLIF(SUM(oi.sum_quantity), 0) / 100, 2) AS average_selling_price,

                COUNT(DISTINCT o.customer_id) AS customers_count
            ")
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $query = $this->applyFilters($query, $params);

        $results = $query->get();

        $summary = [
            'units_sold'            => 0,
            'gross_sale'            => 0,
            'total_refunds'         => 0,
            'net_sale'              => 0,
            'average_selling_price' => 0,
            'customer_count'        => 0,
        ];
        
        $groups = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], array_keys($summary)
        );

        foreach ($results as $row) {
            $item = [
                'year'                  => (int) $row->year,
                'group'                 => $row->group,
                'units_sold'            => (int) $row->units_sold,
                'gross_sale'            => (float) $row->gross_sale,
                'total_refunds'         => (float) $row->total_refunds,
                'net_sale'              => (float) $row->net_sale,
                'average_selling_price' => (float) $row->average_selling_price,
                'customer_count'        => (int) $row->customers_count,
            ];

            $groups[$row->group] = $item;

            $summary['units_sold'] += $item['units_sold'];
            $summary['gross_sale'] += $item['gross_sale'];
            $summary['total_refunds'] += $item['total_refunds'];
            $summary['net_sale'] += $item['net_sale'];
            $summary['customer_count'] += $item['customer_count'];
        }

        if ($summary['units_sold']) {
            $summary['average_selling_price'] = $summary['gross_sale'] / $summary['units_sold'];
        }

        return [
            'summary' => $summary,
            'grouped' => array_values($groups),
        ];
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        $fluctuations = [];

        $indices = ['units_sold', 'gross_sale', 'average_selling_price', 'customer_count', 'total_refunds', 'net_sale'];

        foreach ($indices as $key) {
            $previousValue = $previousMetrics[$key] ?? 0;
            $currentValue = $currentMetrics[$key] ?? 0;

            if ($previousValue > 0) {
                $fluctuations[$key] = (($currentValue - $previousValue) / $previousValue) * 100;
            } else {
                $fluctuations[$key] = $currentValue > 0 ? 100 : 0;
            }
        }

        return $fluctuations;
    }
}