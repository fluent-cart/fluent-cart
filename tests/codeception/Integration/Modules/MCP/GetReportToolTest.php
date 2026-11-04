<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\App;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Services\Report\SubscriptionReportService;
use Tests\Support\DatabaseTestCase;

/**
 * get-report serves the fixed-shape report pages: installments, retention and cohorts.
 */
class GetReportToolTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/report/cohort_options');
        parent::tearDown();
    }

    public function test_each_report_is_served(): void
    {
        $installments = ReportTools::getReport(['report' => 'installments']);
        $this->assertArrayHasKey('summary', $installments['data']);

        $retention = ReportTools::getReport(['report' => 'retention', 'range' => 'last_year']);
        $this->assertArrayHasKey('retention_data', $retention['data']);

        $cohorts = ReportTools::getReport(['report' => 'cohorts', 'metric' => 'mrr']);
        $data = $cohorts['data'];
        unset($data['report']);
        // The service's report, keeping the latest 24 cohorts
        $admin = SubscriptionReportService::make()->cohortReport(['groupBy' => 'year', 'metric' => 'mrr'], false);
        $admin['cohorts_total'] = count($admin['cohorts']);
        $admin['cohorts'] = array_slice($admin['cohorts'], -24);
        // Each period keeps the figures the others derive from
        $keep = array_flip(['offset', 'retained_mrr', 'retained_count', 'retention_rate_mrr', 'retention_rate_count', 'partial']);
        foreach ($admin['cohorts'] as &$cohort) {
            $cohort['periods'] = array_map(function ($period) use ($keep) {
                return array_intersect_key($period, $keep);
            }, $cohort['periods']);
        }
        unset($cohort);
        $this->assertSame($admin, $data);
        $this->assertSame('subscribers', $cohorts['data']['metric'], 'MRR cohorts are a Pro report');

        add_filter('fluent_cart/report/cohort_options', '__return_true');
        $this->assertSame('mrr', ReportTools::getReport(['report' => 'cohorts', 'metric' => 'mrr'])['data']['metric']);
    }

    public function test_unknown_report_is_an_error(): void
    {
        $res = ReportTools::getReport(['report' => 'nope']);
        $this->assertTrue(is_wp_error($res));
    }

    public function test_long_retention_keeps_the_latest_24_periods(): void
    {
        $res = ReportTools::getReport(['report' => 'retention', 'start_date' => '2021-01-01', 'end_date' => '2023-12-31'])['data'];

        $this->assertCount(24, $res['retention_data']);
        $this->assertSame(36, $res['periods_total']);
    }

    public function test_more_than_24_cohorts_stay_a_list_of_the_latest(): void
    {
        App::db()->table('fct_retention_snapshots')->delete();
        // 30 yearly cohorts (the service caps monthly ones at 24 itself), each with its signup month
        $first = (int) gmdate('Y') - 29;
        for ($year = $first; $year < $first + 30; $year++) {
            App::db()->table('fct_retention_snapshots')->insert([
                'cohort' => $year . '-01', 'period' => $year . '-01', 'product_id' => null, 'currency' => MCPHelper::currencyCode(),
                'period_offset' => 0, 'cohort_customers' => 10, 'cohort_mrr' => 10000, 'retained_customers' => 10, 'retained_mrr' => 10000,
            ]);
        }

        $data = ReportTools::getReport(['report' => 'cohorts', 'group_by' => 'year'])['data'];

        $this->assertSame(30, $data['cohorts_total']);
        $this->assertSame(range(0, 23), array_keys($data['cohorts']), 'A list, so it encodes as a JSON array');
        $this->assertStringStartsWith('[', wp_json_encode($data['cohorts']));
        $this->assertSame((string) ($first + 29), (string) end($data['cohorts'])['cohort'], 'The latest cohorts are kept');
    }
}
