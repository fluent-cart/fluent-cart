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
        $params = ReportHelper::processParams($request->get('params'), $this->params);
        
        $service = OrderReportService::make();

        $currentMetrics = $service->getOrderLineChart($params);

        $fluctuations = [];
        $previousMetrics = ['summary' => []];
        if ($params['comparePeriod']) {
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
            // 'previousMetrics' => $previousMetrics['chartData'],
        ];
    }

    public function getNewVsReturningCustomer(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = OrderReportService::make();

        return [
            'newVsReturning' => $service->getNewVsReturningCustomer($params)
        ];
    }

    public function getOrderByGroup(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $data = OrderReportService::make([])->groupBy($params);

        return [
            'data' => $data
        ];
    }

    public function getOrderValueDistribution(Request $request)
    {

        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = OrderReportService::make();

        return [
            'data' => $service->getOrderValueDistribution($params)
        ];
    }

    public function getReportByDayAndHour(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = OrderReportService::make();

        return $service->getReportByDayAndHour($params);
    }

    public function getItemCountDistribution(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = OrderReportService::make();

        return [
            'data' => $service->getItemCountDistribution($params)
        ];
    }

    public function getOrderCompletionTime(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = OrderReportService::make();

        return [
            'data' => $service->getOrderCompletionTime($params)
        ];
    }
}