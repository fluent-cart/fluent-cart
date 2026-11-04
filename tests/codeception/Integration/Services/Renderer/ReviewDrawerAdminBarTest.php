<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The review drawer carries the same admin_bar_enabled class the cart
 * drawer does, for the same reason: both are now direct children of
 * <body> (see ReviewForm.js's getDrawer()), the admin bar is
 * position:fixed; top:0 above them, and without a class the stylesheet
 * can key off, the drawer's header renders under it — reproduced live
 * on a real product page before this fix landed.
 */
class ReviewDrawerAdminBarTest extends WPTestCase
{
    private int $postId = 0;

    public function setUp(): void
    {
        parent::setUp();

        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'review_permission_mode' => 'anyone',
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetReviewSettingsCache();

        $this->postId = self::factory()->post->create(['post_type' => 'fluent-products', 'post_status' => 'publish']);
        wp_set_current_user(0);
    }

    private function form(): string
    {
        ob_start();
        (new ProductReviewRenderer($this->postId))->renderForm();

        return (string) ob_get_clean();
    }

    public function test_the_drawer_panel_carries_the_class_when_the_admin_bar_shows(): void
    {
        add_filter('show_admin_bar', '__return_true');

        $html = $this->form();

        $this->assertMatchesRegularExpression(
            '/class="fct-review-drawer admin_bar_enabled" data-review-drawer-panel/',
            $html,
            'the panel should carry admin_bar_enabled when the bar is showing'
        );

        remove_filter('show_admin_bar', '__return_true');
    }

    public function test_the_drawer_panel_has_no_such_class_when_the_admin_bar_is_off(): void
    {
        add_filter('show_admin_bar', '__return_false');

        $html = $this->form();

        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\badmin_bar_enabled\b/',
            $html,
            'nothing should carry admin_bar_enabled when the bar is off'
        );

        remove_filter('show_admin_bar', '__return_false');
    }
}
