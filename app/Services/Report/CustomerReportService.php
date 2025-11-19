<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class CustomerReportService extends ReportService
{
    public function getCustomerReportData($params = [])
    {
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $query = App::db()->table('fct_customers as o')
            ->selectRaw("
                {$group['field']},
                COUNT(*) as count
            ")
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $results = $query->get();

        $summary = [
            'customer_count' => 0,
        ];

        $keys = ['customer_count'];
        $grouped = $this->getPeriodRange(
            $params['startDate'], $params['endDate'], $group['key'], $keys
        );

        foreach ($results as $row) {
            $item = [
                'year'           => (int) $row->year,
                'group'          => $row->group,
                'customer_count' => (int) $row->count,
            ];

            $grouped[$row->group] = $item;

            $summary['customer_count'] += $item['customer_count'];
        }

        return [
            'summary' => $summary,
            'grouped' => array_values($grouped),
        ];
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        $fluctuations = [];

        $indices = ['customer_count'];

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
