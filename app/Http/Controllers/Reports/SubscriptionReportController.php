<?php

namespace FluentCart\App\Http\Controllers\Reports;

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
        $params = ReportHelper::processParams($request->get('params'), ['customDays']);

        $service = SubscriptionReportService::make();

        $retentionStats = $service->getRetentionChart($params);

        return [
            'chartData' => $retentionStats,
        ];
    }

    public function getDailySignups(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = SubscriptionReportService::make();

        $dailySignups = $service->getDailySignups($params);

        return [
            'signups' => $dailySignups,
        ];
    }

    public function getSubscriptionChart(Request $request)
    {
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
            'compareMetrics' => $compareMetrics['grouped'],
            'summary'        => $summary,
            'fluctuations'   => []
        ];
    }

    public function getFutureRenewals(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), ['startDate', 'endDate']);

        $service = SubscriptionReportService::make();

        return $service->getFutureRenewals($params);
    }
}
