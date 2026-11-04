<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\RefundReportService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Services\Report\ReportHelper;

class RefundReportController extends Controller
{
    protected $params = [
        'orderTypes',
        'paymentStatus',
    ];

    public function getOverview(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);
        $compareRanges = ReportHelper::formatCompareRanges($params);
        $service = RefundReportService::make();
        // The most refunded products are a FluentCart Pro report
        $withProducts = (bool) apply_filters('fluent_cart/report/refund_products', false);
        $current = $service->getOverview(['withProducts' => $withProducts] + $params);
        if (!$withProducts) {
            unset($current['by_product']);
        }

        $previous = null;
        $previousChart = [];
        $fluctuations = [];
        if ($params['comparePeriod']) {
            $params = ReportHelper::resolveGroupKey($params);
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];
            $prior = $service->getOverview(['withProducts' => false] + $params);
            $previous = $prior['summary'];
            $previousChart = $prior['chart'];
            foreach (['refunded_amount', 'refunds', 'refunded_orders', 'average_refund', 'refund_rate_cohort', 'refund_rate_orders', 'refund_rate_amount', 'median_days'] as $key) {
                // A rate is null when no orders were placed; then there is no change to show
                if ($previous[$key] === null || $current['summary'][$key] === null) {
                    $fluctuations[$key] = null;
                    continue;
                }
                $before = (float) $previous[$key];
                $now = (float) $current['summary'][$key];
                if (strpos($key, 'refund_rate') === 0) {
                    // A rate changes in points, as on the other reports
                    $fluctuations[$key] = round($now - $before, 2);
                } else {
                    // Nothing to compare with: no change, rather than a made-up +100%
                    $fluctuations[$key] = $before > 0 ? ($now - $before) / $before * 100 : null;
                }
            }
        }

        return $current + [
            'previousSummary' => $previous,
            'previousChart'   => $previousChart,
            'fluctuations'    => $fluctuations,
            'compare_range'   => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges'  => $compareRanges,
        ];
    }

}
