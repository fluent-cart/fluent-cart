<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;

class RevenueReportService extends ReportService
{

    public function getRevenueData($params = []): array
    {
        $params = $this->clampParamsToToday($params);
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        $group = ReportHelper::processGroup($startDate, $endDate, $params['groupKey']);

        $netSql = ReportHelper::netRevenueSql();
        $taxSql = ReportHelper::unrefundedTaxSql();

        $query = ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params);
        $split = ReportHelper::refundSplitSql($params);
        $query = $query
            ->selectRaw("{$group['field']},

                SUM(o.total_paid) / 100 AS total_sales,

                SUM({$netSql}) / 100 AS net_revenue,

                SUM(o.shipping_total) / 100 AS shipping_total,

                SUM({$taxSql}) / 100 AS total_tax,

                SUM(o.total_refund) / 100 AS total_refunds,

                CASE
                    WHEN COUNT(o.id) = 0 THEN 0
                    ELSE SUM(o.total_paid / 100) / COUNT(o.id)
                END AS average_order_value,

                COUNT(o.id) AS order_count,

                -- COUNT(CASE WHEN o.total_paid = o.total_refund AND o.total_paid > 0 THEN 1 END) AS refunded_orders
                COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refunded_orders,

                SUM(o.coupon_discount_total + o.manual_discount_total) / 100 AS discounts,

                SUM({$split['shipping_kept']}) / 100 AS shipping_net,

                SUM({$split['fees_kept']}) / 100 AS fees,

                SUM({$split['tax_kept']}) / 100 AS taxes_net,

                SUM({$split['sales_refunded']}) / 100 AS refunds,

                SUM({$split['net_sales']}) / 100 AS net_sales");

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
            'discounts'             => 0,
            'fees'                  => 0,
            'shipping_net'          => 0,
            'taxes_net'             => 0,
            'refunds'               => 0,
            'net_sales'             => 0,
        ];

        $keys = [
            'total_sales',
            'net_revenue',
            'shipping_total',
            'total_tax',
            'total_refunds',
            'order_count',
            'refunded_orders',
            'discounts',
            'fees',
            'gross_sales',
            'net_sales',
            'total_collected',
            'refunds',
            'shipping_net',
            'taxes_net',
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
                'discounts'       => $row->discounts,
                'fees'            => $row->fees,
                'shipping_net'    => $row->shipping_net,
                'taxes_net'       => $row->taxes_net,
                'refunds'         => $row->refunds,
                'net_sales'       => $row->net_sales,
            ] + self::salesBreakdown($row);

            $summary['net_revenue'] += $row->net_revenue;
            $summary['gross_sale'] += $row->total_sales;
            $summary['total_refunded_amount'] += $row->total_refunds;
            $summary['tax_total'] += $row->total_tax;
            $summary['shipping_total'] += $row->shipping_total;
            $summary['order_count'] += $row->order_count;
            $summary['refunded_orders'] += $row->refunded_orders;
            $summary['discounts'] += $row->discounts;
            $summary['fees'] += $row->fees;
            $summary['shipping_net'] += $row->shipping_net;
            $summary['taxes_net'] += $row->taxes_net;
            $summary['refunds'] += $row->refunds;
            $summary['net_sales'] += $row->net_sales;
        }
        $summary = self::salesBreakdown((object) $summary) + $summary;

        return [
            'groups'   => array_values($groups),
            'summary'  => $summary,
            'groupKey' => $group['key'],
        ];
    }

    public function getFluctuations($currentMetrics, $previousMetrics)
    {
        $indices = [
            'net_revenue',
            'gross_sale',
            'total_refunded_amount',
            'tax_total',
            'shipping_total',
            'discount',
            'gross_sales',
            'discounts',
            'net_sales',
            'fees',
            'total_collected',
            'refunds',
            'shipping_net',
            'taxes_net',
        ];

        return $this->percentChanges((array) $currentMetrics, (array) $previousMetrics, $indices);
    }

    /**
     * The breakdown lines derived from the per-order split: gross sales adds discounts and refunded sales back to net
     * sales; total collected adds what was kept of shipping, fees and tax (what customers paid, less refunds).
     *
     * @param object $row net_sales, discounts, refunds, shipping_net, fees, taxes_net
     */
    private static function salesBreakdown($row): array
    {
        $net = (float) $row->net_sales;

        return [
            'gross_sales'     => $net + (float) $row->discounts + (float) $row->refunds,
            'total_collected' => $net + (float) $row->shipping_net + (float) $row->fees + (float) $row->taxes_net,
        ];
    }

    /**
     * Net sales by order type (one-time, new subscription, renewal); the rows add up to the period's net sales.
     */
    public function getRevenueByOrderType(array $params): array
    {
        $net = ReportHelper::netSalesSql($params);
        $rows = $this->applyFilters(ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params), $params)
            ->selectRaw(ReportHelper::saleTypeSql() . " AS sale_type, COUNT(*) AS orders, SUM({$net}) / 100 AS net_sales")
            ->groupBy('sale_type')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $total += (float) $row->net_sales;
        }
        $labels = ReportHelper::saleTypeLabels();

        $result = [];
        foreach ($rows as $row) {
            $orders = (int) $row->orders;
            $result[] = [
                'key'           => (string) $row->sale_type,
                'label'         => $labels[$row->sale_type] ?? (string) $row->sale_type,
                'orders'        => $orders,
                'net_sales'     => round((float) $row->net_sales, 2),
                'average'       => $orders ? round((float) $row->net_sales / $orders, 2) : 0,
                'share'         => $total ? round((float) $row->net_sales / $total * 100, 2) : 0,
            ];
        }
        usort($result, function ($a, $b) {
            return $b['net_sales'] <=> $a['net_sales'] ?: strcmp($a['key'], $b['key']);
        });

        return $result;
    }
}
