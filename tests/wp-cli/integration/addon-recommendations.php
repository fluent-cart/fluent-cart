<?php
/**
 * Dashboard "Recommended for your store": an add-on is recommended only when the store's signal matches and the
 * add-on is not active. Builders stay out: the Getting Started checklist already lists them.
 */

use FluentCart\App\Services\PluginInstaller\AddonManager;

$addons = function (array $active = []) {
    $list = [];
    foreach (['fluent-cart-customer-rights', 'fluent-cart-migrator', 'shipping-for-fluent-cart', 'elementor-block'] as $key) {
        $list[$key] = [
            'title'       => $key,
            'logo'        => 'https://example.test/' . $key . '.svg',
            'plugin_slug' => $key,
            'is_installed' => in_array($key, $active, true),
            'is_active'   => in_array($key, $active, true),
        ];
    }
    return $list;
};

$keys = function (array $items) {
    return array_map(function ($item) {
        return $item['key'];
    }, $items);
};

return [
    [
        'id'            => 'addon-recommendations-signals',
        'name'          => 'Recommendations follow the store signals',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons, $keys) {
            $none = AddonManager::pickRecommendations($addons(), ['country' => 'US', 'eu' => false, 'other_store' => '', 'physical' => false]);
            FcTest::assertSame([], $keys($none), 'No signal, no recommendation');

            $all = AddonManager::pickRecommendations($addons(), ['country' => 'FR', 'eu' => true, 'other_store' => 'WooCommerce', 'physical' => true]);
            FcTest::assertSame(['fluent-cart-customer-rights', 'fluent-cart-migrator', 'shipping-for-fluent-cart'], $keys($all), 'EU store, WooCommerce and physical products; no builder');
            FcTest::assertSame('', $all[0]['badge'], 'Only Germany gets the legal badge');
            FcTest::assert(strpos($all[1]['reason'], 'WooCommerce') !== false, 'The migrator reason names the other store plugin');
        },
    ],
    [
        'id'            => 'addon-recommendations-germany-active',
        'name'          => 'Germany is labelled; active add-ons and unknown add-ons are skipped',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons, $keys) {
            $germany = AddonManager::pickRecommendations($addons(), ['country' => 'DE', 'eu' => true, 'other_store' => '', 'physical' => false]);
            FcTest::assertSame(['fluent-cart-customer-rights'], $keys($germany), 'A German store gets Customer Rights');
            FcTest::assertSame('Required in Germany', $germany[0]['badge'], 'A German store sees the legal badge');

            $active = AddonManager::pickRecommendations($addons(['fluent-cart-customer-rights']), ['country' => 'DE', 'eu' => true, 'other_store' => '', 'physical' => true]);
            FcTest::assertSame(['shipping-for-fluent-cart'], $keys($active), 'An active add-on is not recommended');

            $missing = AddonManager::pickRecommendations([], ['country' => 'DE', 'eu' => true, 'other_store' => 'WooCommerce', 'physical' => true]);
            FcTest::assertSame([], $keys($missing), 'An add-on missing from the list is not recommended');
        },
    ],
    [
        'id'            => 'addon-recommendations-cheap-status',
        'name'          => 'Without a status in the list, the active plugins option and the plugin file decide',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($keys) {
            $activeFile = '';
            foreach ((array)get_option('active_plugins', []) as $file) {
                if (strpos($file, 'fluent-cart/') !== 0) {
                    $activeFile = $file;
                    break;
                }
            }
            FcTest::assert($activeFile !== '', 'The test site has another active plugin');

            $list = [
                'fluent-cart-migrator'     => ['title' => 'Migrator', 'plugin_slug' => 'fct-test-missing', 'plugin_file' => 'fct-test-missing/fct-test-missing.php'],
                'shipping-for-fluent-cart' => ['title' => 'Shipping', 'plugin_slug' => 'active', 'plugin_file' => $activeFile],
            ];
            $items = AddonManager::pickRecommendations($list, ['country' => 'US', 'eu' => false, 'other_store' => 'WooCommerce', 'physical' => true]);

            FcTest::assertSame(['fluent-cart-migrator'], $keys($items), 'An active plugin is skipped, a missing one is recommended');
            FcTest::assertSame(false, $items[0]['is_installed'], 'A plugin file that does not exist is not installed');
        },
    ],
    [
        'id'            => 'addon-recommendations-in-addon-list',
        'name'          => 'The add-on list response carries the recommendations',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $data = (new \FluentCart\App\Http\Controllers\ModuleSettingsController())->getPluginAddons()->get_data();

            FcTest::assert(isset($data['addons']) && is_array($data['addons']), 'The add-on list is still there');
            FcTest::assert(isset($data['recommendations']) && is_array($data['recommendations']), 'Recommendations are a list');
            foreach ($data['recommendations'] as $item) {
                FcTest::assert(empty($data['addons'][$item['key']]['is_active']), $item['key'] . ' is recommended only while not active');
            }
        },
    ],
];
