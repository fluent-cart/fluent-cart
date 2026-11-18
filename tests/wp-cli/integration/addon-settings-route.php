<?php
/**
 * The Features & addon list shows a gear icon for an active addon only when its
 * entry names the admin route its settings live on.
 */

use FluentCart\App\Http\Controllers\ModuleSettingsController;
use FluentCart\Framework\Support\Arr;

$addons = function () {
    $data = (new ModuleSettingsController())->getPluginAddons()->get_data();
    return isset($data['addons']) && is_array($data['addons']) ? $data['addons'] : [];
};

return [
    [
        'id'            => 'addon-settings-route-linked',
        'name'          => 'Fluent PDF and Shipping For FluentCart name their settings routes',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons) {
            $list = $addons();

            FcTest::assertSame('/settings/pdf-template', isset($list['fluent-pdf']['settings_route']) ? $list['fluent-pdf']['settings_route'] : null, 'Fluent PDF opens its template settings');
            FcTest::assertSame('/settings/shipping/carriers', isset($list['shipping-for-fluent-cart']['settings_route']) ? $list['shipping-for-fluent-cart']['settings_route'] : null, 'Shipping For FluentCart opens the carriers list');
        },
    ],
    [
        'id'            => 'addon-settings-route-shape',
        'name'          => 'Every addon settings route is an admin settings path',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons) {
            foreach ($addons() as $key => $addon) {
                if (!array_key_exists('settings_route', $addon)) {
                    continue;
                }

                FcTest::assert(
                    is_string($addon['settings_route']) && strpos($addon['settings_route'], '/settings/') === 0,
                    $key . ' settings_route must start with /settings/, got ' . var_export($addon['settings_route'], true)
                );
            }
        },
    ],
    [
        'id'            => 'addon-settings-route-autofill-no-gear',
        'name'          => 'Address Autofill shows no gear: its on/off switch is on the Features & addon page itself',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($addons) {
            $autofill = Arr::get($addons(), 'address-autofill-for-fluent-cart', []);

            FcTest::assert(!empty($autofill), 'Address Autofill is in the addon list');
            FcTest::assertSame('', Arr::get($autofill, 'redirect_url', '') . Arr::get($autofill, 'settings_route', ''), 'Address Autofill has no settings target, so the list shows no gear');
        },
    ],
];
