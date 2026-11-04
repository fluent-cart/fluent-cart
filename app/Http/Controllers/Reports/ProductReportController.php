<?php

namespace FluentCart\App\Http\Controllers\Reports;

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

    public function getProductSales(Request $request)
    {
        $params = ReportHelper::processParams($request->get('params'), $this->params, true);

        return ProductReportService::make()->getProductSales($params) + [
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];
    }
}
