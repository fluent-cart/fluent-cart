<?php

namespace Tests\Integration\Templating;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Classic themes get the reviews by appending them to the product content. A page builder template
 * that already places a reviews widget must be able to turn that append off.
 */
class ProductReviewsContentAppendTest extends WPTestCase
{
    private $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ProductReviewService::resetRuntimeCache();

        $this->productId = (int) (new ProductFactory())->create([
            'post_title'   => 'ReviewAppend ' . wp_generate_password(6, false),
            'post_status'  => 'publish',
            'post_content' => '<p>Description</p>',
        ])->ID;
        $this->go_to(get_permalink($this->productId));
        $GLOBALS['post'] = get_post($this->productId);
    }

    protected function tearDown(): void
    {
        remove_all_filters('fluent_cart/product/append_reviews_to_content');
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    public function test_the_product_content_gets_the_reviews_by_default(): void
    {
        $content = apply_filters('the_content', get_post($this->productId)->post_content);

        $this->assertSame(1, substr_count($content, 'fct-product-reviews-section'));
    }

    public function test_stopping_the_reviews_still_runs_other_after_content_listeners(): void
    {
        add_filter('fluent_cart/product/append_reviews_to_content', '__return_false');
        $listener = static function () {
            echo '<p class="third-party-after-content"></p>';
        };
        add_action('fluent_cart/product/after_product_content', $listener);

        $content = apply_filters('the_content', get_post($this->productId)->post_content);
        remove_action('fluent_cart/product/after_product_content', $listener);

        $this->assertStringContainsString('third-party-after-content', $content);
        $this->assertStringNotContainsString('fct-product-reviews-section', $content);
    }

    public function test_a_filter_can_stop_the_reviews_being_appended_to_the_content(): void
    {
        $contexts = [];
        add_filter('fluent_cart/product/append_reviews_to_content', static function ($append, $context) use (&$contexts) {
            $contexts[] = $context;
            return false;
        }, 10, 2);

        $content = apply_filters('the_content', get_post($this->productId)->post_content);

        $this->assertSame([['post_id' => $this->productId]], $contexts, 'the filter gets a read-only context array');

        $this->assertStringNotContainsString('fct-product-reviews-section', $content);
        $this->assertStringContainsString('Description', $content, 'the description itself is still rendered');
    }
}
