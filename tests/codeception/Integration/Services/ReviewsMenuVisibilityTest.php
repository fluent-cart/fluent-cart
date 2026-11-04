<?php

namespace Tests\Integration\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\AdminHelper;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\UserRoleFactory;

/**
 * The Reviews entries disappear when the store switches reviews off.
 *
 * The screen behind them already redirects to the dashboard when the module is
 * inactive, so an entry that survives is a menu item that bounces whoever
 * clicks it — the worst kind, because it looks like a broken page rather than
 * a feature the store turned off.
 *
 * Read per request on purpose, not at registration: a store that switches
 * reviews off must not need a second page load to see the menu follow.
 *
 * Spec: Cases/reviews.md — "Reviews admin menu"
 */
class ReviewsMenuVisibilityTest extends WPTestCase
{
    private function setReviewsActive(string $value): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = $value;
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();
    }

    /**
     * @return array<int, string> the Products dropdown's child keys
     */
    private function productsMenuChildren(): array
    {
        $method = new \ReflectionMethod(AdminHelper::class, 'getProductsMenu');
        $method->setAccessible(true);

        $menu = $method->invoke(null, 'admin.php?page=fluent-cart#/');

        return array_keys($menu['children'] ?? []);
    }

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(UserRoleFactory::createAdmin());
    }

    public function tearDown(): void
    {
        $this->setReviewsActive('yes');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_the_products_menu_offers_reviews_while_the_module_is_on(): void
    {
        $this->setReviewsActive('yes');

        $this->assertContains(
            'reviews',
            $this->productsMenuChildren(),
            'a store with reviews on can reach them from the Products menu'
        );
    }

    public function test_the_products_menu_drops_reviews_while_the_module_is_off(): void
    {
        $this->setReviewsActive('no');

        $this->assertNotContains(
            'reviews',
            $this->productsMenuChildren(),
            'the screen behind it redirects, so the entry must not be offered'
        );
    }

    /**
     * Switching the module back on brings it straight back — the entry is not
     * decided once at boot.
     */
    public function test_the_entry_follows_the_switch_within_the_same_request(): void
    {
        $this->setReviewsActive('no');
        $this->assertNotContains('reviews', $this->productsMenuChildren());

        $this->setReviewsActive('yes');
        $this->assertContains('reviews', $this->productsMenuChildren());
    }

    /**
     * The rest of the Products menu is unaffected either way: this hides one
     * child, not the group it lives in.
     */
    public function test_the_products_menu_itself_is_untouched(): void
    {
        $this->setReviewsActive('no');

        $this->assertContains(
            'product_attributes',
            $this->productsMenuChildren(),
            'Attributes has nothing to do with the reviews switch'
        );
    }
}
