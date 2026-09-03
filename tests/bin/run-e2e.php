<?php
/**
 * One-process runner for a single Phase 27 E2E money flow.
 *
 * The full-tier shell wrapper invokes all three flows in fresh WordPress
 * processes under one identity and one outer protected-count comparison.
 */

require_once dirname(__DIR__) . '/lib/harness.php';
require_once dirname(__DIR__) . '/lib/fixtures.php';
require_once dirname(__DIR__) . '/lib/domain-fixtures.php';
require_once dirname(__DIR__) . '/lib/automation-fixtures.php';
require_once dirname(__DIR__) . '/lib/provider-harness.php';
require_once dirname(__DIR__) . '/lib/e2e-money-harness.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();
FcTest::clearCaches();

$identity = getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY');
if ($identity === false || trim((string) $identity) === '') {
    WP_CLI::error('Phase 27 requires an explicit full-tier fixture identity.');
}

try {
    FcFixture::initialize((string) $identity);
} catch (\Throwable $e) {
    WP_CLI::error('Phase 27 fixture identity is invalid: ' . $e->getMessage());
}

$flowId = '';
foreach (isset($args) && is_array($args) ? $args : [] as $argument) {
    if (strpos($argument, 'flow=') === 0) {
        $flowId = trim(substr($argument, strlen('flow=')));
    }
}
if ($flowId === '') {
    WP_CLI::error('Phase 27 single-process runner requires flow=<id>.');
}

$phase27Evidence = [];
$definitions = require dirname(__DIR__) . '/e2e/money-flows.php';
if (!is_array($definitions) || count($definitions) !== 3) {
    WP_CLI::error('Phase 27 must define exactly three E2E money flows.');
}

$flows = [];
foreach ($definitions as $definition) {
    if (
        !is_array($definition)
        || empty($definition['id'])
        || empty($definition['name'])
        || empty($definition['run'])
        || !is_callable($definition['run'])
    ) {
        WP_CLI::error('Phase 27 contains an invalid E2E flow definition.');
    }
    $flows[$definition['id']] = $definition;
}
if (!isset($flows[$flowId])) {
    WP_CLI::error('Unknown Phase 27 E2E flow: ' . $flowId);
}

$initialResidue = [
    'fixture'    => FcFixture::residueCounts((string) $identity),
    'shared'     => FcFixture::sharedMarkerResidueCounts((string) $identity),
    'domain'     => FcDomainFixture::markerResidueCounts(),
    'automation' => FcAutomationFixture::markerResidueCounts(),
];
foreach ($initialResidue as $scope => $counts) {
    if (array_sum($counts) !== 0) {
        WP_CLI::error(
            'Phase 27 found pre-existing ' . $scope . ' residue: '
            . wp_json_encode($counts)
        );
    }
}

register_shutdown_function(function () {
    try {
        FcDomainFixture::cleanupAll();
        FcAutomationFixture::cleanupAll();
    } catch (\Throwable $e) {
        WP_CLI::warning('Phase 27 shutdown cleanup failed: ' . $e->getMessage());
    }
});

$flow = $flows[$flowId];
$providerRequestCount = null;
FcTest::case($flow['name'], function () use (
    $flow,
    &$providerRequestCount
) {
    FcTest::assertMailAndLoopbackInterceptorsActive();
    $provider = new FcProviderHarness();
    $provider->install();
    try {
        call_user_func($flow['run']);
        $provider->assertComplete();
    } finally {
        $providerRequestCount = count($provider->requests());
        $provider->uninstall();
    }
    FcTest::assertSame(0, $providerRequestCount, 'provider boundary receives no gateway request');
});

$cleanupFailure = null;
try {
    FcDomainFixture::cleanupAll();
    FcDomainFixture::cleanupAll();
    FcAutomationFixture::cleanupAll();
    FcAutomationFixture::cleanupAll();
} catch (\Throwable $e) {
    $cleanupFailure = $e;
}
if ($cleanupFailure !== null) {
    FcTest::case('Phase 27 outer cleanup remains exact and idempotent', function () use ($cleanupFailure) {
        throw $cleanupFailure;
    });
}

$finalResidue = [
    'fixture'    => FcFixture::residueCounts((string) $identity),
    'shared'     => FcFixture::sharedResidueCounts(),
    'domain'     => FcDomainFixture::residueCounts(),
    'automation' => FcAutomationFixture::residueCounts(),
];
foreach ($finalResidue as $scope => $counts) {
    if (array_sum($counts) !== 0) {
        WP_CLI::error(
            'Phase 27 left exact ' . $scope . ' residue: '
            . wp_json_encode($counts)
        );
    }
}

$safety = [
    'outbound_http'    => count(FcTest::externalCalls()),
    'mail'             => count(FcTest::sentMails()),
    'cron'             => count(FcTest::cronAttempts()),
    'action_scheduler' => count(FcTest::actionSchedulerAttempts()),
    'provider_requests'=> $providerRequestCount === null ? -1 : (int) $providerRequestCount,
];
if (array_filter($safety, function ($count) {
    return (int) $count !== 0;
})) {
    WP_CLI::error('Phase 27 safety guard count is non-zero: ' . wp_json_encode($safety));
}

WP_CLI::log(
    'Phase 27 flow evidence: id=' . $flowId
    . ' values=' . wp_json_encode($phase27Evidence)
);
WP_CLI::log(
    'safety guards: outbound_http=0 mail=0 cron=0 action_scheduler=0 provider_requests=0'
);
WP_CLI::log(
    'exact residue: fixture=0 shared=0 domain=0 automation=0'
);

FcTest::finish('E2E MONEY FLOW');
