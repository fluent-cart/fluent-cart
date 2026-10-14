<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\App;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\SubscriptionReportService;

class SubscriptionReportController extends Controller
{
    protected $params = [
        'paymentStatus',
        'subscriptionType',
    ];

    public function getRetentionChart(Request $request)
    {
        // Counts every subscription with a trustworthy rate in the converted mode
        $params = ReportHelper::processParams($request->get('params'), ['customDays'], true);

        $service = SubscriptionReportService::make();

        $retentionStats = $service->getRetentionChart($params);

        return [
            'chartData' => $retentionStats,
        ];
    }

    public function getDailySignups(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        $service = SubscriptionReportService::make();

        $dailySignups = $service->getDailySignups($params);

        return [
            'signups' => $dailySignups,
        ];
    }

    public function getSubscriptionChart(Request $request)
    {
        // No converted mode: its order counts and future installments read
        // unconverted rows, so "All currencies" reads as the base currency
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = SubscriptionReportService::make();

        $currentMetrics = $service->getChartData($params);

        $summary = [
            'future_installments' => $service->getFutureInstallments($params),
            'total_subscriptions'  => $currentMetrics['totalSubscriptions']
        ];

        $compareMetrics = [];
        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $compareMetrics = $service->getChartData($params);
        }

        return [
            'currentMetrics' => $currentMetrics['grouped'],
            'compareMetrics' => $compareMetrics['grouped'] ?? [],
            'summary'        => $summary,
            'fluctuations'   => []
        ];
    }

    public function getFutureRenewals(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), ['startDate', 'endDate'], true);

        $service = SubscriptionReportService::make();

        return $service->getFutureRenewals($params);
    }

    public function getRetentionData(Request $request)
    {
        // MRR and churn convert each subscription at its own frozen rate
        $params = ReportHelper::processParams($request->get('params'), [], true);

        $service = SubscriptionReportService::make();

        return [
            'retention_data' => $service->getRetentionData($params)
        ];
    }

    public function getCohortData(Request $request)
    {
        $requestParams = $request->get('params', []);
        // No converted mode: the retention snapshots are kept per currency
        $params = ReportHelper::processParams($requestParams);
        
        // Supported groupBy: 'month' or 'year' (week not supported with current snapshots)
        $groupBy = $requestParams['groupBy'] ?? 'year';
        if (!in_array($groupBy, ['month', 'year'])) {
            $groupBy = 'year';
        }
        
        $params['groupBy'] = $groupBy;
        $params['metric'] = $requestParams['metric'] ?? 'subscribers';
        
        // Convert variation_ids to product_ids for snapshot filtering
        // The snapshot table stores product_id, not variation_id
        $productIds = [];
        if (!empty($params['variationIds'])) {
            $productIds = App::db()->table('fct_product_variations')
                ->whereIn('id', $params['variationIds'])
                ->distinct()
                ->pluck('post_id')
                ->toArray();
        }
        $params['productIds'] = $productIds;
        
        // Calculate maxPeriods based on groupBy
        if ($groupBy === 'year') {
            // For yearly view: default 8 years, or date range + 1 (whichever is greater)
            $defaultYears = 8;
            if (isset($params['startDate']) && isset($params['endDate'])) {
                $yearDiff = $params['endDate']->diff($params['startDate'])->y + 1;
                $params['maxPeriods'] = max($defaultYears, $yearDiff);
            } else {
                $params['maxPeriods'] = $defaultYears;
            }
        } else {
            // For monthly view: 18 months to capture yearly subscription renewals
            $params['maxPeriods'] = 18;
        }

        $service = SubscriptionReportService::make();

        return $service->getCohortData($params);
    }
}
