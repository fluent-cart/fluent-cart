<?php

namespace Tests\Integration\FluentPlayer;

use FluentCart\App\Modules\FluentPlayer\FluentPlayerAdminAssets;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The script that pushes the Video tab into the Product Gallery dialog is
 * enqueued on `fluent_cart/loading_app` whether or not FluentPlayer is active,
 * so the tab can offer install/activate.
 */
class FluentPlayerAdminAssetsTest extends WPTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/FluentPlayerStubs.php';
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/fluent_player/is_active');
        wp_dequeue_script(FluentPlayerAdminAssets::HANDLE);
        wp_deregister_script(FluentPlayerAdminAssets::HANDLE);
        parent::tearDown();
    }

    public function test_enqueues_the_gallery_tab_script_when_active(): void
    {
        (new FluentPlayerAdminAssets())->enqueue();

        $this->assertTrue(wp_script_is(FluentPlayerAdminAssets::HANDLE, 'enqueued'));
        $deps = wp_scripts()->registered[FluentPlayerAdminAssets::HANDLE]->deps;
        $this->assertContains('fluent-cart_global_admin_hooks', $deps, 'runs after the admin hooks object exists');
    }

    public function test_still_enqueues_the_script_when_the_integration_is_off(): void
    {
        add_filter('fluent_cart/fluent_player/is_active', '__return_false');

        (new FluentPlayerAdminAssets())->enqueue();

        $this->assertTrue(wp_script_is(FluentPlayerAdminAssets::HANDLE, 'enqueued'));
    }

    public function test_is_hooked_to_loading_app(): void
    {
        $assets = new FluentPlayerAdminAssets();
        $assets->register();

        $this->assertNotFalse(has_action('fluent_cart/loading_app', [$assets, 'enqueue']));
    }
}
