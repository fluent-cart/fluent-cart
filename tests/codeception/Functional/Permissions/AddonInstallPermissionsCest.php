<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

/**
 * POST /integration/feed/install-plugin puts executable plugin code on the site.
 *
 * Its route metadata asks for 'integrations/manage', which a FluentCart Manager
 * carries — but a Manager is only a WP subscriber with the FluentCart admin cap,
 * so they hold neither install_plugins nor activate_plugins. The controller
 * therefore checks the WordPress capability itself: install_plugins when the
 * addon is absent, activate_plugins when it is present but switched off.
 *
 * The allow path is deliberately not exercised here: passing the gate hands the
 * request to BackgroundInstaller, which downloads from wordpress.org. What these
 * tests pin is that the denials hold and that nothing lands on disk.
 */
class AddonInstallPermissionsCest
{
    public function managerWithIntegrationsManageStillCannotInstallAnAddon(FunctionalTester $I): void
    {
        $I->loginAsManager();

        Assert::assertTrue(
            current_user_can('fluent_cart_admin'),
            'the Manager reaches FluentCart admin routes at all'
        );
        Assert::assertFalse(
            current_user_can('install_plugins'),
            'a Manager is a WP subscriber — this is the gap the capability check closes'
        );

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $wasActive = is_plugin_active('fluent-player/fluent-player.php');

        [$status, $body] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
            'addon' => 'fluent-player',
        ]);

        Assert::assertFalse($wasActive, 'the fixture starts with FluentPlayer switched off');
        Assert::assertSame(403, $status, 'integrations/manage must not by itself install a plugin');
        Assert::assertStringContainsString(
            'permission',
            strtolower(json_encode($body)),
            'the refusal should say it is a permission problem'
        );

        Assert::assertFalse(
            is_plugin_active('fluent-player/fluent-player.php'),
            'the refused request must not have activated the plugin'
        );
    }

    public function managerIsRefusedWhetherTheAddonIsPresentOrAbsent(FunctionalTester $I): void
    {
        $I->loginAsManager();

        // The controller picks activate_plugins for an addon already on disk and
        // install_plugins for one that is not. Which branch a given slug takes
        // depends on the machine, so probe several and require the same answer:
        // integrations/manage buys neither.
        foreach (['fluent-player', 'fluent-smtp', 'fluent-community', 'fluentform'] as $addon) {
            [$status] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
                'addon' => $addon,
            ]);

            Assert::assertSame(403, $status, $addon . ' must be refused for a Manager');
        }
    }

    public function installPermissionAloneDoesNotLetAUserActivatePluginCode(FunctionalTester $I): void
    {
        $I->loginAsManager();

        // WordPress lets a custom role hold install_plugins without activate_plugins.
        // BackgroundInstaller activates whatever it installs in the same request, so
        // this user must still be refused.
        // add_cap() on a re-fetched user leaves the cached current-user object with
        // the old capabilities, so grant it on the live one.
        $user = wp_get_current_user();
        $user->add_cap('install_plugins');

        // Belt and braces: if the gate ever regresses, the request must not be able
        // to reach wordpress.org and drop a plugin into the shared plugins directory.
        $blockHttp = function () {
            return new \WP_Error('http_blocked', 'outbound HTTP is blocked in tests');
        };
        add_filter('pre_http_request', $blockHttp, 1);

        Assert::assertTrue(current_user_can('install_plugins'), 'the cap under test is attached');
        Assert::assertFalse(current_user_can('activate_plugins'), 'and the other one is not');

        [$status, $body] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
            'addon' => 'fluent-smtp',
        ]);

        remove_filter('pre_http_request', $blockHttp, 1);
        $user->remove_cap('install_plugins');

        Assert::assertSame(403, $status, 'installing without activate_plugins must be refused');
        Assert::assertStringContainsString(
            'activate',
            strtolower(json_encode($body)),
            'the refusal names the capability that is missing'
        );
    }

    public function workerCannotInstallAnAddon(FunctionalTester $I): void
    {
        $I->loginAsWorker();

        [$status] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
            'addon' => 'fluent-player',
        ]);

        Assert::assertContains(
            $status,
            [401, 403],
            'a Worker has neither integrations/manage nor the WordPress capability'
        );
    }

    public function loggedOutVisitorCannotInstallAnAddon(FunctionalTester $I): void
    {
        $I->logout();

        [$status] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
            'addon' => 'fluent-player',
        ]);

        Assert::assertContains($status, [401, 403], 'the endpoint is not public');
    }

    public function anAddonOutsideTheAllowListIsRejectedBeforeAnyCapabilityCheck(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->post('/fluent-cart/v2/integration/feed/install-plugin', [
            'addon' => 'evil-plugin',
        ]);

        Assert::assertSame(422, $status, 'the allow-list still runs first, even for an administrator');
        Assert::assertStringContainsString(
            'cannot be installed',
            strtolower(json_encode($body)),
            'the allow-list refusal message is the one returned'
        );
    }
}
