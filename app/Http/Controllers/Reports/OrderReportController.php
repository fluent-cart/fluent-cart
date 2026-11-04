<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\OrderReportService;

class OrderReportController extends Controller
{
    protected $params = [
        'orderTypes',
        'paymentStatus',
    ];
    
    public function getOrderChart(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);
        $compareRanges = ReportHelper::formatCompareRanges($params);
        
        $service = OrderReportService::make();

        $currentMetrics = $service->getOrderLineChart($params);

        $fluctuations = [];
        $previousMetrics = ['summary' => []];
        if ($params['comparePeriod']) {
            $params = ReportHelper::resolveGroupKey($params);
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $previousMetrics = $service->getOrderLineChart($params);

            $fluctuations = $service->calculateFluctuations(
                $currentMetrics['summary'], $previousMetrics['summary']
            );
        }
        
        return [
            'orderChartData'  => $currentMetrics['chartData'],
            'summary'         => $currentMetrics['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
            'compare_range'   => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges'  => $compareRanges,
            // The chart's compare line, point by point; empty without a comparison
            'previousChartData' => $params['comparePeriod'] ? $previousMetrics['chartData'] : [],
        ];
    }

    public function getOrderMix(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        return OrderReportService::make()->getOrderMix($params);
    }

    public function getNewVsReturningCustomer(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        $service = OrderReportService::make();

        return [
            'newVsReturning' => $service->getNewVsReturningCustomer($params)
        ];
    }

    public function getFailedOrders(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        return OrderReportService::make()->getFailedOrders($params + ['showCustomers' => ReportHelper::canSeeCustomers()]);
    }
}
