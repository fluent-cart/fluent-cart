<?php
/**
 * A canceled plan keeps its paid term in Cohorts. A plan with a custom billing schedule (every 4 months, as plans
 * migrated from WooCommerce store it) keeps the whole 4 months, not one month for its "monthly" slug.
 */

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\RetentionSnapshotService;

return [
    [
        'id'            => 'reports-cohort-billing-schedule',
        'name'          => 'A canceled plan with a custom billing schedule stays retained for its whole paid term',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC',
        'run'           => function () {
            $service = new class extends RetentionSnapshotService {
                public function endOf($sub)
                {
                    return $this->getSubscriptionEndDate($sub);
                }
            };
            $plan = function (array $config) {
                return (object) [
                    'status' => Status::SUBSCRIPTION_CANCELED, 'billing_interval' => 'monthly', 'parent_order_id' => 0,
                    'created_at' => '2025-01-15 10:00:00', 'canceled_at' => '2025-01-20 10:00:00', 'expire_at' => null,
                    'updated_at' => '2025-01-20 10:00:00', 'next_billing_date' => null, 'trial_days' => 0, 'config' => wp_json_encode($config),
                ];
            };

            FcTest::assertSame(
                ['2025-05-15', '2025-02-15'],
                [
                    $service->endOf($plan(['billing_schedule' => ['period' => 'month', 'interval' => 4]]))->format('Y-m-d'),
                    $service->endOf($plan([]))->format('Y-m-d'),
                ],
                'Paid on 15 January every 4 months: retained until 15 May; a plain monthly plan until 15 February'
            );
        },
    ],
];
