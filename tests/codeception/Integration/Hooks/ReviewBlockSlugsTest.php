<?php

namespace Tests\Integration\Hooks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * "fluent-cart/product-review-form" is the standalone form. The slug named
 * the button-and-drawer block during development, before the trigger and
 * the form became two blocks; it is reused for the form on purpose, since
 * nothing shipped carries the old block. The button is
 * "fluent-cart/write-a-review-button".
 */
class ReviewBlockSlugsTest extends WPTestCase
{
    protected function setUp(): void
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
        wp_set_current_user(0);
    }

    public function test_product_review_form_is_the_bare_form(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $form = $registry->get_registered('fluent-cart/product-review-form');

        $this->assertNotNull($form);
        $this->assertNotNull($registry->get_registered('fluent-cart/write-a-review-button'), 'The button is its own block');
        $this->assertArrayHasKey('layout', $form->attributes);
        $this->assertArrayNotHasKey('addReviewButtonText', $form->attributes, 'A bare form has no button to label');

        $product = (new ProductFactory())->create();
        $html = do_blocks('<!-- wp:fluent-cart/product-review-form {"query_type":"custom","product_id":' . $product->ID . '} /-->');

        $this->assertStringContainsString('fct-review-form-section-none', $html, 'The form, printed in place');
        $this->assertStringNotContainsString('data-open-review-drawer', $html, 'with no trigger');
    }
}
