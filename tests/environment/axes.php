<?php
/**
 * Phase 20 opt-in environment-axis contracts.
 */

return [
    'strict-sql' => [
        'name' => 'Strict SQL session enables both required hostile modes',
        'run'  => function () {
            global $wpdb;

            $modes = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $wpdb->get_var('SELECT @@SESSION.sql_mode'))
            )));

            FcTest::assert(
                in_array('ONLY_FULL_GROUP_BY', $modes, true),
                'strict SQL preflight enables ONLY_FULL_GROUP_BY'
            );
            FcTest::assert(
                in_array('STRICT_TRANS_TABLES', $modes, true),
                'strict SQL preflight enables STRICT_TRANS_TABLES'
            );
        },
    ],
    'non-utc' => [
        'name' => 'Non-UTC site time differs from UTC and activates the GMT assertion',
        'run'  => function () {
            $offset = (float) get_option('gmt_offset');
            $fixedTimestamp = strtotime('2026-07-31 12:00:00 UTC');
            $utc = gmdate('Y-m-d H:i:s', $fixedTimestamp);
            $zeroOffsetSiteTime = gmdate('Y-m-d H:i:s', $fixedTimestamp);
            $hostileSiteTime = wp_date(
                'Y-m-d H:i:s',
                $fixedTimestamp,
                wp_timezone()
            );

            FcTest::assert($offset != 0.0, 'timezone preflight uses a non-zero GMT offset');
            FcTest::assertSame(
                $utc,
                $zeroOffsetSiteTime,
                'at offset zero a site-local timestamp is byte-identical to UTC'
            );
            FcTest::assert(
                $hostileSiteTime !== $utc,
                'at the hostile offset a site-local timestamp differs from UTC'
            );

            $testSource = (string) file_get_contents(
                dirname(__DIR__) . '/integration/order-status-state-machine.php'
            );
            $productionSource = (string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Models/Order.php'
            );
            FcTest::assert(
                strpos(
                    $testSource,
                    'Processing to completed persists a current GMT completed_at timestamp'
                ) !== false
                && strpos($testSource, "\$completedLowerBound = gmdate('Y-m-d H:i:s');") !== false
                && strpos($productionSource, '$this->completed_at = DateTime::') !== false,
                'the one offset-zero-vacuous assertion remains source-pinned to its Order write'
            );
        },
    ],
    'pro-absent' => [
        'name' => 'FluentCart Pro is absent from the WordPress runtime',
        'run'  => function () {
            $activePlugins = (array) get_option('active_plugins', []);
            $configuredActive = in_array(
                'fluent-cart-pro/fluent-cart-pro.php',
                $activePlugins,
                true
            );
            $runtimeLoaded = defined('FLUENTCART_PRO_PLUGIN_VERSION');

            FcTest::assert(
                !$runtimeLoaded,
                'the Pro bootstrap constant is absent'
            );
            FcTest::assert(
                !class_exists('FluentCartPro\\App\\Modules\\Licensing\\LicensingModule', false),
                'no Pro licensing class was loaded'
            );

            WP_CLI::log(
                'Pro axis source state: configured_active='
                . ($configuredActive ? 'yes' : 'no')
                . ' runtime_loaded='
                . ($runtimeLoaded ? 'yes' : 'no')
            );
        },
    ],
];
