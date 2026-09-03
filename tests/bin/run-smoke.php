<?php
/**
 * S1 — REST GET smoke runner.
 *
 * Adapted from wp-plugin-test-suite/assets/bin/run-smoke.php. Every executable
 * route is dispatched in-process with rest_do_request(); web/faker cases must
 * be explicitly skipped in the manifest and can never fall through to HTTP.
 *
 * Usage:
 *   wp eval-file tests/bin/run-smoke.php
 *   wp eval-file tests/bin/run-smoke.php -- --filter=orders
 */

require_once __DIR__ . '/../lib/harness.php';

FcTest::boot();

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
        'skips'        => 0,
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
    if (!empty($entry['skip'])) {
        $stats[$file]['skips']++;
    } else {
        $stats[$file]['executable']++;
    }
    if (!empty($entry['variation'])) {
        $stats[$file]['variations']++;
    }
    if (!empty($entry['known_failure'])) {
        $stats[$file]['known_failures']++;
    }
}

$cleared = FcTest::clearCaches();
$caseCount = count($manifest['cases']);
WP_CLI::log('FluentCart REST smoke — ' . count($manifest['declarations']) . ' declarations, '
    . $caseCount . ' cases'
    . ($filter ? " (filter: {$filter})" : '')
    . ' — cleared ' . $cleared . " cache entries\n");
WP_CLI::log('Manifest inventory by configured route file:');
foreach ($stats as $file => $fileStats) {
    WP_CLI::log(sprintf(
        '  %s declarations=%d executable=%d variations=%d documented_skips=%d known_failures=%d',
        $file,
        $fileStats['declarations'],
        $fileStats['executable'],
        $fileStats['variations'],
        $fileStats['skips'],
        $fileStats['known_failures']
    ));
}
WP_CLI::log('');

$adminId = get_current_user_id();

foreach ($manifest['cases'] as $entry) {
    $label = isset($entry['label']) ? $entry['label'] : $entry['route'];
    $route = $entry['route'];
    $params = isset($entry['params']) ? $entry['params'] : [];
    $ok = isset($entry['ok']) ? $entry['ok'] : [200];

    $searchable = $label . ' ' . $route . ' '
        . (isset($entry['source_file']) ? $entry['source_file'] : '') . ':'
        . (isset($entry['source_line']) ? $entry['source_line'] : '');
    if ($filter !== '' && stripos($searchable, $filter) === false) {
        continue;
    }

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
            if (isset($entry['skip'])) {
                FcTest::skip($entry['skip']);
                return;
            }

            if (!isset($entry['transport']) || $entry['transport'] !== 'rest') {
                FcTest::fail(
                    'Manifest safety error: executable case uses non-REST transport '
                    . (isset($entry['transport']) ? $entry['transport'] : '(missing)')
                );
                return;
            }

            $tokens = [];
            foreach ((array) (isset($entry['needs']) ? $entry['needs'] : []) as $need) {
                $resolved = $resolve($need);
                if (!$resolved) {
                    FcTest::skip('no safe existing ' . $need . ' fixture exists on this site');
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

            $result = FcTest::rest('GET', $route, $params);

            if (isset($entry['known_failure'])) {
                $known = $entry['known_failure'];
                $dbMatch = isset($known['db_match']) ? (string) $known['db_match'] : '';
                $exceptionMatch = isset($known['exception_match'])
                    ? (string) $known['exception_match']
                    : '';
                $matchesKnownFailure = (
                    $dbMatch !== ''
                    && strpos($result['db_error'], $dbMatch) !== false
                ) || (
                    $exceptionMatch !== ''
                    && $result['is_exception']
                    && strpos($result['message'], $exceptionMatch) !== false
                );

                if ($matchesKnownFailure) {
                    FcTest::skip(
                        'KNOWN-FAILURE — '
                        . (isset($known['reason']) ? $known['reason'] : 'documented production defect')
                    );
                    return;
                }
            }

            FcTest::assertHealthy($result, $label . "  [GET {$route}]", $ok);
        } finally {
            wp_set_current_user($adminId);
        }
    });
}

FcTest::finish('SMOKE');
