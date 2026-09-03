<?php
/**
 * Phase 12/13/22/24/30 pure-behavior runner.
 *
 * These cases use the real plugin classes under the existing WP-CLI bootstrap
 * but create no fixtures and perform no writes. The shared harness keeps
 * protected-table and transport guards active around every case.
 */

require_once dirname(__DIR__) . '/lib/harness.php';
require_once dirname(__DIR__) . '/lib/provider-harness.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();

$filter = '';
foreach (isset($args) && is_array($args) ? $args : [] as $argument) {
    if (strpos($argument, '--filter=') === 0) {
        $filter = substr($argument, strlen('--filter='));
    } elseif (strpos($argument, 'filter=') === 0) {
        $filter = substr($argument, strlen('filter='));
    }
}
$filter = trim($filter);

$definitions = require dirname(__DIR__) . '/pure/functions.php';
if (!is_array($definitions)) {
    WP_CLI::error('pure/functions.php must return an array of case definitions.');
}

$cases = [];
$knownFailureCount = 0;
$phaseCounts = [];
foreach ($definitions as $definition) {
    $phase = (int) ($definition['phase'] ?? 0);
    if (
        !is_array($definition)
        || empty($definition['id'])
        || empty($definition['name'])
        || !isset($definition['run'])
        || !is_callable($definition['run'])
        || empty($definition['targets'])
        || !is_array($definition['targets'])
        || empty($definition['boundaries'])
        || !is_array($definition['boundaries'])
        || !in_array($phase, [12, 13, 22, 24, 30], true)
    ) {
        WP_CLI::error(
            'pure/functions.php contains an incomplete Phase 12/13/22/24/30 case definition.'
        );
    }

    foreach ($definition['targets'] as $target) {
        if (!is_string($target) || strpos($target, '::') === false) {
            WP_CLI::error(
                'Pure-function target metadata is malformed for ' . $definition['id']
            );
        }
    }
    foreach ($definition['boundaries'] as $boundary) {
        if (!is_string($boundary) || trim($boundary) === '') {
            WP_CLI::error(
                'Pure-function boundary metadata is malformed for ' . $definition['id']
            );
        }
    }

    $id = (string) $definition['id'];
    if (isset($cases[$id])) {
        WP_CLI::error('Duplicate pure-function case ID: ' . $id);
    }

    $phaseCounts[$phase] = isset($phaseCounts[$phase]) ? $phaseCounts[$phase] + 1 : 1;
    $definition['known_failure'] = !empty($definition['known_failure']);
    if ($definition['known_failure']) {
        $knownFailureCount++;
    }

    if (
        $filter !== ''
        && stripos($id, $filter) === false
        && stripos((string) $definition['name'], $filter) === false
        && stripos(implode(' ', $definition['targets']), $filter) === false
    ) {
        continue;
    }

    $cases[$id] = $definition;
}

WP_CLI::log(sprintf(
    'Pure-function inventory: total=%d selected=%d Phase12=%d Phase13=%d Phase22=%d Phase24=%d Phase30=%d KNOWN-FAILURE=%d',
    count($definitions),
    count($cases),
    isset($phaseCounts[12]) ? $phaseCounts[12] : 0,
    isset($phaseCounts[13]) ? $phaseCounts[13] : 0,
    isset($phaseCounts[22]) ? $phaseCounts[22] : 0,
    isset($phaseCounts[24]) ? $phaseCounts[24] : 0,
    isset($phaseCounts[30]) ? $phaseCounts[30] : 0,
    $knownFailureCount
));

if (!$cases) {
    FcTest::case('pure runner selects at least one case', function () use ($filter) {
        FcTest::fail('No pure-function case matched filter: ' . $filter);
    });
}

foreach ($cases as $case) {
    FcTest::case($case['name'], $case['run']);
}

WP_CLI::log(sprintf(
    'Pure-function safety guards: outbound_http=%d mail=%d cron=%d action_scheduler=%d',
    count(FcTest::externalCalls()),
    count(FcTest::sentMails()),
    count(FcTest::cronAttempts()),
    count(FcTest::actionSchedulerAttempts())
));

FcTest::finish('PURE FUNCTIONS');
