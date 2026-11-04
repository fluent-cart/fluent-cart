<?php
/**
 * A monthly cohort's cell for the store's current month is still running: the cohort data marks it partial, so the
 * admin table and the MCP cohorts report both show it as not final.
 */

use FluentCart\App\Services\Report\SubscriptionReportService;

return [
    [
        'id'            => 'reports-cohort-running-month',
        'name'          => 'Monthly cohorts mark the current month partial',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            // A cohort that started two store months ago: offsets 1 and 2, the second is this month
            $now = new DateTime('now', wp_timezone());
            $cohort = (clone $now)->modify('first day of -2 months')->format('Y-m');
            $snap = function ($offset) {
                return (object) [
                    'period_offset' => $offset, 'cohort_mrr' => 10000, 'cohort_customers' => 10, 'retained_mrr' => 9000,
                    'retained_customers' => 9, 'churned_customers' => 1, 'retention_rate_mrr' => 90, 'retention_rate_customers' => 90,
                ];
            };
            $service = new class extends SubscriptionReportService {
                public function build(array $groups)
                {
                    return $this->buildCohortResponseFromGroups($groups, 'month', 'subscribers', 12);
                }
            };
            $periods = $service->build([$cohort => [$snap(0), $snap(1), $snap(2)]])['cohorts'][0]['periods'] ?? [];
            $byOffset = array_column($periods, null, 'offset');

            FcTest::assertSame(
                [false, true],
                [!empty($byOffset[1]['partial']), !empty($byOffset[2]['partial'])],
                'Last month is final; this month is still running'
            );
        },
    ],
];
