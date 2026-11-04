<?php
/**
 * Phase 7 exact-value coverage for global recent Order and Activity lists.
 */

return [
    [
        'id'            => 'reports-recent-orders',
        'name'          => 'Recent Orders returns the exact newest owned row and values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $rows = \FluentCart\App\Services\Report\DashBoardReportService::getRecentOrders();
                $recent = $rows['recentOrders'];

                FcTest::assert(
                    isset($recent[0]),
                    'GET /reports/get-recent-orders returns a first list row'
                );
                FcTest::assertSame(
                    (int) $data['orders']['future_decoy']->id,
                    (int) $recent[0]['id'],
                    'GET /reports/get-recent-orders returns the exact newest owned Order ID'
                );
                FcTest::assertSame(
                    (int) $data['customer']->id,
                    (int) $recent[0]['customer_id'],
                    'GET /reports/get-recent-orders preserves the exact Customer ID'
                );
                FcTest::assertSame(
                    'Phase Seven Reports',
                    (string) $recent[0]['customer_name'],
                    'GET /reports/get-recent-orders preserves the exact customer name'
                );
                FcTest::assertSame(
                    999.99,
                    (float) $recent[0]['total_amount'],
                    'GET /reports/get-recent-orders returns the exact newest total 999.99'
                );
                FcTest::assertSame(
                    1,
                    (int) $recent[0]['order_items_count'],
                    'GET /reports/get-recent-orders returns the exact newest item-row count'
                );
                FcTest::assertSame(
                    $data['window']['future'],
                    (string) $recent[0]['created_at'],
                    'GET /reports/get-recent-orders preserves the exact newest timestamp'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-recent-activities',
        'name'          => 'Recent Activities variants return the exact newest owned row and values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                foreach (['all', 'daily'] as $groupKey) {
                    $rows = \FluentCart\App\Services\Report\DashBoardReportService::getRecentActivities(
                        $groupKey
                    );
                    $recent = $rows['recentActivities'];

                    FcTest::assert(
                        isset($recent[0]),
                        'GET /reports/get-recent-activities ' . $groupKey
                        . ' variation returns a first list row'
                    );
                    FcTest::assertSame(
                        (int) $data['orders']['future_decoy']->id,
                        (int) $recent[0]['module_id'],
                        'GET /reports/get-recent-activities ' . $groupKey
                        . ' returns the exact newest owned module ID'
                    );
                    FcTest::assertSame(
                        FcFixture::sharedValue('report-recent-activity'),
                        (string) $recent[0]['title'],
                        'GET /reports/get-recent-activities ' . $groupKey
                        . ' returns the exact owned Activity title'
                    );
                    FcTest::assertSame(
                        FcFixture::sharedValue('report-recent-activity'),
                        (string) $recent[0]['content'],
                        'GET /reports/get-recent-activities ' . $groupKey
                        . ' returns the exact owned Activity content'
                    );
                    FcTest::assertSame(
                        '2099-01-02T14:00:00+00:00',
                        (string) $recent[0]['created_at'],
                        'GET /reports/get-recent-activities ' . $groupKey
                        . ' preserves the exact newest timestamp'
                    );
                }
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
];
