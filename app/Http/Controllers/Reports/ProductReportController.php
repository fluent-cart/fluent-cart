<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\ProductReportService;

class ProductReportController extends Controller
{
    protected $params = [
        'paymentStatus',
        'orderTypes',
    ];

    public function getProductPerformance(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        $service = ProductReportService::make();

        $productPerformance = $service->getProductTopChart($params);

        return [
            'productPerformance' => $productPerformance,
        ];
    }

    public function getProductReport(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);
        $compareRanges = ReportHelper::formatCompareRanges($params);
        
        $service = ProductReportService::make();

        $currentMetrics = $service->getProductReportData($params);

        $fluctuations = [];
        $previousMetrics = ['grouped' => [], 'summary' => []];

        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $previousMetrics = $service->getProductReportData($params);

            $fluctuations = $service->calculateFluctuations($currentMetrics['summary'], $previousMetrics['summary']);
        }

        return [
            'summary'         => $currentMetrics['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
            'currentMetrics'  => $currentMetrics['grouped'],
            'previousMetrics' => $previousMetrics['grouped'],
            'compare_range'   => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges'  => $compareRanges,
        ];
    }

    public function getProductCompare(Request $request)
    {
        if (!App::isProActive()) {
            return $this->sendError([
                'message' => __('Comparing products is only available in FluentCart Pro.', 'fluent-cart')
            ], 403);
        }

        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        return ProductReportService::make()->getProductCompare($params);
    }
}
