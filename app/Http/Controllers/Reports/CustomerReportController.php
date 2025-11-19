<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\CustomerReportService;

class CustomerReportController extends Controller
{
    public function index(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'));

        $service = CustomerReportService::make();

        $currentMetrics = $service->getCustomerReportData($params);

        $fluctuations = [];
        $previousMetrics = ['grouped' => [], 'summary' => []];

        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];
            $previousMetrics = $service->getCustomerReportData($params);

            $fluctuations = $service->calculateFluctuations(
                $currentMetrics['summary'], $previousMetrics['summary']
            );
        }

        return [
            'summary'         => $currentMetrics['summary'],
            'previousSummary' => $previousMetrics['summary'],
            'fluctuations'    => $fluctuations,
            'currentMetrics'  => $currentMetrics['grouped'],
            'previousMetrics' => $previousMetrics['grouped'],
        ];
    }
}
