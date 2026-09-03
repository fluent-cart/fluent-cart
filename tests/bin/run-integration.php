<?php
/**
 * Real WordPress/MySQL/FluentCart integration runner through Phase 30.
 *
 * Test files return auditable case definitions. Every case uses the real model
 * and query stack, while the fixture helper owns/deletes only immediately
 * captured primary IDs with an exact identity check.
 */

require_once dirname(__DIR__) . '/lib/harness.php';
require_once dirname(__DIR__) . '/lib/fixtures.php';
require_once dirname(__DIR__) . '/lib/report-fixtures.php';
require_once dirname(__DIR__) . '/lib/domain-fixtures.php';
require_once dirname(__DIR__) . '/lib/crud-fixtures.php';
require_once dirname(__DIR__) . '/lib/automation-fixtures.php';
require_once dirname(__DIR__) . '/lib/public-surface-fixtures.php';
require_once dirname(__DIR__) . '/lib/throughput-fixtures.php';
require_once dirname(__DIR__) . '/lib/provider-harness.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();
$cleared = FcTest::clearCaches();

try {
    $identity = FcFixture::initialize(
        getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY') !== false
            ? getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY')
            : null
    );
} catch (\Throwable $e) {
    WP_CLI::error('Integration fixture identity is invalid: ' . $e->getMessage());
}
$startedAt = microtime(true);
$baseline = FcFixture::protectedCounts();
$initialResidue = FcFixture::residueCounts($identity);
if ($initialResidue !== ['customer' => 0, 'order' => 0]) {
    WP_CLI::error(
        'Integration fixture identity already exists before the case: '
        . $identity . ' residue=' . wp_json_encode($initialResidue)
    );
}
$initialSharedResidue = FcFixture::sharedMarkerResidueCounts($identity);
$initialReportResidue = FcFixture::reportMarkerResidueCounts($identity);
$initialDomainResidue = FcDomainFixture::markerResidueCounts();
$initialCrudResidue = FcCrudFixture::markerResidueCounts();
$initialAutomationResidue = FcAutomationFixture::markerResidueCounts();
$initialPublicResidue = FcPublicSurfaceFixture::markerResidueCounts();
$initialThroughputResidue = FcThroughputFixture::markerResidueCounts();
if (
    array_sum($initialSharedResidue) !== 0
    || array_sum($initialReportResidue) !== 0
    || array_sum($initialDomainResidue) !== 0
    || array_sum($initialCrudResidue) !== 0
    || array_sum($initialAutomationResidue) !== 0
    || array_sum($initialPublicResidue) !== 0
    || array_sum($initialThroughputResidue) !== 0
) {
    WP_CLI::error(
        'Integration shared/report/domain/CRUD/automation/public/throughput marker already exists before the case: '
        . $identity
        . ' shared=' . wp_json_encode($initialSharedResidue)
        . ' report=' . wp_json_encode($initialReportResidue)
        . ' domain=' . wp_json_encode($initialDomainResidue)
        . ' CRUD=' . wp_json_encode($initialCrudResidue)
        . ' automation=' . wp_json_encode($initialAutomationResidue)
        . ' public=' . wp_json_encode($initialPublicResidue)
        . ' throughput=' . wp_json_encode($initialThroughputResidue)
    );
}

$filter = '';
foreach (isset($args) && is_array($args) ? $args : [] as $argument) {
    if (strpos($argument, '--filter=') === 0) {
        $filter = substr($argument, strlen('--filter='));
    } elseif (strpos($argument, 'filter=') === 0) {
        $filter = substr($argument, strlen('filter='));
    }
}
$filter = trim($filter);

$files = glob(dirname(__DIR__) . '/integration/*.php');
$files = $files === false ? [] : $files;
sort($files);

$cases = [];
foreach ($files as $file) {
    $definitions = require $file;
    if (!is_array($definitions)) {
        WP_CLI::error(basename($file) . ' must return an array of integration cases.');
    }

    foreach ($definitions as $definition) {
        if (
            !is_array($definition)
            || empty($definition['id'])
            || empty($definition['name'])
            || !isset($definition['run'])
            || !is_callable($definition['run'])
        ) {
            WP_CLI::error(basename($file) . ' contains an invalid integration case definition.');
        }

        $definition['file'] = basename($file);
        $definition['kind'] = isset($definition['kind'])
            ? (string) $definition['kind']
            : 'behavior';
        if ($definition['kind'] !== 'behavior') {
            WP_CLI::error(
                basename($file) . ' has unsupported case kind: ' . $definition['kind']
            );
        }
        $definition['known_failure'] = !empty($definition['known_failure']);
        $definition['phase'] = isset($definition['phase'])
            ? (int) $definition['phase']
            : 0;
        if (isset($cases[$definition['id']])) {
            WP_CLI::error('Duplicate integration case ID: ' . $definition['id']);
        }
        $cases[$definition['id']] = $definition;
    }
}

$allCaseCount = count($cases);
$knownFailureCount = count(array_filter($cases, function ($case) {
    return $case['known_failure'];
}));

$throughputInventory = require dirname(__DIR__) . '/throughput/inventory.php';
if (
    !is_array($throughputInventory)
    || ($throughputInventory['small_size'] ?? null) !== 5
    || ($throughputInventory['large_size'] ?? null) !== 25
    || empty($throughputInventory['profiles'])
    || !is_array($throughputInventory['profiles'])
    || count($throughputInventory['profiles']) !== 5
) {
    WP_CLI::error('Phase 16 throughput inventory is missing or malformed.');
}

$throughputKinds = ['list' => 0, 'aggregate' => 0];
foreach ($throughputInventory['profiles'] as $profile) {
    $kind = isset($profile['kind']) ? (string) $profile['kind'] : '';
    $coverage = isset($profile['coverage']) ? (string) $profile['coverage'] : '';
    if (
        empty($profile['id'])
        || !isset($throughputKinds[$kind])
        || $coverage === ''
        || !isset($cases[$coverage])
        || (int) $cases[$coverage]['phase'] !== 16
        || empty($profile['source_checks'])
        || !is_array($profile['source_checks'])
        || ($kind === 'list' && empty($profile['relations']))
    ) {
        WP_CLI::error(
            'Phase 16 throughput inventory entry is incomplete: '
            . wp_json_encode($profile)
        );
    }
    $throughputKinds[$kind]++;

    foreach ($profile['source_checks'] as $check) {
        if (
            empty($check['file'])
            || empty($check['line'])
            || empty($check['needle'])
        ) {
            WP_CLI::error(
                'Phase 16 throughput source check is incomplete: '
                . wp_json_encode($check)
            );
        }
        $lines = file(dirname(__DIR__, 2) . '/' . $check['file']);
        $line = (int) $check['line'];
        if (
            $lines === false
            || !isset($lines[$line - 1])
            || strpos($lines[$line - 1], $check['needle']) === false
        ) {
            WP_CLI::error(
                'Phase 16 throughput source metadata is stale at '
                . $check['file'] . ':' . $line
            );
        }
    }
}
if ($throughputKinds !== ['list' => 4, 'aggregate' => 1]) {
    WP_CLI::error(
        'Phase 16 throughput inventory totals are incomplete: '
        . wp_json_encode($throughputKinds)
    );
}

$publicInventory = require dirname(__DIR__) . '/public/inventory.php';
$permissionInventory = require dirname(__DIR__) . '/permissions/routes.manifest.php';
if (
    !is_array($publicInventory)
    || !isset($publicInventory['routes'], $publicInventory['permission_public_exempt_total'])
    || !is_array($publicInventory['routes'])
    || count($publicInventory['routes']) !== 14
    || !is_array($permissionInventory)
    || empty($permissionInventory['classifications'])
) {
    WP_CLI::error('Phase 18 public UUID inventory is missing or malformed.');
}

$publicSourceCache = [];
$publicSourceLine = function ($sourceFile, $line) use (&$publicSourceCache) {
    $absolute = dirname(__DIR__, 2) . '/' . $sourceFile;
    if (!isset($publicSourceCache[$absolute])) {
        $lines = file($absolute);
        if ($lines === false) {
            WP_CLI::error('Phase 18 source is unreadable: ' . $sourceFile);
        }
        $publicSourceCache[$absolute] = $lines;
    }
    return isset($publicSourceCache[$absolute][$line - 1])
        ? $publicSourceCache[$absolute][$line - 1]
        : '';
};

$permissionPublicExempt = array_filter(
    $permissionInventory['classifications'],
    function ($classification) {
        return isset($classification['classification'])
            && $classification['classification'] === 'public_exempt';
    }
);
if (
    count($permissionPublicExempt)
        !== (int) $publicInventory['permission_public_exempt_total']
    || count($permissionPublicExempt) !== 20
) {
    WP_CLI::error(
        'Phase 18 permission inventory reconciliation failed: expected 20 PUBLIC-EXEMPT, got '
        . count($permissionPublicExempt)
    );
}

$publicRouteIds = [];
$publicUuidExemptCount = 0;
foreach ($publicInventory['routes'] as $entry) {
    if (
        empty($entry['id'])
        || empty($entry['verb'])
        || empty($entry['path'])
        || empty($entry['entity'])
        || empty($entry['route_file'])
        || empty($entry['route_line'])
        || empty($entry['route_needle'])
        || empty($entry['source_file'])
        || empty($entry['method_line'])
        || empty($entry['method_needle'])
        || empty($entry['ownership_line'])
        || empty($entry['ownership_needle'])
        || empty($entry['denial_line'])
        || empty($entry['denial_needle'])
        || empty($entry['post_guard_line'])
        || empty($entry['post_guard_needle'])
        || empty($entry['coverage'])
        || !isset($cases[$entry['coverage']])
    ) {
        WP_CLI::error('Phase 18 public route entry is incomplete: ' . wp_json_encode($entry));
    }
    if (isset($publicRouteIds[$entry['id']])) {
        WP_CLI::error('Phase 18 duplicate public route ID: ' . $entry['id']);
    }
    $publicRouteIds[$entry['id']] = true;

    foreach ([
        [$entry['route_file'], $entry['route_line'], $entry['route_needle'], 'route'],
        [$entry['source_file'], $entry['method_line'], $entry['method_needle'], 'method'],
        [$entry['source_file'], $entry['ownership_line'], $entry['ownership_needle'], 'ownership'],
        [$entry['source_file'], $entry['denial_line'], $entry['denial_needle'], 'denial'],
        [$entry['source_file'], $entry['post_guard_line'], $entry['post_guard_needle'], 'post-guard'],
    ] as $check) {
        if (strpos($publicSourceLine($check[0], (int) $check[1]), $check[2]) === false) {
            WP_CLI::error(
                'Phase 18 ' . $check[3] . ' source metadata is stale at '
                . $check[0] . ':' . $check[1]
            );
        }
    }
    if (
        !(
            (int) $entry['method_line'] < (int) $entry['ownership_line']
            && (int) $entry['ownership_line'] < (int) $entry['denial_line']
            && (int) $entry['denial_line'] < (int) $entry['post_guard_line']
        )
    ) {
        WP_CLI::error('Phase 18 guard ordering is invalid for ' . $entry['id']);
    }

    $permissionId = $entry['route_file'] . ':' . $entry['route_line']
        . ':' . strtoupper($entry['verb']);
    $isPublicExempt = isset($permissionPublicExempt[$permissionId]);
    if (!empty($entry['permission_public_exempt'])) {
        $publicUuidExemptCount++;
    }
    if ($isPublicExempt !== !empty($entry['permission_public_exempt'])) {
        WP_CLI::error(
            'Phase 18 UUID/public-exempt classification drifted for ' . $permissionId
        );
    }
}
if ($publicUuidExemptCount !== 9) {
    WP_CLI::error(
        'Phase 18 expected 9 UUID mutation routes within 21 PUBLIC-EXEMPT declarations; got '
        . $publicUuidExemptCount
    );
}

$validationInventory = require dirname(__DIR__) . '/validation/inventory.php';
if (
    !is_array($validationInventory)
    || !isset(
        $validationInventory['request_wiring'],
        $validationInventory['read_probes'],
        $validationInventory['v_html_sinks']
    )
    || !is_array($validationInventory['request_wiring'])
    || !is_array($validationInventory['read_probes'])
    || !is_array($validationInventory['v_html_sinks'])
) {
    WP_CLI::error('Phase 10 validation inventory is missing or malformed.');
}

$validationSourceCache = [];
$validationSourceLine = function ($sourceFile, $line) use (&$validationSourceCache) {
    $absolute = dirname(__DIR__, 2) . '/' . $sourceFile;
    if (!isset($validationSourceCache[$absolute])) {
        $lines = file($absolute);
        if ($lines === false) {
            WP_CLI::error('Phase 10 validation source is unreadable: ' . $sourceFile);
        }
        $validationSourceCache[$absolute] = $lines;
    }
    return isset($validationSourceCache[$absolute][$line - 1])
        ? $validationSourceCache[$absolute][$line - 1]
        : '';
};

foreach ($validationInventory['request_wiring'] as $entry) {
    if (
        empty($entry['surface'])
        || empty($entry['source_file'])
        || empty($entry['method_line'])
        || empty($entry['method_needle'])
        || empty($entry['sanitize_line'])
        || empty($entry['sanitize_needle'])
        || empty($entry['coverage'])
        || !isset($cases[$entry['coverage']])
    ) {
        WP_CLI::error(
            'Phase 10 request-wiring entry is incomplete: ' . wp_json_encode($entry)
        );
    }
    $methodLine = $validationSourceLine(
        $entry['source_file'],
        (int) $entry['method_line']
    );
    $sanitizeLine = $validationSourceLine(
        $entry['source_file'],
        (int) $entry['sanitize_line']
    );
    if (
        strpos($methodLine, $entry['method_needle']) === false
        || strpos($sanitizeLine, $entry['sanitize_needle']) === false
    ) {
        WP_CLI::error(
            'Phase 10 request-wiring source metadata is stale at '
            . $entry['source_file'] . ':' . $entry['method_line']
            . '/' . $entry['sanitize_line']
        );
    }
}

foreach ($validationInventory['read_probes'] as $entry) {
    if (
        empty($entry['surface'])
        || empty($entry['source_file'])
        || empty($entry['source_line'])
        || empty($entry['needle'])
        || !isset($entry['coverage'])
        || !is_array($entry['coverage'])
    ) {
        WP_CLI::error(
            'Phase 10 read-probe entry is incomplete: ' . wp_json_encode($entry)
        );
    }
    $sourceLine = $validationSourceLine(
        $entry['source_file'],
        (int) $entry['source_line']
    );
    if (strpos($sourceLine, $entry['needle']) === false) {
        WP_CLI::error(
            'Phase 10 read-probe source metadata is stale at '
            . $entry['source_file'] . ':' . $entry['source_line']
        );
    }
    foreach (isset($entry['source_checks']) ? $entry['source_checks'] : [] as $check) {
        if (
            empty($check['source_file'])
            || empty($check['source_line'])
            || empty($check['needle'])
        ) {
            WP_CLI::error(
                'Phase 10 read-probe source check is incomplete: '
                . wp_json_encode($check)
            );
        }
        $checkedLine = $validationSourceLine(
            $check['source_file'],
            (int) $check['source_line']
        );
        if (strpos($checkedLine, $check['needle']) === false) {
            WP_CLI::error(
                'Phase 10 read-probe source check is stale at '
                . $check['source_file'] . ':' . $check['source_line']
            );
        }
    }
    if (!$entry['coverage'] && empty($entry['skip_reason'])) {
        WP_CLI::error(
            'Phase 10 read probe lacks coverage or a safety reason at '
            . $entry['source_file'] . ':' . $entry['source_line']
        );
    }
    foreach ($entry['coverage'] as $coverage) {
        if (!isset($cases[$coverage])) {
            WP_CLI::error(
                'Phase 10 read probe references unknown coverage '
                . $coverage . ' at '
                . $entry['source_file'] . ':' . $entry['source_line']
            );
        }
    }
}

foreach ($validationInventory['v_html_sinks'] as $entry) {
    if (
        empty($entry['source_file'])
        || empty($entry['source_line'])
        || empty($entry['needle'])
    ) {
        WP_CLI::error(
            'Phase 10 v-html sink is incomplete: ' . wp_json_encode($entry)
        );
    }
    $sourceLine = $validationSourceLine(
        $entry['source_file'],
        (int) $entry['source_line']
    );
    if (strpos($sourceLine, $entry['needle']) === false) {
        WP_CLI::error(
            'Phase 10 v-html source metadata is stale at '
            . $entry['source_file'] . ':' . $entry['source_line']
        );
    }
}

if (
    count($validationInventory['request_wiring']) !== 12
    || count($validationInventory['read_probes']) !== 7
    || count($validationInventory['v_html_sinks']) !== 15
    || !isset(
        $cases['validation-destructive-routes-have-policy-methods'],
        $cases['validation-v-html-user-data-sinks']
    )
) {
    WP_CLI::error(
        'Phase 10 validation inventory totals are incomplete: request_wiring='
        . count($validationInventory['request_wiring'])
        . ' read_probes=' . count($validationInventory['read_probes'])
        . ' v_html_sinks=' . count($validationInventory['v_html_sinks'])
    );
}

$crudInventory = require dirname(__DIR__) . '/crud/inventory.php';
if (
    !is_array($crudInventory)
    || empty($crudInventory['routes'])
    || !is_array($crudInventory['routes'])
    || empty($crudInventory['missing'])
    || !is_array($crudInventory['missing'])
) {
    WP_CLI::error('Phase 9 CRUD inventory is missing or malformed.');
}

$crudTotals = ['round_trip' => 0, 'safety_exclusion' => 0];
$crudDomains = [];
$crudSourceCache = [];
foreach ($crudInventory['routes'] as $crudRoute) {
    $classification = isset($crudRoute['classification'])
        ? (string) $crudRoute['classification']
        : '';
    if (!isset($crudTotals[$classification])) {
        WP_CLI::error(
            'Phase 9 CRUD inventory has invalid classification: '
            . wp_json_encode($crudRoute)
        );
    }
    if (
        $classification === 'round_trip'
        && (
            empty($crudRoute['coverage'])
            || !isset($cases[$crudRoute['coverage']])
        )
    ) {
        WP_CLI::error(
            'Phase 9 route references unknown round-trip coverage at '
            . $crudRoute['source_file'] . ':' . $crudRoute['source_line']
        );
    }
    if ($classification === 'safety_exclusion' && empty($crudRoute['reason'])) {
        WP_CLI::error(
            'Phase 9 safety exclusion lacks a written reason at '
            . $crudRoute['source_file'] . ':' . $crudRoute['source_line']
        );
    }

    $sourceFile = dirname(__DIR__, 2) . '/' . $crudRoute['source_file'];
    if (!isset($crudSourceCache[$sourceFile])) {
        $lines = file($sourceFile);
        if ($lines === false) {
            WP_CLI::error('Phase 9 CRUD inventory source is unreadable: ' . $sourceFile);
        }
        $crudSourceCache[$sourceFile] = $lines;
    }
    $line = (int) $crudRoute['source_line'];
    $sourceLine = $crudSourceCache[$sourceFile][$line - 1] ?? '';
    $needle = '->' . strtolower($crudRoute['verb'])
        . "('" . $crudRoute['declared_path'] . "'";
    if (strpos($sourceLine, $needle) === false) {
        WP_CLI::error(
            'Phase 9 CRUD inventory source metadata is stale at '
            . $crudRoute['source_file'] . ':' . $line
            . '; expected ' . $needle
        );
    }

    $crudTotals[$classification]++;
    $crudDomains[$crudRoute['domain']] = true;
}

foreach ($crudInventory['missing'] as $missingCrud) {
    if (
        ($missingCrud['classification'] ?? '') !== 'known_failure'
        || empty($missingCrud['coverage'])
        || !isset($cases[$missingCrud['coverage']])
        || empty($missingCrud['reason'])
        || empty($missingCrud['production'])
    ) {
        WP_CLI::error(
            'Phase 9 missing CRUD entry is incomplete: ' . wp_json_encode($missingCrud)
        );
    }
}

if (
    count($crudInventory['routes']) !== 34
    || $crudTotals !== ['round_trip' => 21, 'safety_exclusion' => 13]
    || count($crudDomains) !== 6
    || count($crudInventory['missing']) !== 2
) {
    WP_CLI::error(
        'Phase 9 CRUD inventory totals are incomplete: routes='
        . count($crudInventory['routes'])
        . ' round_trip=' . $crudTotals['round_trip']
        . ' safety_exclusion=' . $crudTotals['safety_exclusion']
        . ' domains=' . count($crudDomains)
        . ' missing=' . count($crudInventory['missing'])
    );
}

$reportInventory = require dirname(__DIR__) . '/reports/inventory.php';
if (
    !is_array($reportInventory)
    || empty($reportInventory['routes'])
    || !is_array($reportInventory['routes'])
) {
    WP_CLI::error('Phase 7 report inventory is missing or malformed.');
}

$reportTotals = [
    'GET' => ['value' => 0, 'known_failure' => 0, 'skip' => 0],
    'POST' => ['value' => 0, 'known_failure' => 0, 'skip' => 0],
];
$reportSmokeCases = 0;
$reportSourceCache = [];
foreach ($reportInventory['routes'] as $reportRoute) {
    $verb = isset($reportRoute['verb']) ? (string) $reportRoute['verb'] : '';
    $classification = isset($reportRoute['classification'])
        ? (string) $reportRoute['classification']
        : '';
    if (!isset($reportTotals[$verb][$classification])) {
        WP_CLI::error(
            'Phase 7 report inventory has invalid verb/classification at '
            . wp_json_encode($reportRoute)
        );
    }
    if (empty($reportRoute['coverage'])) {
        WP_CLI::error(
            'Phase 7 report inventory lacks coverage/reason at '
            . $reportRoute['source_file'] . ':' . $reportRoute['source_line']
        );
    }
    if ($classification === 'value' && !isset($cases[$reportRoute['coverage']])) {
        WP_CLI::error(
            'Phase 7 value-covered report references an unknown integration case: '
            . $reportRoute['coverage'] . ' at '
            . $reportRoute['source_file'] . ':' . $reportRoute['source_line']
        );
    }

    $sourceFile = dirname(__DIR__, 2) . '/' . $reportRoute['source_file'];
    if (!isset($reportSourceCache[$sourceFile])) {
        $lines = file($sourceFile);
        if ($lines === false) {
            WP_CLI::error('Phase 7 report inventory source is unreadable: ' . $sourceFile);
        }
        $reportSourceCache[$sourceFile] = $lines;
    }
    $line = (int) $reportRoute['source_line'];
    $sourceLine = isset($reportSourceCache[$sourceFile][$line - 1])
        ? $reportSourceCache[$sourceFile][$line - 1]
        : '';
    $unprefixedPath = substr($reportRoute['path'], strlen('reports/'));
    $needle = '->' . strtolower($verb) . "('" . $unprefixedPath . "'";
    if (strpos($sourceLine, $needle) === false) {
        WP_CLI::error(
            'Phase 7 report inventory source metadata is stale at '
            . $reportRoute['source_file'] . ':' . $line
            . '; expected ' . $needle
        );
    }

    $reportTotals[$verb][$classification]++;
    $reportSmokeCases += (int) $reportRoute['phase1_smoke_cases'];
}

if (
    count($reportInventory['routes']) !== 42
    || array_sum($reportTotals['GET']) !== 41
    || array_sum($reportTotals['POST']) !== 1
    || $reportSmokeCases !== 66
) {
    WP_CLI::error(
        'Phase 7 report inventory totals are incomplete: routes='
        . count($reportInventory['routes'])
        . ' GET=' . array_sum($reportTotals['GET'])
        . ' POST=' . array_sum($reportTotals['POST'])
        . ' Phase1_shapes=' . $reportSmokeCases
    );
}

$smokeManifest = (static function ($path) {
    return require $path;
})(dirname(__DIR__) . '/smoke/routes.manifest.php');
$phase1ReportDeclarations = array_filter(
    $smokeManifest['declarations'],
    function ($entry) {
        return $entry['source_file'] === 'app/Http/Routes/reports.php';
    }
);
$phase1ReportCases = array_filter(
    $smokeManifest['cases'],
    function ($entry) {
        return $entry['source_file'] === 'app/Http/Routes/reports.php';
    }
);
$phase1KnownCases = array_filter(
    $phase1ReportCases,
    function ($entry) {
        return !empty($entry['known_failure']);
    }
);
if (
    count($phase1ReportDeclarations) !== $reportInventory['phase1']['get_declarations']
    || count($phase1ReportCases) !== $reportInventory['phase1']['get_smoke_cases']
    || count($phase1KnownCases) !== $reportInventory['phase1']['known_failure_smoke_cases']
) {
    WP_CLI::error(
        'Phase 7 inventory does not reconcile with the Phase 1 report manifest: '
        . 'declarations=' . count($phase1ReportDeclarations)
        . ' query_shapes=' . count($phase1ReportCases)
        . ' known_shapes=' . count($phase1KnownCases)
    );
}
unset(
    $smokeManifest,
    $phase1ReportDeclarations,
    $phase1ReportCases,
    $phase1KnownCases
);

if ($filter !== '') {
    $cases = array_filter($cases, function ($case) use ($filter) {
        return strpos($case['id'], $filter) !== false
            || strpos($case['name'], $filter) !== false
            || strpos($case['file'], $filter) !== false;
    });
}

WP_CLI::log(sprintf(
    "Phase 16/18/22/23/24/29/30 integration — behavioral_cases=%d selected=%d invariant_checks=1 KNOWN-FAILURE=%d%s\n"
    . "fixture identity: %s\n"
    . "cleared cache entries: %d\n"
    . "public UUID inventory: guarded_routes=%d UUID_PUBLIC-EXEMPT=%d "
    . "permission_PUBLIC-EXEMPT=%d\n"
    . "CRUD inventory: routes=%d round_trip=%d safety_exclusion=%d domains=%d missing=%d\n"
    . "validation inventory: request_wiring=%d read_probes=%d "
    . "destructive_policies=18 v_html_sinks=%d\n"
    . "throughput inventory: profiles=%d list=%d aggregate=%d rows=%d/%d\n"
    . "report inventory: declarations=42 GET=41 POST=1 Phase1_query_shapes=%d "
    . "GET_value=%d GET_KNOWN-FAILURE=%d GET_skipped=%d POST_skipped=%d",
    $allCaseCount,
    count($cases),
    $knownFailureCount,
    $filter !== '' ? ' filter=' . $filter : '',
    $identity,
    $cleared,
    count($publicInventory['routes']),
    $publicUuidExemptCount,
    count($permissionPublicExempt),
    count($crudInventory['routes']),
    $crudTotals['round_trip'],
    $crudTotals['safety_exclusion'],
    count($crudDomains),
    count($crudInventory['missing']),
    count($validationInventory['request_wiring']),
    count($validationInventory['read_probes']),
    count($validationInventory['v_html_sinks']),
    count($throughputInventory['profiles']),
    $throughputKinds['list'],
    $throughputKinds['aggregate'],
    $throughputInventory['small_size'],
    $throughputInventory['large_size'],
    $reportSmokeCases,
    $reportTotals['GET']['value'],
    $reportTotals['GET']['known_failure'],
    $reportTotals['GET']['skip'],
    $reportTotals['POST']['skip']
));

/*
 * This backstop covers fatal termination outside the ordinary case/finally
 * path. Normal cleanup remains authoritative because it can fail the suite.
 */
register_shutdown_function(function () {
    try {
        FcCrudFixture::cleanupAll();
        FcDomainFixture::cleanupAll();
        FcAutomationFixture::cleanupAll();
        FcPublicSurfaceFixture::cleanupAll();
        FcThroughputFixture::cleanupAll();
    } catch (\Throwable $e) {
        WP_CLI::warning('Fixture shutdown cleanup failed: ' . $e->getMessage());
    }
});

if (!$cases) {
    FcTest::case('integration runner selects at least one case', function () use ($filter) {
        FcTest::fail('No integration case matched filter: ' . $filter);
    });
}

$cleanupFailure = null;
$profileCases = getenv('WP_PLUGIN_TEST_PROFILE') === '1';
global $wpdb;
try {
    foreach ($cases as $case) {
        $caseStartedAt = microtime(true);
        $queriesBefore = (int) $wpdb->num_queries;
        $run = $case['run'];
        if (in_array($case['phase'], [14, 16, 18], true)) {
            $run = function () use ($case) {
                FcTest::assertMailAndLoopbackInterceptorsActive();
                call_user_func($case['run']);
            };
        }
        FcTest::case($case['name'], $run);
        if ($profileCases) {
            WP_CLI::log(sprintf(
                'PROFILE %.3fs %d queries %s',
                microtime(true) - $caseStartedAt,
                (int) $wpdb->num_queries - $queriesBefore,
                $case['id']
            ));
        }
    }
} finally {
    try {
        FcCrudFixture::cleanupAll();
        // Idempotence is part of the reusable fixture contract.
        FcCrudFixture::cleanupAll();
        FcDomainFixture::cleanupAll();
        // Idempotence is part of the reusable fixture contract.
        FcDomainFixture::cleanupAll();
        FcAutomationFixture::cleanupAll();
        // Idempotence is part of the reusable fixture contract.
        FcAutomationFixture::cleanupAll();
        FcPublicSurfaceFixture::cleanupAll();
        // Idempotence is part of the reusable fixture contract.
        FcPublicSurfaceFixture::cleanupAll();
        FcThroughputFixture::cleanupAll();
        // Idempotence is part of the reusable fixture contract.
        FcThroughputFixture::cleanupAll();

        $outerAttempts = [];
        foreach (FcTest::externalCalls() as $call) {
            $outerAttempts[] = 'HTTP ' . $call['method'] . ' ' . $call['url'];
        }
        if (FcTest::sentMails()) {
            $outerAttempts[] = 'mail count=' . count(FcTest::sentMails());
        }
        foreach (FcTest::cronAttempts() as $attempt) {
            $outerAttempts[] = 'cron ' . $attempt['operation'] . ' ' . $attempt['hook'];
        }
        foreach (FcTest::actionSchedulerAttempts() as $attempt) {
            $outerAttempts[] = 'scheduler ' . $attempt['operation'] . ' ' . $attempt['hook'];
        }
        if ($outerAttempts) {
            throw new RuntimeException(
                'Fixture outer cleanup attempted blocked transport: '
                . implode('; ', $outerAttempts)
            );
        }
    } catch (\Throwable $e) {
        $cleanupFailure = $e;
    }
}

if ($cleanupFailure !== null) {
    FcTest::case('Fixture outer cleanup is exact and idempotent', function () use ($cleanupFailure) {
        throw $cleanupFailure;
    });
}

$afterCounts = FcFixture::protectedCounts();
$residue = FcFixture::residueCounts($identity);
$relatedResidue = [];
foreach (FcFixture::ownedOrderIds() as $orderId) {
    $relatedResidue[$orderId] = FcFixture::orderRelatedCounts($orderId);
}
$sharedResidue = FcFixture::sharedResidueCounts();
$sharedMarkerResidue = FcFixture::sharedMarkerResidueCounts($identity);
$reportResidue = FcFixture::reportResidueCounts();
$reportMarkerResidue = FcFixture::reportMarkerResidueCounts($identity);
$domainResidue = FcDomainFixture::residueCounts();
$domainMarkerResidue = FcDomainFixture::markerResidueCounts();
$crudResidue = FcCrudFixture::residueCounts();
$crudMarkerResidue = FcCrudFixture::markerResidueCounts();
$automationResidue = FcAutomationFixture::residueCounts();
$automationMarkerResidue = FcAutomationFixture::markerResidueCounts();
$publicResidue = FcPublicSurfaceFixture::residueCounts();
$publicMarkerResidue = FcPublicSurfaceFixture::markerResidueCounts();
$throughputResidue = FcThroughputFixture::residueCounts();
$throughputMarkerResidue = FcThroughputFixture::markerResidueCounts();
FcTest::case('integration run restores protected counts and exact fixture absence', function () use (
    $baseline,
    $afterCounts,
    $residue,
    $relatedResidue,
    $sharedResidue,
    $sharedMarkerResidue,
    $reportResidue,
    $reportMarkerResidue,
    $domainResidue,
    $domainMarkerResidue,
    $crudResidue,
    $crudMarkerResidue,
    $automationResidue,
    $automationMarkerResidue,
    $publicResidue,
    $publicMarkerResidue,
    $throughputResidue,
    $throughputMarkerResidue
) {
    FcTest::assertSame(
        $baseline,
        $afterCounts,
        'protected orders/customers counts return to the pre-run baseline'
    );
    FcTest::assertSame(
        ['customer' => 0, 'order' => 0],
        $residue,
        'exact Customer and Order fixture identities have zero residue'
    );
    foreach ($relatedResidue as $orderId => $counts) {
        FcTest::assertSame(
            array_fill_keys(array_keys($counts), 0),
            $counts,
            'exact related rows have zero residue for Order ID ' . $orderId
        );
    }
    FcTest::assertSame(
        array_fill_keys(array_keys($sharedResidue), 0),
        $sharedResidue,
        'every captured shared fixture ID has zero residue by table'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($sharedMarkerResidue), 0),
        $sharedMarkerResidue,
        'every exact shared-table identity marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($reportResidue), 0),
        $reportResidue,
        'every captured Phase 7 report fixture ID has zero residue by table'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($reportMarkerResidue), 0),
        $reportMarkerResidue,
        'every exact Phase 7 report marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($domainResidue), 0),
        $domainResidue,
        'every captured Phase 8 domain fixture ID has zero residue by table'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($domainMarkerResidue), 0),
        $domainMarkerResidue,
        'every exact Phase 8 domain marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($crudResidue), 0),
        $crudResidue,
        'every captured Phase 9 CRUD fixture ID has zero residue by table'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($crudMarkerResidue), 0),
        $crudMarkerResidue,
        'every exact Phase 9 CRUD marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($automationResidue), 0),
        $automationResidue,
        'every captured Phase 14 automation fixture has zero residue by primary key'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($automationMarkerResidue), 0),
        $automationMarkerResidue,
        'every exact Phase 14 automation marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($publicResidue), 0),
        $publicResidue,
        'every captured Phase 18 public-surface fixture has zero residue by primary key'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($publicMarkerResidue), 0),
        $publicMarkerResidue,
        'every exact Phase 18 public-surface marker has zero outer-process residue'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($throughputResidue), 0),
        $throughputResidue,
        'every captured Phase 16 throughput fixture ID has zero residue by primary key'
    );
    FcTest::assertSame(
        array_fill_keys(array_keys($throughputMarkerResidue), 0),
        $throughputMarkerResidue,
        'every exact Phase 16 throughput marker has zero outer-process residue'
    );
});

$runtime = microtime(true) - $startedAt;
WP_CLI::log(sprintf(
    "internal protected counts: before=%s after=%s\n"
    . "Integration result: passed=%d failed=%d KNOWN-FAILURE=%d runtime=%.3fs\n"
    . "fixture cleanup: identity=%s customer_residue=%d order_residue=%d order_ids=%s related_residue=%d\n"
    . "shared fixture cleanup: ids=%s exact_id_residue=%s marker_residue=%s\n"
    . "report fixture cleanup: ids=%s exact_id_residue=%s marker_residue=%s\n"
    . "domain fixture cleanup: ids=%s exact_id_residue=%s marker_residue=%s\n"
    . "CRUD fixture cleanup: exact_id_residue=%s marker_residue=%s\n"
    . "automation fixture cleanup: exact_id_residue=%s marker_residue=%s\n"
    . "public fixture cleanup: exact_id_residue=%s marker_residue=%s\n"
    . "throughput fixture cleanup: ids=%s exact_id_residue=%s marker_residue=%s\n"
    . "safety guards: outbound_http=%d mail=%d cron=%d action_scheduler=%d",
    wp_json_encode($baseline),
    wp_json_encode($afterCounts),
    FcTest::$passed,
    count(FcTest::$failures),
    $knownFailureCount,
    $runtime,
    $identity,
    $residue['customer'],
    $residue['order'],
    wp_json_encode(FcFixture::ownedOrderIds()),
    array_sum(array_map('array_sum', $relatedResidue)),
    wp_json_encode(FcFixture::ownedSharedIds()),
    wp_json_encode($sharedResidue),
    wp_json_encode($sharedMarkerResidue),
    wp_json_encode(FcFixture::ownedReportIds()),
    wp_json_encode($reportResidue),
    wp_json_encode($reportMarkerResidue),
    wp_json_encode(FcDomainFixture::ownedIds()),
    wp_json_encode($domainResidue),
    wp_json_encode($domainMarkerResidue),
    wp_json_encode($crudResidue),
    wp_json_encode($crudMarkerResidue),
    wp_json_encode($automationResidue),
    wp_json_encode($automationMarkerResidue),
    wp_json_encode($publicResidue),
    wp_json_encode($publicMarkerResidue),
    wp_json_encode(FcThroughputFixture::ownedIds()),
    wp_json_encode($throughputResidue),
    wp_json_encode($throughputMarkerResidue),
    count(FcTest::externalCalls()),
    count(FcTest::sentMails()),
    count(FcTest::cronAttempts()),
    count(FcTest::actionSchedulerAttempts())
));

FcTest::finish('INTEGRATION');
