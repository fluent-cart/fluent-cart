<?php

namespace FluentCart\App\Modules\ReportingModule;

use FluentCart\App\Services\Report\OrderReportService;

class SalesReport
{
    /**
     * Get Order Sales by a date range which will contain
     * @return array contains
     */
    public function getSalesGrowth($filters = []): array
    {
        $service = OrderReportService::make($filters)
            ->setSelects(['id', 'type', 'customer_id', 'total_amount', 'discount_total', 'total_refund', 'created_at', 'currency'])
            ->generate();
        return ($service->getYearlySaleReport());
    }
}
