<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\CouponReportService;
use FluentCart\App\Services\Report\ReportHelper;

class CouponReportController extends Controller
{
    public function overview(Request $request): array
    {
        $params = ReportHelper::processParams((array) $request->get('params', []), ['paymentStatus', 'orderTypes'], true);

        $overview = CouponReportService::make()->getOverview(['withComparison' => self::withQuality()] + $params);
        // Coupon vs full price is a FluentCart Pro report
        if (!self::withQuality()) {
            unset($overview['comparison']);
        }

        return $overview + [
            'compare_ranges' => ReportHelper::formatCompareRanges($params),
        ];
    }

    public function index(Request $request): array
    {
        $params = ReportHelper::processParams((array) $request->get('params', []), ['paymentStatus', 'orderTypes'], true);

        $report = CouponReportService::make()->getReport(['withQuality' => self::withQuality()] + $params);
        // The value of the customers a coupon won and its refund rate are a FluentCart Pro report
        if (!self::withQuality()) {
            $strip = function (array $row) {
                return array_diff_key($row, array_flip(self::QUALITY_KEYS));
            };
            $report['rows'] = array_map($strip, $report['rows']);
            $report['totals'] = $strip($report['totals']);
        }

        return $report;
    }

    private const QUALITY_KEYS = ['won_ltv', 'first_order_net', 'later_net', 'lifetime_net', 'refund_rate'];

    private static function withQuality(): bool
    {
        return (bool) apply_filters('fluent_cart/report/coupon_quality', false);
    }
}
