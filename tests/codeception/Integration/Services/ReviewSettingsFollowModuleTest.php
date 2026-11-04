<?php

namespace Tests\Integration\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\CPT\Pages;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\EmailNotificationConfigFactory;

/**
 * Switching the reviews module off takes the review settings with it, and
 * the product-rating block goes quiet rather than missing.
 *
 * The Order Review page selector and the "Show Reviews In Single Page"
 * toggle configure a feature the store turned off, so they leave the form.
 * Their stored values stay — save() merges the form over what is stored.
 *
 * The product-rating block ships inside the Shop App, Product Info and
 * Related Products card templates. Unregistering it would greet the editor
 * with "your site doesn't include support for this block" on every card;
 * instead it stays registered, leaves the inserter, and renders nothing.
 */
class ReviewSettingsFollowModuleTest extends WPTestCase
{
    private function setReviewsActive(string $value): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = $value;
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();
    }

    public function tearDown(): void
    {
        $this->setReviewsActive('yes');
        (new EmailNotificationConfigFactory())->reset();
        parent::tearDown();
    }

    private function pagesSetupKeys(): array
    {
        $fields = (new StoreSettings())->fields();

        return array_keys($fields['setting_tabs']['schema']['pages_setup']['schema']);
    }

    private function singleProductToggleKeys(): array
    {
        $fields = (new StoreSettings())->fields();

        return array_keys($fields['setting_tabs']['schema']['single_product_setup']['schema']['product_settings_grid']['schema']['fields']['schema']);
    }

    public function test_the_review_settings_are_offered_while_the_module_is_on(): void
    {
        $this->setReviewsActive('yes');

        $this->assertContains('order_review_page_grid', $this->pagesSetupKeys());
        $this->assertContains('hr_order_review', $this->pagesSetupKeys());
        $this->assertContains('show_reviews_in_single_page', $this->singleProductToggleKeys());
        $this->assertContains('show_reviews_note', $this->singleProductToggleKeys());
    }

    public function test_the_review_settings_leave_the_form_while_the_module_is_off(): void
    {
        $this->setReviewsActive('no');

        $pages = $this->pagesSetupKeys();
        $this->assertNotContains('order_review_page_grid', $pages, 'the Order Review page selector configures a feature that is off');
        $this->assertNotContains('hr_order_review', $pages, 'and its divider goes with it');
        $this->assertContains('receipt_page_grid', $pages, 'the neighbouring page selectors stay');
        $this->assertContains('checkout_page_grid', $pages);

        $toggles = $this->singleProductToggleKeys();
        $this->assertNotContains('show_reviews_in_single_page', $toggles);
        $this->assertNotContains('show_reviews_note', $toggles);
        $this->assertContains('show_relevant_product_in_single_page', $toggles, 'the other single-page toggles stay');
    }

    public function test_a_stored_order_review_page_survives_a_save_made_while_the_module_is_off(): void
    {
        $settings = new StoreSettings();
        $settings->save(['order_review_page_id' => 4242, 'show_reviews_in_single_page' => 'no']);

        $this->setReviewsActive('no');
        // What the form sends when the review fields are not on it.
        (new StoreSettings())->save(['show_relevant_product_in_single_page' => 'yes']);

        $stored = new StoreSettings();
        $this->assertSame(4242, (int) $stored->get('order_review_page_id'), 'a field left off the form keeps its value');
        $this->assertSame('no', $stored->get('show_reviews_in_single_page'));
    }

    public function test_the_order_review_page_is_not_a_missing_page_while_the_module_is_off(): void
    {
        $this->setReviewsActive('yes');
        $this->assertArrayHasKey('order_review', (new Pages())->corePages(), 'wanted while reviews are on');
        $this->assertArrayHasKey('order_review', (new Pages())->pages());

        $this->setReviewsActive('no');
        $pages = (new Pages())->corePages();
        $this->assertArrayNotHasKey('order_review', $pages, 'the dashboard must not ask for a page the store cannot use');
        $this->assertArrayNotHasKey('order_review', (new Pages())->pages());
        $this->assertSame(['checkout', 'cart', 'receipt', 'shop', 'customer_profile'], array_keys($pages), 'the other core pages, in their order');
    }

    public function test_the_review_notifications_leave_the_email_settings_while_the_module_is_off(): void
    {
        $reviewNames = ['review_submitted_admin', 'review_approved_customer', 'review_replied_customer'];

        // A store's own subject, to be found again when the module returns.
        $configFactory = new EmailNotificationConfigFactory();
        $configFactory->reset();
        $configFactory->withNotificationConfig('review_approved_customer', ['active' => 'no', 'subject' => 'Kept while off']);

        $this->setReviewsActive('yes');
        $listed = array_keys(EmailNotifications::getNotifications());
        foreach ($reviewNames as $name) {
            $this->assertContains($name, $listed, 'offered while reviews are on');
        }

        $this->setReviewsActive('no');
        $listed = array_keys(EmailNotifications::getNotifications());
        foreach ($reviewNames as $name) {
            $this->assertNotContains($name, $listed, 'not offered while reviews are off');
        }
        $this->assertContains('order_paid_customer', $listed, 'the other notifications stay');
        $this->assertSame([], EmailNotifications::activeNotificationNamesOfEvent('review_approved_done'), 'and nothing would send');

        $this->setReviewsActive('yes');
        $approved = EmailNotifications::getNotifications()['review_approved_customer'];
        $this->assertSame('Kept while off', $approved['settings']['subject'], 'the stored settings came back untouched');
        $this->assertSame('no', $approved['settings']['active']);
    }

    public function test_the_rating_block_stays_registered_but_silent_while_the_module_is_off(): void
    {
        $this->assertTrue(
            \WP_Block_Type_Registry::get_instance()->is_registered('fluent-cart/product-rating'),
            'the card templates carry the block, so it must always parse'
        );

        $this->setReviewsActive('no');
        $block = new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductRatingBlockEditor();
        $this->assertFalse($block->supports()['inserter'], 'out of the inserter while off');
        $this->assertFalse($block->localizeData()['fluent_cart_product_rating_data']['reviews_active'], 'the editor is told to draw nothing');
        $this->assertSame('', $block->render(['query_type' => 'custom', 'product_id' => 0]), 'nothing on the page either');

        $this->setReviewsActive('yes');
        $this->assertTrue($block->supports()['inserter']);
        $this->assertTrue($block->localizeData()['fluent_cart_product_rating_data']['reviews_active']);
    }
}
