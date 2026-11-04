<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\CustomerReportService;

class CustomerReportController extends Controller
{
    public function insights(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), [], true);

        // The report permission does not cover customer names and emails
        return CustomerReportService::make()->getInsights($params, ReportHelper::canSeeCustomers());
    }

    // Paid, partly refunded and refunded orders, as the Products page reads
    public function overview(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), ['paymentStatus'], true);

        return CustomerReportService::make()->getOverview($params) + [
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];
    }

}
