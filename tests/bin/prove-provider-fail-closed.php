<?php
/**
 * Intentional-red Phase 22 proof that unmatched provider HTTP fails the run.
 *
 * Expected result: exit 1 with "Provider transport rejected unmatched request".
 */

require_once dirname(__DIR__) . '/lib/harness.php';
require_once dirname(__DIR__) . '/lib/provider-harness.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();

FcTest::case('Provider transport rejects an unmatched outbound request', function () {
    $transport = new FcProviderHarness();
    $transport->install();

    wp_remote_post(
        'https://api.stripe.com/v1/phase22-deliberately-unmatched',
        [
            'body' => [
                'proof' => 'fail-closed',
            ],
        ]
    );
});

FcTest::finish('PROVIDER FAIL-CLOSED PROOF');
