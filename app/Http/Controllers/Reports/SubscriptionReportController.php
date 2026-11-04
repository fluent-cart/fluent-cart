<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Report\ReportHelper;
use FluentCart\App\Services\Report\SubscriptionReportService;
use FluentCart\App\Services\Report\InstallmentReportService;
use FluentCart\Framework\Support\Arr;

class SubscriptionReportController extends Controller
{
    protected $params = [
        'paymentStatus',
        'subscriptionType',
    ];

    public function getFutureRenewals(Request $request)
    {
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, ['startDate', 'endDate'], true);
        $params['horizon'] = sanitize_text_field((string) Arr::get($raw, 'horizon', ''));
        $params['asOf'] = Arr::get($raw, 'as_of');

        // Renewals by variant is a FluentCart Pro report
        $params['withVariations'] = (bool) apply_filters('fluent_cart/report/future_renewals_by_variation', false);
        $report = SubscriptionReportService::make()->getFutureRenewals($params);
        if (!$params['withVariations']) {
            unset($report['by_variation']);
        }

        return $report;
    }

    public function getInstallments(Request $request)
    {
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, [], true);

        $report = InstallmentReportService::make()->getReport([
            'currency'     => $params['currency'],
            'variationIds' => $params['variationIds'],
            'startedFrom'  => Arr::get($raw, 'started_from'),
            'startedTo'    => Arr::get($raw, 'started_to'),
            'asOf'         => Arr::get($raw, 'as_of'),
            'atRiskLimit'  => (int) Arr::get($raw, 'at_risk_limit', 0),
            'showCustomers' => ReportHelper::canSeeCustomers(),
        ]);
        // The monthly schedule, plans started by month and the collection rate are FluentCart Pro reports
        if (!apply_filters('fluent_cart/report/installment_forecast', false)) {
            unset($report['schedule'], $report['starts'], $report['summary']['collection_rate']);
        }

        return $report;
    }

    public function getInstallmentsAtRisk(Request $request)
    {
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, [], true);

        return InstallmentReportService::make()->getAtRisk([
            'currency'     => $params['currency'],
            'variationIds' => $params['variationIds'],
            'startedFrom'  => Arr::get($raw, 'started_from'),
            'startedTo'    => Arr::get($raw, 'started_to'),
            'search'       => sanitize_text_field((string) $request->get('search', '')),
            'bucket'       => sanitize_text_field((string) $request->get('bucket', '')),
            'page'         => (int) $request->get('page', 1),
            'perPage'      => (int) $request->get('per_page', 20),
            'showCustomers' => ReportHelper::canSeeCustomers(),
        ]);
    }

    public function getRetentionData(Request $request)
    {
        // MRR and churn convert each subscription at its own frozen rate
        $params = ReportHelper::processParams($request->get('params'), ['billingInterval', 'period'], true);

        $service = SubscriptionReportService::make();

        return [
            'retention_data' => $service->getRetentionData($params),
            'intervals'      => $service->retentionIntervals($params),
        ];
    }

    public function getOverview(Request $request): array
    {
        // Converted mode: each subscription and order at its own frozen rate, like the health cards
        $params = ReportHelper::processParams($request->get('params'), [], true);
        $compareRanges = ReportHelper::formatCompareRanges($params);
        $service = SubscriptionReportService::make();
        // Summary only (the Overview's churn card): the period figures, without the plan, interval and trial breakdowns
        $summaryOnly = !empty(Arr::get((array) $request->get('params', []), 'summary_only'));
        // MRR by plan and interval and trial conversion are a FluentCart Pro report
        $current = $service->getOverview($params, !$summaryOnly && apply_filters('fluent_cart/report/mrr_breakdown', false));

        $previous = null;
        $previousChart = [];
        $fluctuations = [];
        if ($params['comparePeriod']) {
            $params['startDate'] = $params['comparePeriod'][0];
            $params['endDate'] = $params['comparePeriod'][1];
            // The compare shows only the period figures and the chart
            $prior = $service->getOverview($params, false);
            $previous = $prior['period'];
            $previousChart = $prior['chart'];
            foreach (['new', 'churned', 'churn_rate', 'net_change', 'new_mrr', 'churned_mrr', 'net_mrr', 'renewals', 'renewal_amount', 'failed_renewals'] as $key) {
                $now = $current['period'][$key];
                $before = $previous[$key];
                // No rate in either period, a change from zero, or a net figure that crossed zero: no honest % to show
                if ($now === null || $before === null || (float) $before == 0.0 || ((float) $now < 0) !== ((float) $before < 0)) {
                    $fluctuations[$key] = null;
                    continue;
                }
                $fluctuations[$key] = ((float) $now - (float) $before) / abs((float) $before) * 100;
            }
        }

        return $current + [
            'previousPeriod' => $previous,
            'previousChart'  => $previousChart,
            'fluctuations'   => $fluctuations,
            'compare_range'  => ReportHelper::formatCompareRange($params['comparePeriod']),
            'compare_ranges' => $compareRanges,
        ];
    }

    public function getHealth(Request $request)
    {
        // MRR converts each subscription at its own frozen rate, like the Retention table
        $raw = (array) $request->get('params', []);
        $params = ReportHelper::processParams($raw, [], true);
        $health = function () use ($params) {
            return SubscriptionReportService::make()->getHealth($params);
        };

        // The Overview may take it from a short cache: it does not follow the date range. The Subscriptions page reads it live
        return [
            'health' => !empty($raw['cached'])
                ? ReportHelper::remember('health', [$params['currency'] ?? '', $params['variationIds'] ?? [], $raw['subscriptionType'] ?? ''], $health)
                : $health(),
        ];
    }

    public function getSubscriptionVariations(): array
    {
        return ['products' => SubscriptionReportService::make()->subscriptionVariations()];
    }

    public function getCohortData(Request $request)
    {
        // MRR cohorts, the billing-interval filter and counting rebuys as retained are FluentCart Pro reports
        return SubscriptionReportService::make()->cohortReport((array) $request->get('params', []), (bool) apply_filters('fluent_cart/report/cohort_options', false));
    }

}
