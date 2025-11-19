<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\LicenseReportService;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\Framework\Http\Request\Request;

class LicenseReportController extends Controller
{


    public function getLicenseLineChart(Request $request): array
    {
        $params = ReportHelper::processRequest($request->get('params'));

        $service = LicenseReportService::make($params['filters'])
            ->setRange($params['startDate'], $params['endDate']);
        return $service->getLicenseLineChart($params['groupKey'], $params['startDate'], $params['endDate']);
    }

    public function getLicensePieChart(Request $request): array
    {
        $filters = [];

        $params = ReportHelper::processRequest($request->get('params'));

        $service = LicenseReportService::make($params['filters'])
            ->setRange($params['startDate'], $params['endDate']);
        return $service->getLicensePieChart($params['startDate'], $params['endDate']);
    }

    public function getLicenseSummary(Request $request): array
    {
        $params = ReportHelper::processRequest($request->get('params'));

        $service = LicenseReportService::make($params['filters'])
            ->setRange($params['startDate'], $params['endDate']);

        return $service->getSummary(
            $params['startDate'],
            $params['endDate']
        );
    }
}