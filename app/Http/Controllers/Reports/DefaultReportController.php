<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\DefaultReportService;

class DefaultReportController extends Controller
{
    protected $params = [
        'orderTypes',
        'paymentStatus',
    ];

    public function getTopSoldProducts(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = DefaultReportService::make();

        return $service->fetchTopSoldProducts($params);
    }

    public function getTopSoldVariants(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = DefaultReportService::make();

        return $service->fetchTopSoldVariants($params);
    }

    public function getSalesReport(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = DefaultReportService::make([]);

        $currentMetrics = $service->getAllGraphMetricsSeparate($params);

        $fluctuations = [];
        $previousMetrics = ['summary' => []];

        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];
            $previousMetrics = $service->getAllGraphMetricsSeparate($params);

            $fluctuations = $service->calculateFluctuations(
                $currentMetrics['summary'], $previousMetrics['summary']
            );
        }
        
        return [
            'graphs'          => $currentMetrics['metrics'],
            'summaryData'     => $currentMetrics['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
        ];
    }
}
