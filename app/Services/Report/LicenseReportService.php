<?php

namespace FluentCart\App\Services\Report;

use DateInterval;
use DatePeriod;
use FluentCart\App\App;
use FluentCart\App\Services\Report\Concerns\HasRange;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCartPro\App\Modules\Licensing\Models\License;

class LicenseReportService extends ReportService
{
    use HasRange;

    protected $summary = [];

    protected $forcedGroupKey = [];

    protected function modifyQuery(Builder $query): Builder
    {
        return $query->with('product')->orderBy('created_at');
    }

    public function getModel(): string
    {
        if (!class_exists(License::class)) {
            throw new \Exception(esc_html__('The required plugin is not installed or the License class is missing.', 'fluent-cart'));
        }

        return License::class;
    }

    protected function prepareReportData(): void
    {
        $this->data->each(function ($item) {

        });
    }

    public function getLicenseLineChart($groupKey, $startDate, $endDate)
    {
        global $wpdb;

        // Store days, like every report: labels in the site timezone, bounds as UTC for the GMT column
        $startDate = ReportHelper::siteDay($startDate)->startOfDay();
        $endDate = ReportHelper::siteDay($endDate)->endOfDay();
        $local = ReportHelper::storeTimeSql('created_at', $startDate, $endDate);

        $this->forcedGroupKey = $groupKey;

        if (!$this->forcedGroupKey || $this->forcedGroupKey === 'default') {
            $groupKey = ReportHelper::defineGroupKey($startDate, $endDate);
        }

        switch ($groupKey) {
            case 'yearly':
                $dateFormat = "YEAR({$local}) AS year";
                $groupBy = "YEAR({$local})";
                break;
            case 'monthly':
                $dateFormat = "YEAR({$local}) AS year, MONTH({$local}) AS month";
                $groupBy = "YEAR({$local}), MONTH({$local})";
                break;
            default:
                $dateFormat = "DATE({$local}) AS date";
                $groupBy = "DATE({$local})";
                break;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$dateFormat}, COUNT(*) AS license_count FROM {$wpdb->prefix}fct_licenses WHERE created_at BETWEEN %s AND %s GROUP BY {$groupBy} ORDER BY {$groupBy}",
                ReportHelper::toGmt($startDate)->format('Y-m-d H:i:s'),
                ReportHelper::toGmt($endDate)->format('Y-m-d H:i:s')
            )
        );

        $lineChartData = [];

        if ($groupKey === 'yearly') {
            $yearMap = [];
            foreach ($results as $row) {
                $yearMap[$row->year] = (int)$row->license_count;
            }

            for ($y = (int)$startDate->format('Y'); $y <= (int)$endDate->format('Y'); $y++) {
                $lineChartData[] = [
                    'year'          => $y,
                    'license_count' => isset($yearMap[$y]) ? $yearMap[$y] : 0,
                ];
            }
        } elseif ($groupKey === 'monthly') {
            $monthMap = [];
            foreach ($results as $row) {
                $key = "{$row->year}-{$row->month}";
                $monthMap[$key] = (int)$row->license_count;
            }

            $period = new DatePeriod($startDate, new DateInterval('P1M'), (clone $endDate)->modify('first day of next month'));
            foreach ($period as $date) {
                $year = $date->format('Y');
                $month = $date->format('n');
                $key = "{$year}-{$month}";
                $lineChartData[] = [
                    'year'          => (int)$year,
                    'month'         => (int)$month,
                    'license_count' => isset($monthMap[$key]) ? $monthMap[$key] : 0,
                ];
            }
        } else {
            $dateMap = [];
            foreach ($results as $row) {
                $dateMap[$row->date] = (int)$row->license_count;
            }

            $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
            foreach ($period as $date) {
                $formatted = $date->format('Y-m-d');
                $lineChartData[] = [
                    'date'          => $formatted,
                    'license_count' => isset($dateMap[$formatted]) ? $dateMap[$formatted] : 0,
                ];
            }
        }

        return [
            'lineChartData' => $lineChartData,
        ];
    }

    public function getLicensePieChart($startDate, $endDate): array
    {

        $totalSubquery = App::db()->table('fct_licenses')
            ->selectRaw('COUNT(*) AS total_activation_count')
            ->where('status', '=', 'active');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Static query with no variables
        $results = App::db()->table('fct_licenses as l')
            ->selectRaw('
        l.product_id,
        COALESCE(p.post_title, ?) AS post_title,
        COUNT(*) AS activation_count,
        ROUND(COUNT(*) * 100.0 / total.total_activation_count, 2) AS percentage
    ', [__('Unknown Product', 'fluent-cart')])
            ->leftJoin('posts as p', 'p.ID', '=', 'l.product_id')
            ->crossJoinSub($totalSubquery, 'total')
            ->where('l.status', '=', 'active')
            ->groupByRaw('l.product_id, p.post_title, total.total_activation_count')
            ->orderByDesc('activation_count')
            ->get()
            ->toArray();

        return [
            'pieChartData' => $results
        ];

    }

    /**
     * Licenses by their current status. A status is a snapshot, so the date range does not apply.
     */
    public function getSummary($startDate, $endDate): array
    {

        $this->summary = [
            'totalLicense'          => App::db()->table('fct_licenses')->count(),
            'totalActiveLicense'    => App::db()->table('fct_licenses')->where('status', 'active')->count(),
            'totalInactiveLicense'  => App::db()->table('fct_licenses')->where('status', 'inactive')->count(),
            'totalDisabledLicense'  => App::db()->table('fct_licenses')->where('status', 'disabled')->count(),
            'totalExpiredLicense'   => App::db()->table('fct_licenses')->where('status', 'expired')->count(),
            'totalActivatedSites'   => App::db()->table('fct_licenses')->sum('activation_count'),
            'totalLicensedProducts' => App::db()->table('fct_product_meta')
                ->where('meta_key', 'license_settings')
                ->count('object_id'),
        ];
        return [
            'summaryData' => $this->summary
        ];
    }


}
