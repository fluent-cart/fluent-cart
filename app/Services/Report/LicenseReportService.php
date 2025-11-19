<?php

namespace FluentCart\App\Services\Report;

use DateInterval;
use DatePeriod;
use FluentCart\App\App;
use FluentCart\App\Services\Report\Concerns\CanParseAddressField;
use FluentCart\App\Services\Report\Concerns\HasRange;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\DateTime;
use FluentCartPro\App\Modules\Licensing\Models\License;

class LicenseReportService extends ReportService
{
    use HasRange, CanParseAddressField;

    protected $summary = [];

    protected $forcedGroupKey = [];

    protected function modifyQuery(Builder $query): Builder
    {
        return $query->with('product')->orderBy('created_at');
    }

    public function getModel(): string
    {
        if (!class_exists(License::class)) {
            throw new \Exception('The required plugin is not installed or the License class is missing.');
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

        $startDate = new DateTime($startDate);
        $endDate = new DateTime($endDate);

        $this->forcedGroupKey = $groupKey;

        if (!$this->forcedGroupKey || $this->forcedGroupKey === 'default') {
            $groupKey = ReportHelper::defineGroupKey($startDate, $endDate);
        }

        switch ($groupKey) {
            case 'yearly':
                $dateFormat = "YEAR(created_at) AS year";
                $groupBy = "YEAR(created_at)";
                break;
            case 'monthly':
                $dateFormat = "YEAR(created_at) AS year, MONTH(created_at) AS month";
                $groupBy = "YEAR(created_at), MONTH(created_at)";
                break;
            default:
                $dateFormat = "DATE(created_at) AS date";
                $groupBy = "DATE(created_at)";
                break;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Query is prepared with date placeholders
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT %s, COUNT(*) AS license_count FROM %s WHERE created_at BETWEEN %s AND %s GROUP BY %s ORDER BY %s",
                $dateFormat,
                "{$wpdb->prefix}fct_licenses",
                $startDate->format('Y-m-d 00:00:00'),
                $endDate->format('Y-m-d 23:59:59'),
                $groupBy,
                $groupBy
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

    public function getSummary($startDate, $endDate): array
    {

        $this->summary = [
            'totalLicense'          => App::db()->table('fct_licenses')->count(),
            'totalActiveLicense'    => App::db()->table('fct_licenses')->where('status', 'active')->count(),
            'totalInactiveLicense'  => App::db()->table('fct_licenses')->where('status', 'disabled')->count(),
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
