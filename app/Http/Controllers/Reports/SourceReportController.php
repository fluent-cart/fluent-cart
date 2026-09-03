<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\SourceReportService;

class SourceReportController extends Controller
{
    protected $params = [
        'orderTypes',
        'paymentStatus',
        // Forwarded untouched to the 'fluent_cart/report/sources_query' filter so
        // FluentCart Pro can apply the advanced filters. ReportHelper sanitizes
        // both keys; neither has a special-case branch in processParams(), so they
        // land in $params as-is.
        'filter_type',
        'advanced_filters',
    ];

    public function index(Request $request): array
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params);

        $service = SourceReportService::make();

        $currentMetrics = $service->getSourceReportData($params);

        $fluctuations = [];

        if ($params['comparePeriod']) {
            // Only the date window moves — every other param (advanced filters
            // included) is reused, so the comparison period is measured over the
            // same segment of orders and the fluctuations compare like with like.
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            $previousMetrics = $service->getSourceReportData($params);

            $fluctuations = $service->calculateFluctuations(
                $currentMetrics, $previousMetrics
            );
        }

        return [
            'sourceReportData' => $currentMetrics,
            'fluctuations'     => $fluctuations,
        ];
    }
}
