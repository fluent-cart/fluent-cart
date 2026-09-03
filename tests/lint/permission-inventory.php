<?php
/**
 * Static mutating-route permission inventory lint.
 *
 * Does not boot WordPress, register routes, or invoke controllers.
 */

$startedAt = microtime(true);
$pluginDir = dirname(__DIR__, 2);
$config = require $pluginDir . '/tests/suite.config.php';
$manifest = require $pluginDir . '/tests/permissions/routes.manifest.php';
require_once $pluginDir . '/tests/lib/permission-route-source.php';

$inventory = FcPermissionRouteSource::discover($pluginDir, $config);
$errors = $inventory['errors'];
$addError = static function ($location, $reason) use (&$errors) {
    $errors[] = [
        'location' => (string) $location,
        'reason'   => (string) $reason,
    ];
};

if (
    !is_array($manifest)
    || !isset($manifest['route_files'], $manifest['classifications'])
    || !is_array($manifest['route_files'])
    || !is_array($manifest['classifications'])
) {
    $addError(
        'tests/permissions/routes.manifest.php',
        'manifest must return route_files and classifications arrays'
    );
    $manifest = [
        'route_files'     => [],
        'classifications' => [],
    ];
}

if (array_values($manifest['route_files']) !== $inventory['route_files']) {
    $addError(
        'tests/permissions/routes.manifest.php',
        'route_files must exactly match tests/suite.config.php, including order'
    );
}

$allowedClassifications = [
    'protected_executable',
    'public_exempt',
    'unsafe_skip',
    'known_failure',
];
$classified = [];
$categoryTotals = array_fill_keys($allowedClassifications, 0);

foreach ($manifest['classifications'] as $id => $classification) {
    $location = 'tests/permissions/routes.manifest.php [' . $id . ']';
    if (!is_string($id) || $id === '') {
        $addError($location, 'classification key must be an exact non-empty declaration ID');
        continue;
    }
    if (!isset($inventory['declarations'][$id])) {
        $addError($location, 'stale classification: no source mutating declaration has ID ' . $id);
        continue;
    }
    if (!is_array($classification)) {
        $addError($location, 'classification must be an array');
        continue;
    }

    $kind = isset($classification['classification'])
        ? (string) $classification['classification']
        : '';
    $reason = isset($classification['reason']) ? trim((string) $classification['reason']) : '';
    if (!in_array($kind, $allowedClassifications, true)) {
        $addError($location, 'unsupported or missing classification: ' . var_export($kind, true));
        continue;
    }
    if ($reason === '') {
        $addError($location, $kind . ' requires a precise route-specific reason');
    }

    $declaration = $inventory['declarations'][$id];
    if ($kind === 'protected_executable') {
        if ($declaration['transport'] !== 'rest') {
            $addError(
                $location,
                'non-REST declaration cannot be executable: transport='
                    . $declaration['transport']
            );
        }
        if (
            $declaration['policy'] === null
            || in_array($declaration['policy'], ['PublicPolicy', 'CustomerFrontendPolicy'], true)
        ) {
            $addError(
                $declaration['source_file'] . ':' . $declaration['source_line'],
                'protected executable classification conflicts with source policy '
                . var_export($declaration['policy'], true)
            );
        }
        if (
            !isset($classification['expected_anonymous'], $classification['expected_subscriber'])
            || (int) $classification['expected_anonymous'] !== 401
            || (int) $classification['expected_subscriber'] !== 403
        ) {
            $addError(
                $location,
                'protected denial contract must require anonymous=401 and subscriber=403'
            );
        }
        if (!isset($classification['params']) || !is_array($classification['params'])) {
            $addError($location, 'protected executable declaration requires an invalid/no-op params array');
        }

        $bindings = isset($classification['bindings']) ? $classification['bindings'] : [];
        if (!is_array($bindings)) {
            $addError($location, 'bindings must be a placeholder-to-resolver array');
        } else {
            foreach ($bindings as $placeholder => $resolver) {
                if (
                    strpos($declaration['route'], '{' . $placeholder . '}') === false
                    || !in_array(
                        $resolver,
                        ['existing_product_id', 'existing_order_id', 'existing_transaction_id'],
                        true
                    )
                ) {
                    $addError(
                        $location,
                        'invalid read-only route binding '
                            . var_export($placeholder . '=>' . $resolver, true)
                    );
                }
            }
        }
    } elseif ($kind === 'public_exempt') {
        if (!in_array($declaration['policy'], ['PublicPolicy', 'CustomerFrontendPolicy'], true)) {
            $addError(
                $declaration['source_file'] . ':' . $declaration['source_line'],
                'public/exempt classification conflicts with source policy '
                    . var_export($declaration['policy'], true)
            );
        }
    }

    $classified[$id] = array_merge($declaration, $classification);
    $categoryTotals[$kind]++;
}

foreach ($inventory['declarations'] as $id => $declaration) {
    if (!isset($classified[$id])) {
        $addError(
            $declaration['source_file'] . ':' . $declaration['source_line'],
            'missing permission classification for source '
                . $declaration['verb'] . ' /' . $declaration['route']
                . ' (ID ' . $id . ', policy '
                . var_export($declaration['policy'], true) . ')'
        );
    }
}

$verbTotals = [
    'POST'   => 0,
    'PUT'    => 0,
    'PATCH'  => 0,
    'DELETE' => 0,
];
foreach ($inventory['declarations'] as $declaration) {
    $verbTotals[$declaration['verb']]++;
}

$runtime = number_format(microtime(true) - $startedAt, 3, '.', '');
echo 'permission-inventory: configured ' . count($inventory['route_files'])
    . ' files; discovered ' . count($inventory['declarations'])
    . ' mutating declarations; classified ' . count($classified) . "\n";
echo sprintf(
    "verbs: POST=%d PUT=%d PATCH=%d DELETE=%d\n",
    $verbTotals['POST'],
    $verbTotals['PUT'],
    $verbTotals['PATCH'],
    $verbTotals['DELETE']
);
foreach ($inventory['stats'] as $file => $fileStats) {
    echo sprintf(
        "  %s POST=%d PUT=%d PATCH=%d DELETE=%d total=%d\n",
        $file,
        $fileStats['POST'],
        $fileStats['PUT'],
        $fileStats['PATCH'],
        $fileStats['DELETE'],
        array_sum($fileStats)
    );
}
echo sprintf(
    "classifications: protected_executable=%d public_exempt=%d unsafe_skip=%d known_failure=%d\n",
    $categoryTotals['protected_executable'],
    $categoryTotals['public_exempt'],
    $categoryTotals['unsafe_skip'],
    $categoryTotals['known_failure']
);

if ($errors) {
    echo "\nFAIL — " . count($errors) . " permission inventory violation(s):\n\n";
    foreach ($errors as $error) {
        echo '  ' . $error['location'] . "\n";
        echo '    ' . $error['reason'] . "\n\n";
    }
    echo 'permission-inventory runtime: ' . $runtime . "s\n";
    exit(1);
}

echo "\nOK — every configured mutating declaration has reviewed permission metadata and classification.\n";
echo 'permission-inventory runtime: ' . $runtime . "s\n";
exit(0);
