<?php

namespace Tests\Integration\Api;

use FluentCart\Api\StoreSettings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Regression test: isCheckoutPage() read $post->ID off the global $post
 * without checking it exists first, so any context where WP hasn't set up
 * $post yet (admin-ajax, REST, CLI, early hooks) triggered
 * "Attempt to read property 'ID' on null".
 */
class StoreSettingsIsCheckoutPageTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $post;
        $post = null;
        StoreSettings::clearCache();
    }

    protected function tearDown(): void
    {
        global $post;
        $post = null;
        StoreSettings::clearCache();
        parent::tearDown();
    }

    public function test_returns_false_when_global_post_is_missing(): void
    {
        $settings = new StoreSettings();

        $this->assertFalse($settings->isCheckoutPage());
    }

    public function test_returns_true_when_post_id_matches_checkout_page_id(): void
    {
        $settings = new StoreSettings();
        $settings->save(['checkout_page_id' => 321]);

        global $post;
        $post = new \WP_Post((object) ['ID' => 321]);

        $this->assertTrue($settings->isCheckoutPage());
    }

    public function test_returns_false_when_post_id_does_not_match_checkout_page_id(): void
    {
        $settings = new StoreSettings();
        $settings->save(['checkout_page_id' => 321]);

        global $post;
        $post = new \WP_Post((object) ['ID' => 654]);

        $this->assertFalse($settings->isCheckoutPage());
    }

    public function test_returns_false_when_global_post_is_not_a_wp_post(): void
    {
        $settings = new StoreSettings();
        $settings->save(['checkout_page_id' => 321]);

        global $post;
        $post = (object) ['ID' => 321];

        $this->assertFalse($settings->isCheckoutPage());
    }
}
