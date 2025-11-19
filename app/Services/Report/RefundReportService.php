<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class RefundReportService extends ReportService
{
    public function getRefundDataGroupedBy($params = []): array
    {
        $groupKey = $params['groupKey'];

        $query = App::db()->query()
            ->from('fct_orders as o')
            ->join('fct_order_transactions as ot', 'o.id', '=', 'ot.order_id')
            ->selectRaw("COUNT(DISTINCT ot.order_id) as total_refunded,

                SUM(ot.total) / 100 as total_refunded_amount,

                (SUM(ot.total) / COUNT(DISTINCT ot.order_id)) / 100 as average_refunded_amount")
            ->whereIn('ot.status', ['refunded', 'partially_refunded'])
            ->where('o.total_refund', '>', 0);

        $query = $this->applyFilters($query, $params);

        if (in_array($groupKey, ['payment_method_type', 'payment_method_title'])) {
            $query = $query->selectRaw("COALESCE(NULLIF(o.{$groupKey}, ''), 'Uncategorized') as group_key")->groupBy("o.{$groupKey}");
        } else {
            $type = $groupKey === 'billing_country' ? 'billing' : 'shipping';

            $query = $query->join('fct_order_addresses as a', 'o.id', '=', 'a.order_id')
                ->selectRaw("COALESCE(a.country, 'Uncategorized') as group_key")
                ->where('a.type', $type)
                ->whereNotNull('a.country')
                ->groupBy('a.country');
        }

        $results = $query->get()->toArray();

        $formattedResults = array_map(function ($row) use ($groupKey) {
            return [
                $groupKey             => $row->group_key,
                'totalRefunded'       => (int) $row->total_refunded,
                'totalRefundedAmount' => [
                    'total'   => (float) $row->total_refunded_amount,
                    'average' => (float) $row->average_refunded_amount,
                ],
            ];
        }, $results);

        return $formattedResults;
    }

    public function getRefundData($params = []): array
    {
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $orderSubQuery = App::db()->query()
            ->from('fct_orders as o')
            ->selectRaw("{$group['field']},

                o.total_refund,

                o.total_paid");

        $orderSubQuery = $this->applyFilters($orderSubQuery, $params);

        $refundData = App::db()->query()
            ->fromSub($orderSubQuery, 'subq')
            ->selectRaw("COALESCE(`group`, 'TOTAL') as `group`,

                COUNT(
                    CASE WHEN total_refund > 0 THEN 1 END
                ) as refund_count,

                SUM(total_refund) / 100 as refunded_amount,

                CASE
                    WHEN COUNT(total_refund) > 0
                        THEN SUM(total_refund) / COUNT(total_refund) / 100
                    ELSE 0
                END as average_refunded_amount,

                CASE
                    WHEN SUM(total_paid) > 0
                        THEN (SUM(total_refund) / SUM(total_paid)) * 100
                    ELSE 0
                END as refund_rate")
            ->groupByRaw("`group` WITH ROLLUP")
            ->get()
            ->toArray();

        $summary = [
            'refund_rate'             => 0,
            'refund_count'            => 0,
            'refunded_amount'         => 0,
            'average_refunded_amount' => 0,
        ];

        if (!empty($refundData)) {
            $summary = (array) array_pop($refundData);
        }

        return [
            'summary' => $summary,
            'grouped' => $refundData,
        ];
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        $metrics = ['refund_count', 'refunded_amount', 'average_refunded_amount', 'refund_rate'];

        $result = [];

        foreach ($metrics as $metric) {
            if ($previousMetrics[$metric] != 0) {
                $result[$metric] = (($currentMetrics[$metric] - $previousMetrics[$metric]) / $previousMetrics[$metric]) * 100;
            } else {
                $result[$metric] = $currentMetrics[$metric] > 0 ? 100 : 0;
            }
        }

        return $result;
    }

    public function weeksBetweenRefund($params = []): array
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];

        $subquery = App::db()->table('fct_order_transactions')
            ->selectRaw('
                order_id,
                MIN(created_at) AS refund_date
            ')
            ->whereIn('status', ['refunded', 'partially_refunded'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('order_id');

        $orderQuery = $this->applyFilters(
            App::db()->table('fct_orders as o'), $params
        );

        return $orderQuery
            ->selectRaw('
                COUNT(*) AS occurrence,
                IFNULL(((GREATEST(TIMESTAMPDIFF(DAY, o.created_at, r.refund_date), 1) + 6) DIV 7), 1) AS weekBetween
            ')
            ->joinSub($subquery, 'r', fn ($join) => $join->on('o.id', '=', 'r.order_id'))
            ->groupBy('weekBetween')
            ->orderBy('weekBetween')
            ->get()
            ->toArray();
    }
}
