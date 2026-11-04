<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
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

    public function channels(Request $request): array
    {
        // The page's filters (sale type, advanced filters) apply here as on the table below
        $params = ReportHelper::processParams((array) $request->get('params', []), $this->params, true);

        return SourceReportService::make()->getChannels($params);
    }

    public function index(Request $request): array
    {
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, $this->params, true);
        $params['group_by'] = SourceReportService::sanitizeGroupBy(Arr::get($raw, 'group_by'));
        // Grouping by product, variation, time or referrer is a FluentCart Pro report; the UTM groupings are free
        if (in_array($params['group_by'], SourceReportService::OTHER_GROUPS, true) && !apply_filters('fluent_cart/report/source_groupings', false)) {
            $params['group_by'] = SourceReportService::DEFAULT_GROUP;
        }
        $params['include_none'] = in_array(Arr::get($raw, 'include_none'), [true, 1, '1', 'true', 'yes'], true);
        $compareRanges = ReportHelper::formatCompareRanges($params);

        $service = SourceReportService::make();

        $report = $service->getReport($params);
        $currentMetrics = $report['rows'];
        $allRows = $currentMetrics;

        // With a page, one page of rows goes back; without one, every matching row (the export)
        $pagination = null;
        if (isset($raw['page'])) {
            $page = max(1, absint($raw['page']));
            $perPage = min(150, max(10, absint(Arr::get($raw, 'per_page', 50))));
            $paged = $service->pageRows($currentMetrics, [
                'page'       => $page,
                'per_page'   => $perPage,
                'search'     => sanitize_text_field((string) Arr::get($raw, 'search', '')),
                'sort_by'    => sanitize_key((string) Arr::get($raw, 'sort_by', '')),
                'sort_order' => Arr::get($raw, 'sort_order') === 'asc' ? 'asc' : 'desc',
            ]);
            $currentMetrics = $paged['rows'];
            $pagination = ['total' => $paged['total'], 'current_page' => $page, 'per_page' => $perPage,
                'last_page' => (int) ceil($paged['total'] / $perPage)];
        } else {
            // The export: every matching row, so a file after a search holds what the table shows
            $currentMetrics = $service->searchRows($currentMetrics, sanitize_text_field((string) Arr::get($raw, 'search', '')));
        }

        $fluctuations = [];

        if ($params['comparePeriod']) {
            // Only the date window moves — every other param (advanced filters
            // included) is reused, so the comparison period is measured over the
            // same segment of orders and the fluctuations compare like with like.
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];

            // Rows as arrays with the same grouping as the current period; getSourceReportData() returns objects
            $previousMetrics = $service->getReport($params)['rows'];

            $fluctuations = $service->calculateFluctuations(
                $currentMetrics, $previousMetrics
            );
        }

        $response = [
            'sourceReportData' => $currentMetrics,
            'totals'           => $report['totals'],
            'group_by'         => $report['group_by'],
            'fluctuations'     => $fluctuations,
            'compare_range'    => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges'   => $compareRanges,
        ];
        if ($pagination) {
            $response['pagination'] = $pagination;
            // A period grouping draws a chart of every period, not one page
            if (in_array($report['group_by'], ['day', 'week', 'month'], true)) {
                $response['chart'] = $allRows;
            }
        }

        return $response;
    }
}
