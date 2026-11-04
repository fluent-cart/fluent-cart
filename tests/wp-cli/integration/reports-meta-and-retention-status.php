<?php
/**
 * Phase 7 exact-value coverage for report metadata and inert snapshot polling.
 */

return [
    [
        'id'            => 'reports-filter-meta',
        'name'          => 'Report metadata returns the exact filtered minimum date',
        'kind'          => 'behavior',
        'known_failure' => false,
        'site_timezone' => 'UTC', // fixtures are UTC days; reports-site-timezone covers other zones
        'run'           => function () {
            try {
                $data = FcReportFixture::createDataset();
                $result = FcTest::rest(
                    'GET',
                    'reports/fetch-report-meta',
                    ['params' => FcReportFixture::requestParams()]
                );
                FcTest::assertHealthy($result, 'GET /reports/fetch-report-meta');
                FcTest::assertSame(
                    '2001-02-04 10:00:00',
                    (string) $result['data']['min_date'],
                    'GET /reports/fetch-report-meta returns exact filtered min_date'
                );
                FcTest::assertSame(
                    (string) $data['orders']['paid_a']->created_at,
                    (string) $result['data']['min_date'],
                    'GET /reports/fetch-report-meta min_date belongs to the first owned Order'
                );
                FcTest::assert(
                    isset($result['data']['currencies']['USD']),
                    'GET /reports/fetch-report-meta returns the configured USD currency value'
                );
            } finally {
                FcFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'reports-retention-step-without-build',
        'name'          => 'A snapshot build step with no build started is refused and changes nothing',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            global $wpdb;
            $lock = \FluentCart\App\Services\Report\RetentionSnapshotService::LOCK_OPTION;
            if (get_option($lock) !== false) {
                throw new RuntimeException('Refusing to run: a snapshot build is in progress on this store');
            }
            $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_retention_snapshots");

            $result = FcTest::rest('POST', 'reports/retention-snapshots/step');
            FcTest::assertHealthy($result, 'POST /reports/retention-snapshots/step');
            FcTest::assertSame([false, 'no_build'], [(bool) $result['data']['success'], (string) $result['data']['code']],
                'POST /reports/retention-snapshots/step without a started build answers no_build');
            FcTest::assertSame([false, $before], [get_option($lock), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_retention_snapshots")],
                'the refused step takes no lock and leaves the snapshots as they were');
        },
    ],
];
