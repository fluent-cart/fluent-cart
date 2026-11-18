<?php
/**
 * S1 — REST GET smoke runner.
 *
 * Adapted from wp-plugin-test-suite/assets/bin/run-smoke.php. Every case is
 * dispatched in-process with rest_do_request(). A case with a non-REST
 * transport fails before any request is made.
 *
 * Usage:
 *   wp eval-file tests/wp-cli/bin/run-smoke.php
 *   wp eval-file tests/wp-cli/bin/run-smoke.php -- --filter=orders
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/fixtures.php';
require_once __DIR__ . '/../lib/domain-fixtures.php';
require_once __DIR__ . '/../lib/automation-fixtures.php';
require_once __DIR__ . '/../lib/smoke-fixtures.php';

FcTest::boot();
FcTest::interceptCronMutations();
FcTest::interceptActionScheduler();

$manifest = require __DIR__ . '/../smoke/routes.manifest.php';
$resolve = require __DIR__ . '/../lib/resolvers.php';

if (
    !is_array($manifest)
    || !isset($manifest['route_files'], $manifest['declarations'], $manifest['cases'])
    || !is_array($manifest['route_files'])
    || !is_array($manifest['declarations'])
    || !is_array($manifest['cases'])
) {
    WP_CLI::error('Smoke manifest must provide route_files, declarations, and cases arrays.');
}

$filter = '';
foreach ((array) $args as $arg) {
    if (strpos($arg, '--filter=') === 0) {
        $filter = substr($arg, 9);
    } elseif (strpos($arg, 'filter=') === 0) {
        $filter = substr($arg, 7);
    }
}

$replaceTokens = function ($value, array $tokens) use (&$replaceTokens) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = $replaceTokens($item, $tokens);
        }
        return $value;
    }

    if (!is_string($value)) {
        return $value;
    }

    foreach ($tokens as $token => $replacement) {
        if ($value === '{' . $token . '}') {
            return $replacement;
        }
        if (!is_array($replacement) && !is_object($replacement)) {
            $value = str_replace('{' . $token . '}', (string) $replacement, $value);
        }
    }

    return $value;
};

$containsTokens = function ($value) use (&$containsTokens) {
    if (is_array($value)) {
        foreach ($value as $item) {
            if ($containsTokens($item)) {
                return true;
            }
        }
        return false;
    }

    return is_string($value)
        && preg_match('/\{[A-Za-z][A-Za-z0-9_]*\}/', $value) === 1;
};

$stats = [];
foreach ($manifest['route_files'] as $file) {
    $stats[$file] = [
        'declarations' => 0,
        'executable'   => 0,
        'variations'   => 0,
        'known_failures' => 0,
    ];
}
foreach ($manifest['declarations'] as $declaration) {
    if (!isset($stats[$declaration['source_file']])) {
        WP_CLI::error('Manifest declaration references an unconfigured route file: ' . $declaration['source_file']);
    }
    $stats[$declaration['source_file']]['declarations']++;
}
foreach ($manifest['cases'] as $entry) {
    $file = $entry['source_file'];
    $stats[$file]['executable']++;
    if (!empty($entry['variation'])) {
        $stats[$file]['variations']++;
    }
    if (!empty($entry['known_failure'])) {
        $stats[$file]['known_failures']++;
    }
}

$cleared = FcTest::clearCaches();
$caseCount = count($manifest['cases']);
FcTest::note('FluentCart REST smoke — ' . count($manifest['declarations']) . ' declarations, '
    . $caseCount . ' cases'
    . ($filter ? " (filter: {$filter})" : '')
    . ' — cleared ' . $cleared . " cache entries\n");
FcTest::note('Manifest inventory by configured route file:');
foreach ($stats as $file => $fileStats) {
    FcTest::note(sprintf(
        '  %s declarations=%d executable=%d variations=%d known_failures=%d',
        $file,
        $fileStats['declarations'],
        $fileStats['executable'],
        $fileStats['variations'],
        $fileStats['known_failures']
    ));
}

$adminId = get_current_user_id();

// A Pro-only case belongs to the runs where Pro loads, not to --axis=pro-absent.
$selected = [];
foreach ($manifest['cases'] as $entry) {
    if (isset($entry['requires']) && $entry['requires'] === 'pro' && !FcTest::proExpected()) {
        continue;
    }
    $searchable = (isset($entry['label']) ? $entry['label'] : $entry['route']) . ' ' . $entry['route'] . ' '
        . (isset($entry['source_file']) ? $entry['source_file'] : '') . ':'
        . (isset($entry['source_line']) ? $entry['source_line'] : '');
    if ($filter === '' || stripos($searchable, $filter) !== false) {
        $selected[] = $entry;
    }
}

// Create the records the selected cases need before the first case runs, and delete them by exact ID after the last.
$fixtureErrors = [];
register_shutdown_function(static function () {
    try {
        FcSmokeFixture::cleanupAll();
    } catch (\Throwable $e) {
        WP_CLI::warning('Smoke fixture shutdown cleanup failed: ' . $e->getMessage());
    }
});
$blockedTransport = static function () {
    return count(FcTest::cronAttempts()) + count(FcTest::actionSchedulerAttempts())
        + count(FcTest::externalCalls()) + count(FcTest::sentMails());
};
try {
    $needs = [];
    foreach ($selected as $entry) {
        foreach ((array) (isset($entry['needs']) ? $entry['needs'] : []) as $need) {
            $needs[] = $need;
        }
    }
    FcSmokeFixture::create($needs);
    if ($blockedTransport()) {
        throw new RuntimeException('Creating the smoke fixtures attempted blocked cron, scheduler, HTTP or mail transport.');
    }
} catch (\Throwable $e) {
    $fixtureErrors[] = 'fixture creation failed: ' . $e->getMessage();
}

try {
    foreach ($selected as $entry) {
        $label = isset($entry['label']) ? $entry['label'] : $entry['route'];
        $route = $entry['route'];
        $params = isset($entry['params']) ? $entry['params'] : [];
        $ok = isset($entry['ok']) ? $entry['ok'] : [200];

        FcTest::case($label, function () use (
            $entry,
            $route,
            $params,
            $ok,
            $label,
            $resolve,
            $replaceTokens,
            $containsTokens,
            $adminId
        ) {
            try {
                if (!isset($entry['transport']) || $entry['transport'] !== 'rest') {
                    FcTest::fail(
                        'Manifest safety error: executable case uses non-REST transport '
                        . (isset($entry['transport']) ? $entry['transport'] : '(missing)')
                    );
                    return;
                }

                $tokens = [];
                foreach ((array) (isset($entry['needs']) ? $entry['needs'] : []) as $need) {
                    $resolved = FcSmokeFixture::tokens($need) ?: $resolve($need);
                    if (!$resolved) {
                        FcTest::fail($label . "\n  no " . $need . ' record is available on this site');
                        return;
                    }
                    $tokens = array_merge($tokens, $resolved);
                }

                $route = $replaceTokens($route, $tokens);
                $params = $replaceTokens($params, $tokens);

                $auth = isset($entry['auth']) ? $entry['auth'] : 'admin';
                if ($auth === 'anonymous') {
                    wp_set_current_user(0);
                } elseif ($auth === 'customer') {
                    if (empty($tokens['user_id'])) {
                        FcTest::fail('Customer-auth case did not resolve a user_id.');
                        return;
                    }
                    wp_set_current_user((int) $tokens['user_id']);
                } elseif ($auth === 'admin') {
                    wp_set_current_user($adminId);
                } else {
                    FcTest::fail('Unknown auth mode: ' . $auth);
                    return;
                }

                if ($containsTokens([$route, $params])) {
                    $encoded = wp_json_encode([$route, $params]);
                    FcTest::fail($label . "\n  unresolved manifest placeholder: " . $encoded);
                    return;
                }

                $result = $auth === 'customer'
                    ? FcTest::withVerifiedCustomer(function () use ($route, $params) {
                        return FcTest::rest('GET', $route, $params);
                    })
                    : FcTest::rest('GET', $route, $params);


                if (isset($entry['empty_response'])) {
                    $empty = $entry['empty_response'];
                    if ($result['status'] === $empty['status'] && $result['message'] === __($empty['message'], 'fluent-cart')) {
                        $ok[] = $empty['status'];
                    }
                }
                FcTest::assertHealthy($result, $label . "  [GET {$route}]", $ok);
            } finally {
                wp_set_current_user($adminId);
            }
        });
    }
} finally {
    try {
        FcSmokeFixture::cleanupAll();
        FcSmokeFixture::cleanupAll();
        if ($blockedTransport()) {
            throw new RuntimeException('Cleaning up the smoke fixtures attempted blocked cron, scheduler, HTTP or mail transport.');
        }
    } catch (\Throwable $e) {
        $fixtureErrors[] = 'fixture cleanup failed: ' . $e->getMessage();
    }
}

if ($fixtureErrors) {
    FcTest::case('Smoke fixtures are created and removed cleanly', function () use ($fixtureErrors) {
        FcTest::fail(implode("\n", $fixtureErrors));
    });
}

FcTest::finish('SMOKE');
