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
        'id'            => 'reports-retention-status-missing-job',
        'name'          => 'Retention snapshot status returns exact inert missing-job values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $jobId = '__fc_phase7_missing_job__';
            FcTest::assertSame(
                false,
                (bool) get_option('fluent_cart_snapshot_job_' . $jobId, false),
                'retention status proof job option is absent before the read-only request'
            );

            $result = FcTest::rest(
                'GET',
                'reports/retention-snapshots/status',
                ['params' => ['job_id' => $jobId]]
            );
            FcTest::assertHealthy($result, 'GET /reports/retention-snapshots/status');
            FcTest::assertSame(
                false,
                (bool) $result['data']['success'],
                'GET /reports/retention-snapshots/status returns success=false for exact missing job'
            );
            FcTest::assertSame(
                'Job not found',
                (string) $result['data']['message'],
                'GET /reports/retention-snapshots/status returns exact missing-job message'
            );
            FcTest::assertSame(
                $jobId,
                (string) $result['data']['job_id'],
                'GET /reports/retention-snapshots/status echoes the exact inert job ID'
            );
            FcTest::assertSame(
                false,
                (bool) get_option('fluent_cart_snapshot_job_' . $jobId, false),
                'retention status request does not create a snapshot job option'
            );
        },
    ],
];
