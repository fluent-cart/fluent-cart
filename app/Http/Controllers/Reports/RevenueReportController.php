<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\RevenueReportService;

class RevenueReportController extends Controller
{
    protected $params = [
        'paymentStatus',
        'orderTypes',
    ];

    public function getRevenue(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);
        $compareRanges = ReportHelper::formatCompareRanges($params);

        $service = RevenueReportService::make();

        $revenueData = $service->getRevenueData($params);
        $byOrderType = $service->getRevenueByOrderType($params);

        $fluctuations = [];
        $previousMetrics = ['groups' => [], 'summary' => []];

        if ($params['comparePeriod']) {
            $params = ReportHelper::resolveGroupKey($params);
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $previousMetrics = $service->getRevenueData($params);

            $fluctuations = $service->getFluctuations($revenueData['summary'], $previousMetrics['summary']);

            // Each order type's change against the compare period
            $previousByType = array_column($service->getRevenueByOrderType($params), 'net_sales', 'key');
            foreach ($byOrderType as &$type) {
                $before = (float) ($previousByType[$type['key']] ?? 0);
                $type['previous_net_sales'] = $before;
                $type['change'] = $before > 0 ? round(($type['net_sales'] - $before) / $before * 100, 2) : null;
            }
            unset($type);
        }

        return [
            'revenueReport'   => $revenueData['groups'],
            'summary'         => $revenueData['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
            'previousMetrics' => $previousMetrics['groups'],
            'appliedGroupKey' => $revenueData['groupKey'],
            'compare_range'   => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges'  => $compareRanges,
            'byOrderType'     => $byOrderType,
        ];
    }

    public function getPaymentMethods(Request $request): array
    {
        $params = ReportHelper::processParams((array) $request->get('params', []), $this->params, true);

        return \FluentCart\App\Services\Report\PaymentMethodReportService::make()->getReport($params) + [
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];
    }

    public function getLocations(Request $request): array
    {
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, $this->params, true);
        foreach (['level', 'country', 'state', 'address_type'] as $key) {
            $params[$key] = sanitize_text_field((string) ($raw[$key] ?? ''));
        }
        $params['with_summary'] = !empty($raw['with_summary']);

        // FluentCart Pro returns the full report (states, cities, lifetime value, movers); without it, countries only
        $report = apply_filters('fluent_cart/report/locations', null, $params);
        if (!is_array($report)) {
            $report = \FluentCart\App\Services\Report\LocationReportService::make()->getReport($params);
        }

        return $report + [
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];
    }
}
