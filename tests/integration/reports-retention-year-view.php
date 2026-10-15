<?php
/**
 * Cohort year view: a year's Start counts every monthly cohort of that year, and a
 * Year-k cell that only some cohorts have reached divides by those cohorts alone.
 */

use FluentCart\App\App;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Report\SubscriptionReportService;

$fcRetentionYearCurrency = 'XTS';

return [
    [
        'id'            => 'reports-retention-year-view',
        'name'          => 'Cohort year view sizes Start from every monthly cohort and marks partially matured years',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($fcRetentionYearCurrency) {
            $db = App::db();
            $foreign = (int) $db->table('fct_retention_snapshots')->where('currency', $fcRetentionYearCurrency)->count();
            if ($foreign !== 0) {
                throw new RuntimeException(
                    'Refusing to seed: ' . $foreign . ' existing retention snapshots already use currency ' . $fcRetentionYearCurrency
                );
            }

            try {
                $now = gmdate('Y-m-d H:i:s');
                $totalSize = 0;
                $maturedYearOneSize = 0;
                $maturedYearOneRetained = 0;

                for ($month = 1; $month <= 12; $month++) {
                    $cohort = sprintf('2001-%02d', $month);
                    $size = 10 + $month * 3;
                    $totalSize += $size;

                    // Offset 12 reached by Jan-Oct, offset 24 by Jan-Jun only.
                    $offsets = [0 => $size];
                    if ($month <= 10) {
                        $offsets[12] = $size - $month;
                    }
                    if ($month <= 6) {
                        $offsets[24] = $size - $month - 2 - ($month % 3);
                        $maturedYearOneSize += $size;
                        $maturedYearOneRetained += $offsets[24];
                    }

                    foreach ($offsets as $offset => $retained) {
                        $db->table('fct_retention_snapshots')->insert([
                            'cohort'             => $cohort,
                            'period'             => gmdate('Y-m', strtotime($cohort . '-01 +' . $offset . ' months')),
                            'product_id'         => null,
                            'currency'           => $fcRetentionYearCurrency,
                            'cohort_customers'   => $size,
                            'cohort_mrr'         => $size * 1000,
                            'retained_customers' => $retained,
                            'retained_mrr'       => $retained * 1000,
                            'churned_customers'  => $size - $retained,
                            'period_offset'      => $offset,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);
                    }
                }

                $result = SubscriptionReportService::make()->getCohortData([
                    'startDate'  => new DateTime('2001-01-01 00:00:00'),
                    'endDate'    => new DateTime('2001-12-31 23:59:59'),
                    'groupBy'    => 'year',
                    'currency'   => $fcRetentionYearCurrency,
                    'maxPeriods' => 2,
                ]);

                FcTest::assertSame(1, count($result['cohorts']), 'GET /reports/subscription-cohorts year view yields one 2001 row');
                $year = $result['cohorts'][0];

                FcTest::assertSame(
                    $totalSize,
                    (int) $year['start_count'],
                    'GET /reports/subscription-cohorts year Start sums the offset-0 size of all twelve monthly cohorts'
                );

                $yearOne = $year['periods'][0];
                FcTest::assertSame(true, !empty($yearOne['partial']), 'GET /reports/subscription-cohorts Year 1 reached by six of twelve cohorts is partial');
                FcTest::assertSame(
                    round(($maturedYearOneRetained / $maturedYearOneSize) * 100, 2),
                    (float) $yearOne['retention_rate_previous_count'],
                    'GET /reports/subscription-cohorts Year 1 % of previous divides by the matured cohorts\' size only'
                );
            } finally {
                $db->table('fct_retention_snapshots')->where('currency', $fcRetentionYearCurrency)->delete();
            }
        },
    ],
];
