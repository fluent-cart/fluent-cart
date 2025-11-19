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
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = RevenueReportService::make();

        $revenueData = $service->getRevenueData($params);

        $fluctuations = [];
        $previousMetrics = ['groups' => [], 'summary' => []];

        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $previousMetrics = $service->getRevenueData($params);

            $fluctuations = $service->getFluctuations($revenueData['summary'], $previousMetrics['summary']);
        }

        return [
            'revenueReport'   => $revenueData['groups'],
            'summary'         => $revenueData['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
            'previousMetrics' => $previousMetrics['groups'],
            'appliedGroupKey' => $revenueData['groupKey'],
        ];
    }

    public function getRevenueByGroup(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = RevenueReportService::make();

        return [
            'data' => $service->revenueByGroup($params),
        ];
    }
}
