<?php
/**
 * S3 — permission smoke for source-derived mutating REST declarations.
 *
 * Protected routes are dispatched in-process twice: anonymous and as a fresh
 * sacrificial subscriber. A rest_dispatch_request fuse runs only after the
 * route permission callback succeeds and blocks the controller with status
 * 418, so a missing guard is observable without permitting a write.
 *
 * Usage:
 *   wp eval-file tests/wp-cli/bin/run-permissions.php
 *   wp eval-file tests/wp-cli/bin/run-permissions.php -- filter=upload-editor-file auth=anonymous
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/fixtures.php';
require_once __DIR__ . '/../lib/permission-route-source.php';

FcTest::boot();
FcTest::interceptCronMutations();

$pluginDir = dirname(__DIR__, 3);
$config = require __DIR__ . '/../suite.config.php';
$manifest = require __DIR__ . '/../permissions/routes.manifest.php';
$sourceInventory = FcPermissionRouteSource::discover($pluginDir, $config);

if ($sourceInventory['errors']) {
    foreach ($sourceInventory['errors'] as $error) {
        WP_CLI::warning($error['location'] . ' — ' . $error['reason']);
    }
    WP_CLI::error('Permission source inventory is invalid; refusing runtime dispatch.');
}
if (
    !is_array($manifest)
    || !isset($manifest['route_files'], $manifest['classifications'])
    || !is_array($manifest['route_files'])
    || !is_array($manifest['classifications'])
) {
    WP_CLI::error('Permission manifest must provide route_files and classifications arrays.');
}
if (array_values($manifest['route_files']) !== $sourceInventory['route_files']) {
    WP_CLI::error('Permission manifest route_files differ from tests/wp-cli/suite.config.php.');
}

$declarations = [];
$categoryTotals = [
    'protected_executable' => 0,
    'public_exempt'        => 0,
    'unsafe_skip'          => 0,
    'known_failure'        => 0,
];
foreach ($manifest['classifications'] as $id => $classification) {
    if (!isset($sourceInventory['declarations'][$id])) {
        WP_CLI::error('Stale permission classification has no source declaration: ' . $id);
    }
    if (
        !is_array($classification)
        || !isset($classification['classification'], $categoryTotals[$classification['classification']])
    ) {
        WP_CLI::error('Invalid permission classification for ' . $id);
    }
    if (empty($classification['reason'])) {
        WP_CLI::error('Permission classification lacks a precise reason: ' . $id);
    }

    $declaration = array_merge($sourceInventory['declarations'][$id], $classification);
    if (
        $declaration['classification'] === 'protected_executable'
        && $declaration['transport'] !== 'rest'
    ) {
        WP_CLI::error('Unsafe protected-executable classification at ' . $id);
    }
    if (
        $declaration['classification'] === 'public_exempt'
        && !in_array($declaration['policy'], ['PublicPolicy', 'CustomerFrontendPolicy'], true)
    ) {
        WP_CLI::error('Public/exempt source policy mismatch at ' . $id);
    }
    foreach ((array) (isset($declaration['bindings']) ? $declaration['bindings'] : []) as $placeholder => $resolver) {
        if (
            strpos($declaration['route'], '{' . $placeholder . '}') === false
            || !in_array(
                $resolver,
                ['existing_product_id', 'existing_order_id', 'existing_transaction_id'],
                true
            )
        ) {
            WP_CLI::error('Invalid read-only route binding at ' . $id);
        }
    }

    $declarations[$id] = $declaration;
    $categoryTotals[$declaration['classification']]++;
}
foreach ($sourceInventory['declarations'] as $id => $declaration) {
    if (!isset($declarations[$id])) {
        WP_CLI::error(
            'Missing permission classification for ' . $declaration['verb']
                . ' /' . $declaration['route'] . ' at ' . $id
        );
    }
}

$filter = '';
$authFilter = '';
foreach ((array) $args as $arg) {
    if (strpos($arg, '--filter=') === 0) {
        $filter = substr($arg, 9);
    } elseif (strpos($arg, 'filter=') === 0) {
        $filter = substr($arg, 7);
    } elseif (strpos($arg, '--auth=') === 0) {
        $authFilter = substr($arg, 7);
    } elseif (strpos($arg, 'auth=') === 0) {
        $authFilter = substr($arg, 5);
    }
}
if ($authFilter !== '' && !in_array($authFilter, ['anonymous', 'subscriber'], true)) {
    WP_CLI::error('Permission auth filter must be anonymous or subscriber.');
}

$stats = [];
foreach ($sourceInventory['route_files'] as $file) {
    $stats[$file] = [
        'POST'       => 0,
        'PUT'        => 0,
        'PATCH'      => 0,
        'DELETE'     => 0,
        'protected'  => 0,
        'exempt'     => 0,
        'unsafe'     => 0,
        'known'      => 0,
    ];
}
foreach ($declarations as $declaration) {
    $file = $declaration['source_file'];
    $stats[$file][$declaration['verb']]++;
    $categoryKey = [
        'protected_executable' => 'protected',
        'public_exempt'        => 'exempt',
        'unsafe_skip'          => 'unsafe',
        'known_failure'        => 'known',
    ][$declaration['classification']];
    $stats[$file][$categoryKey]++;
}

$cleared = FcTest::clearCaches();
$protectedRoutes = $categoryTotals['protected_executable'];
FcTest::note(
    'FluentCart permission smoke — ' . count($declarations) . ' mutating declarations, '
        . $protectedRoutes . ' protected executable routes, '
        . ($protectedRoutes * 2) . ' denial cases'
        . ($filter !== '' ? ' (filter: ' . $filter . ')' : '')
        . ($authFilter !== '' ? ' (auth: ' . $authFilter . ')' : '')
        . ' — cleared ' . $cleared . " cache entries\n"
);
FcTest::note('Permission inventory by configured route file:');
foreach ($stats as $file => $fileStats) {
    FcTest::note(sprintf(
        '  %s POST=%d PUT=%d PATCH=%d DELETE=%d protected=%d public_exempt=%d unsafe_skips=%d known_failures=%d',
        $file,
        $fileStats['POST'],
        $fileStats['PUT'],
        $fileStats['PATCH'],
        $fileStats['DELETE'],
        $fileStats['protected'],
        $fileStats['exempt'],
        $fileStats['unsafe'],
        $fileStats['known']
    ));
}
FcTest::note(sprintf(
    "\nInventory totals: protected_executable=%d anonymous_cases=%d subscriber_cases=%d public_exempt=%d unsafe_skips=%d known_failures=%d\n",
    $protectedRoutes,
    $protectedRoutes,
    $protectedRoutes,
    $categoryTotals['public_exempt'],
    $categoryTotals['unsafe_skip'],
    $categoryTotals['known_failure']
));

foreach ($declarations as $declaration) {
    if (!in_array($declaration['classification'], ['public_exempt', 'unsafe_skip', 'known_failure'], true)) {
        continue;
    }
    FcTest::note(sprintf(
        '  %s %s /%s [%s] — %s',
        strtoupper(str_replace('_', '-', $declaration['classification'])),
        $declaration['verb'],
        $declaration['route'],
        $declaration['source_file'] . ':' . $declaration['source_line'],
        $declaration['reason']
    ));
}
FcTest::note('');

global $wpdb;

$startingCounts = FcTest::protectedBaseline();
$readProtectedCounts = static function () {
    return FcTest::protectedCounts();
};
$assertProtectedCounts = static function ($context) use (
    $readProtectedCounts,
    &$startingCounts
) {
    $actual = $readProtectedCounts();
    if ($actual !== $startingCounts) {
        FcTest::fail(
            $context . "\n  PROTECTED TABLE CHANGED: start="
            . wp_json_encode($startingCounts)
            . ' current=' . wp_json_encode($actual)
        );
    }
    return $actual;
};

FcTest::note(
    'internal protected run-start: '
    . FcProtectedTables::format($startingCounts)
);

if (!function_exists('wp_delete_user')) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
}

$subscriberId = 0;
$subscriberDeleted = false;
$cleanupSubscriber = static function () use (&$subscriberId, &$subscriberDeleted) {
    if (!$subscriberId || $subscriberDeleted) {
        return true;
    }

    $deleted = wp_delete_user($subscriberId);
    if ($deleted && !get_userdata($subscriberId)) {
        $subscriberDeleted = true;
        return true;
    }

    return false;
};
register_shutdown_function($cleanupSubscriber);

$suffix = strtolower(wp_generate_password(10, false, false));
$subscriberId = wp_insert_user([
    'user_login' => 'fct_permission_' . $suffix,
    'user_pass'  => wp_generate_password(32, true, true),
    'user_email' => 'fct-permission-' . $suffix . '@example.invalid',
    'role'       => 'subscriber',
]);
if (is_wp_error($subscriberId)) {
    WP_CLI::error('Could not create sacrificial subscriber: ' . $subscriberId->get_error_message());
}
$subscriberId = (int) $subscriberId;
delete_user_meta($subscriberId, '_fluent_cart_admin_role');
$subscriber = get_userdata($subscriberId);
if (
    !$subscriber
    || !in_array('subscriber', (array) $subscriber->roles, true)
    || user_can($subscriber, 'manage_options')
    || user_can($subscriber, 'fluent_cart_admin')
) {
    $cleanupSubscriber();
    WP_CLI::error('Sacrificial user is not a clean low-privilege subscriber.');
}
FcTest::note(
    'subscriber fixture: created fresh ID ' . $subscriberId
        . '; role=subscriber manage_options=no fluent_cart_admin=no'
);

$activeGate = null;
$controllerFuse = static function ($dispatchResult, $request) use (&$activeGate) {
    if (!is_array($activeGate)) {
        return $dispatchResult;
    }
    if (
        strtoupper($request->get_method()) !== $activeGate['method']
        || rtrim($request->get_route(), '/') !== $activeGate['path']
    ) {
        return $dispatchResult;
    }

    return new WP_Error(
        'fc_permission_gate_bypassed',
        'Permission callback allowed the request; mutating controller dispatch was blocked by the test fuse.',
        ['status' => 418]
    );
};
add_filter('rest_dispatch_request', $controllerFuse, PHP_INT_MAX, 4);

$refreshPluginUser = static function () {
    \FluentCart\App\Helpers\Helper::getCurrentUser(true);
};
// A store without an order or a transaction gets an owned fixture set (customer, order, transaction)
// from FcFixture, which checks ownership and residue on cleanup. The order and transaction routes bind
// to it. The baseline is re-read once it exists; the end check compares against the original counts.
$fixtureOrderId = 0;
$fixtureTransactionId = 0;
$fixtureCleaned = true;
$cleanupFixtureOrder = static function () use (&$fixtureCleaned) {
    if (!$fixtureCleaned) {
        FcFixture::cleanupAll();
        $fixtureCleaned = true;
    }
};
register_shutdown_function(static function () use ($cleanupFixtureOrder) {
    try {
        $cleanupFixtureOrder();
    } catch (\Throwable $e) {
        WP_CLI::warning('Permission order fixture shutdown cleanup failed: ' . $e->getMessage());
    }
});
$existingProductId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> %s ORDER BY ID ASC LIMIT 1",
    \FluentCart\App\CPT\FluentProducts::CPT_NAME,
    'auto-draft'
));
if ($existingProductId <= 0) {
    // Before any fixture row exists, so there is nothing to clean but the subscriber.
    $cleanupSubscriber();
    WP_CLI::error('Required read-only permission binding is unavailable: existing_product_id');
}
$existingOrderId = (int) $wpdb->get_var(
    'SELECT id FROM `' . $wpdb->prefix . 'fct_orders` ORDER BY id ASC LIMIT 1'
);
$existingTransactionId = (int) $wpdb->get_var(
    'SELECT id FROM `' . $wpdb->prefix . 'fct_order_transactions` ORDER BY id ASC LIMIT 1'
);
if ($existingOrderId <= 0 || $existingTransactionId <= 0) {
    try {
        FcFixture::initialize(
            getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY') !== false ? getenv('WP_PLUGIN_TEST_FIXTURE_IDENTITY') : null
        );
        $fixtureCleaned = false;
        FcFixture::customer();
        $fixtureOrderId = (int) FcFixture::order()->id;
        $transaction = \FluentCart\App\Models\OrderTransaction::query()->create([
            'order_id'            => $fixtureOrderId,
            'order_type'          => 'payment',
            'vendor_charge_id'    => FcFixture::identity(),
            'payment_method'      => '',
            'payment_mode'        => 'test',
            'payment_method_type' => '',
            'currency'            => 'USD',
            'transaction_type'    => 'charge',
            'card_last_4'         => 0,
            'card_brand'          => '',
            'status'              => 'pending',
            'total'               => 1000,
            'rate'                => 1,
        ]);
        $fixtureTransactionId = isset($transaction->id) ? (int) $transaction->id : 0;
        if ($fixtureTransactionId <= 0) {
            throw new RuntimeException('Transaction create returned no ID.');
        }
    } catch (\Throwable $e) {
        $message = 'Could not create the permission order fixture: ' . $e->getMessage();
        $cleanupSubscriber();
        try {
            $cleanupFixtureOrder();
        } catch (\Throwable $cleanupError) {
            $message .= '; cleanup also failed: ' . $cleanupError->getMessage();
        }
        WP_CLI::error($message);
    }
    $startingCounts = $readProtectedCounts();
    FcTest::note(
        'permission order fixture: created owned customer, order and transaction; per-route baseline re-read: '
        . FcProtectedTables::format($startingCounts)
    );
}
$bindingValues = [
    'existing_product_id'     => $existingProductId,
    'existing_order_id'       => $fixtureOrderId ?: $existingOrderId,
    'existing_transaction_id' => $fixtureTransactionId ?: $existingTransactionId,
];
$resolveRoute = static function ($route, array $bindings) use ($bindingValues) {
    return preg_replace_callback('/\{([^{}]+)\}/', static function ($matches) use (
        $bindings,
        $bindingValues
    ) {
        $placeholder = $matches[1];
        if (!isset($bindings[$placeholder])) {
            return '2147483647';
        }

        $bindingName = $bindings[$placeholder];
        return (string) $bindingValues[$bindingName];
    }, $route);
};
$selectedRoutes = 0;
$selectedCases = 0;

try {
    foreach ($declarations as $declaration) {
        if ($declaration['classification'] !== 'protected_executable') {
            continue;
        }

        $searchable = implode(' ', [
            $declaration['id'],
            $declaration['verb'],
            $declaration['route'],
            $declaration['handler'],
            $declaration['policy'],
            implode(',', $declaration['permissions']),
        ]);
        if ($filter !== '' && stripos($searchable, $filter) === false) {
            continue;
        }

        $selectedRoutes++;
        $resolvedRoute = $resolveRoute(
            $declaration['route'],
            isset($declaration['bindings']) ? $declaration['bindings'] : []
        );
        $fullPath = '/' . trim($config['rest_namespace'], '/')
            . '/' . ltrim($resolvedRoute, '/');
        $fullPath = rtrim($fullPath, '/');

        $authCases = [
            'anonymous' => [
                'user_id'  => 0,
                'expected' => (int) $declaration['expected_anonymous'],
            ],
            'subscriber' => [
                'user_id'  => $subscriberId,
                'expected' => (int) $declaration['expected_subscriber'],
            ],
        ];
        foreach ($authCases as $authLabel => $authCase) {
            if ($authFilter !== '' && $authLabel !== $authFilter) {
                continue;
            }
            $selectedCases++;
            $label = sprintf(
                '%s /%s [%s] %s at %s:%d',
                $declaration['verb'],
                $resolvedRoute,
                $authLabel,
                $declaration['policy'],
                $declaration['source_file'],
                $declaration['source_line']
            );

            FcTest::case($label, function () use (
                $authCase,
                $authLabel,
                $declaration,
                $resolvedRoute,
                $fullPath,
                $refreshPluginUser,
                &$activeGate,
                $assertProtectedCounts
            ) {
                wp_set_current_user($authCase['user_id']);
                $refreshPluginUser();
                $activeGate = [
                    'method' => $declaration['verb'],
                    'path'   => $fullPath,
                ];

                try {
                    $result = FcTest::rest(
                        $declaration['verb'],
                        $resolvedRoute,
                        isset($declaration['params']) ? $declaration['params'] : []
                    );

                    if ($result['db_error'] !== '') {
                        FcTest::fail(
                            $declaration['verb'] . ' /' . $resolvedRoute
                                . ' [' . $authLabel . "]\n  DATABASE ERROR: "
                                . $result['db_error']
                        );
                    }
                    if ($result['is_exception']) {
                        FcTest::fail(
                            $declaration['verb'] . ' /' . $resolvedRoute
                                . ' [' . $authLabel . "]\n  PLUGIN EXCEPTION: "
                                . $result['message']
                        );
                    }

                    $responseCode = is_array($result['data']) && isset($result['data']['code'])
                        ? (string) $result['data']['code']
                        : '(missing)';
                    if (
                        $result['status'] !== $authCase['expected']
                        || $responseCode !== 'rest_forbidden'
                    ) {
                        $response = wp_json_encode($result['data']);
                        FcTest::fail(sprintf(
                            "%s /%s [%s]\n  expected authentication/authorization rejection: status %d, code rest_forbidden\n  actual status: %d\n  actual code: %s\n  actual response: %s",
                            $declaration['verb'],
                            $resolvedRoute,
                            $authLabel,
                            $authCase['expected'],
                            $result['status'],
                            $responseCode,
                            $response === false ? '[unencodable]' : $response
                        ));
                    }
                } finally {
                    $activeGate = null;
                    $assertProtectedCounts(
                        $declaration['verb'] . ' /' . $resolvedRoute . ' [' . $authLabel . ']'
                    );
                }
            });
        }
    }
} finally {
    $activeGate = null;
    remove_filter('rest_dispatch_request', $controllerFuse, PHP_INT_MAX);
    wp_set_current_user(0);
    $refreshPluginUser();
    $cleanupOk = $cleanupSubscriber();
    // Fixture rows sit in protected tables, so a failed cleanup is a hard failure like a changed count.
    // It halts here so a probe that threw cannot hide it.
    try {
        $cleanupFixtureOrder();
    } catch (\Throwable $e) {
        restore_error_handler();
        WP_CLI::log(
            'HARD FAILURE — Permission order fixture cleanup failed: ' . $e->getMessage()
            . '; subscriber cleanup ' . ($cleanupOk ? 'ok' : 'failed')
        );
        WP_CLI::halt(97);
    }
}

if ($filter !== '' && $selectedRoutes === 0) {
    WP_CLI::error('Permission filter matched no protected executable route: ' . $filter);
}
if (!$cleanupOk || get_userdata($subscriberId)) {
    WP_CLI::error('Subscriber cleanup failed for user ID ' . $subscriberId . '.');
}
FcTest::note(
    'subscriber cleanup: deleted fresh ID ' . $subscriberId . '; lookup=absent'
);

$endingCounts = $readProtectedCounts();
FcTest::note(
    'internal protected run-end: '
    . FcProtectedTables::format($endingCounts)
    . '; selected_routes=' . $selectedRoutes
    . ' selected_cases=' . $selectedCases
);

FcTest::finish('PERMISSIONS');
