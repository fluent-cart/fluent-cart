<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class RevenueReportService extends ReportService
{
    public function revenueByGroup(array $params = []): array
    {
        $query = App::db()->table('fct_orders as o')
            ->leftJoin('fct_order_items as oi', 'o.id', '=', 'oi.order_id')
            ->when($params['variationIds'], fn($q) => $q->whereIn('oi.object_id', $params['variationIds']));

        $query = $this->applyFilters($query, $params);

        $groupKeyExpression = '';

        if (in_array($params['groupKey'], ['billing_country', 'shipping_country'])) {
            $type = $params['groupKey'] === 'billing_country' ? 'billing' : 'shipping';

            $query->leftJoin('fct_order_addresses as a', function ($join) use ($type) {
                $join->on('o.id', 'a.order_id')->where('a.type', $type);
            });

            $groupKeyExpression = "COALESCE(a.country, 'Uncategorized') AS `{$params['groupKey']}`";
        } elseif ($params['groupKey'] === 'payment_method') {
            $groupKeyExpression = "COALESCE(o.payment_method_title, 'Unknown') AS `{$params['groupKey']}`";
        } else {
            $groupKeyExpression = "COALESCE(o.{$params['groupKey']}, 'Unknown') AS `{$params['groupKey']}`";
        }

        $query->selectRaw("{$groupKeyExpression},

            COUNT(o.id) AS orders,

            -- COUNT(CASE WHEN o.total_paid = o.total_refund AND o.total_paid > 0 THEN 1 END) AS refunded_orders,
            COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refunded_orders,

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 AS net_sale,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(o.total_paid) / COUNT(o.id) / 100
            END AS average_order_gross,

            CASE
                WHEN COUNT(o.id) = 0 THEN 0
                ELSE SUM(
                    o.total_paid
                    - o.total_refund
                    - o.tax_total
                    - o.shipping_tax
                ) / COUNT(o.id) / 100
            END AS average_order_net,

            SUM(oi.quantity) AS items,

            SUM(o.shipping_total) / 100 AS shipping_total,

            SUM(o.tax_total + o.shipping_tax) / 100 AS total_tax,

            SUM(o.total_refund) / 100 AS total_refunds,

            MAX(o.payment_method_title) AS payment_method,

            COUNT(DISTINCT o.customer_id) AS customer_count");

        return $query->groupByRaw($params['groupKey'])->orderByRaw($params['groupKey'])->get()->toArray();
    }

    public function getRevenueData($params = []): array
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        $group = ReportHelper::processGroup($startDate, $endDate, $params['groupKey']);

        $query = App::db()->table('fct_orders as o')
            ->selectRaw("{$group['field']},

                SUM(o.total_paid) / 100 AS total_sales,

                SUM(
                    o.total_paid
                    - o.total_refund
                    - o.tax_total
                    - o.shipping_tax
                ) / 100 AS net_revenue,

                SUM(o.shipping_total) / 100 AS shipping_total,

                SUM(o.tax_total + o.shipping_tax) / 100 AS total_tax,

                SUM(o.total_refund) / 100 AS total_refunds,

                CASE
                    WHEN COUNT(o.id) = 0 THEN 0
                    ELSE SUM(o.total_paid / 100) / COUNT(o.id)
                END AS average_order_value,

                COUNT(o.id) AS order_count,

                -- COUNT(CASE WHEN o.total_paid = o.total_refund AND o.total_paid > 0 THEN 1 END) AS refunded_orders
                COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refunded_orders");

        $query = $this->applyFilters($query, $params);

        $results = $query->groupByRaw($group['by'])->orderBy('group')->get();

        $summary = [
            'net_revenue'           => 0,
            'gross_sale'            => 0,
            'total_refunded_amount' => 0,
            'tax_total'             => 0,
            'shipping_total'        => 0,
            'order_count'           => 0,
            'refunded_orders'       => 0,
        ];

        $keys = [
            'total_sales',
            'net_revenue',
            'shipping_total',
            'total_tax',
            'total_refunds',
            'order_count',
            'refunded_orders'
        ];

        $groups = $this->getPeriodRange($startDate, $endDate, $group['key'], $keys);

        foreach ($results as $row) {
            $groups[$row->group] = [
                'year'            => $row->year,
                'group'           => $row->group,  
                'total_sales'     => $row->total_sales,
                'net_revenue'     => $row->net_revenue,
                'shipping_total'  => $row->shipping_total,
                'total_tax'       => $row->total_tax,
                'total_refunds'   => $row->total_refunds,
                'order_count'     => $row->order_count,
                'refunded_orders' => $row->refunded_orders,
            ];

            $summary['net_revenue'] += $row->net_revenue;
            $summary['gross_sale'] += $row->total_sales;
            $summary['total_refunded_amount'] += $row->total_refunds;
            $summary['tax_total'] += $row->total_tax;
            $summary['shipping_total'] += $row->shipping_total;
            $summary['order_count'] += $row->order_count;
            $summary['refunded_orders'] += $row->refunded_orders;
        }

        return [
            'groups'   => array_values($groups),
            'summary'  => $summary,
            'groupKey' => $group['key'],
        ];
    }

    public function getFluctuations($currentMetrics, $previousMetrics)
    {
        $fluctuations = [];

        $indices = [
            'net_revenue',
            'gross_sale',
            'total_refunded_amount',
            'tax_total',
            'shipping_total',
            'discount',
        ];

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
