<?php
/**
 * External exact-identity residue assertion for integration runner boundaries.
 */

require_once dirname(__DIR__) . '/lib/fixtures.php';
require_once dirname(__DIR__) . '/lib/domain-fixtures.php';

$identity = getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY');
if ($identity === false || $identity === '') {
    WP_CLI::error('WP_PLUGIN_TEST_FIXTURE_IDENTITY is required for the residue check.');
}

FcFixture::initialize($identity);
$counts = FcFixture::residueCounts($identity);
$sharedCounts = FcFixture::sharedMarkerResidueCounts($identity);
$reportCounts = FcFixture::reportMarkerResidueCounts($identity);
$domainCounts = FcDomainFixture::markerResidueCounts();
WP_CLI::log(
    'fixture residue: identity=' . $identity
    . ' customer=' . $counts['customer']
    . ' order=' . $counts['order']
    . ' shared=' . wp_json_encode($sharedCounts)
    . ' report=' . wp_json_encode($reportCounts)
    . ' domain=' . wp_json_encode($domainCounts)
);

if (
    $counts !== ['customer' => 0, 'order' => 0]
    || array_sum($sharedCounts) !== 0
    || array_sum($reportCounts) !== 0
    || array_sum($domainCounts) !== 0
) {
    WP_CLI::error('Exact Customer/Order/shared/report/domain fixture residue is not zero.');
}
