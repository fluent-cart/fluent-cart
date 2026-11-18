<?php
/**
 * Product edit builder notice: every active page builder whose FluentCart add-on is not active becomes a candidate,
 * with that add-on's list entry, the builder's name and whether the plugin is installed. The page shows the first
 * one the admin has not closed. A user who cannot install or activate plugins gets none.
 */

use FluentCart\App\Services\PluginInstaller\AddonManager;

$addons = [
    'elementor-block'           => ['title' => 'Elementor Blocks', 'plugin_slug' => 'fluent-cart-elementor-blocks', 'plugin_file' => 'fct-test-missing/fct-test-missing.php'],
    'fluent-cart-bricks-blocks' => ['title' => 'Bricks Blocks', 'plugin_slug' => 'fluent-cart-bricks-blocks', 'plugin_file' => 'fluent-cart/fluent-cart.php'],
];

$both = [['key' => 'elementor-block', 'name' => 'Elementor'], ['key' => 'unknown', 'name' => 'Oxygen'], ['key' => 'fluent-cart-bricks-blocks', 'name' => 'Bricks']];

return [
    [
        'id'            => 'builder-addon-notice-pick',
        'name'          => 'Every builder without its add-on is a candidate, in order',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons, $both) {
            $previous = get_current_user_id();
            wp_set_current_user(1);

            FcTest::assertSame([], AddonManager::pickBuilderAddons($addons, []), 'No builder without its add-on, no notice');

            $picked = AddonManager::pickBuilderAddons($addons, $both);
            FcTest::assertSame(['Elementor', 'Bricks'], array_column($picked, 'builder'), 'Both builders, a builder with no add-on in the list is skipped');
            FcTest::assertSame('Elementor Blocks', $picked[0]['title'], 'The add-on list entry travels along');
            FcTest::assertSame(false, $picked[0]['is_installed'], 'A missing plugin file is not installed');
            FcTest::assertSame(true, $picked[1]['is_installed'], 'An existing plugin file is installed');

            wp_set_current_user($previous);
        },
    ],
    [
        'id'            => 'builder-addon-notice-capability',
        'name'          => 'A user who cannot install or activate plugins gets no candidates',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons, $both) {
            $previous = get_current_user_id();
            wp_set_current_user(0);

            FcTest::assertSame([], AddonManager::pickBuilderAddons($addons, $both), 'No plugin capabilities, no notice');

            wp_set_current_user($previous);
        },
    ],
];
