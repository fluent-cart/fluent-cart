<?php

namespace Tests\Integration\Hooks;

use FluentCart\Api\StoreSettings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The account-page rewrite rule is built from the saved slug, while emails link
 * to the page's live permalink. Moving the page under a parent left the slug
 * stale, so `/parent/account/order/{uuid}` links fell through to the blog index.
 */
class CustomerProfilePageSlugTest extends WPTestCase
{
    private $accountPageId;
    private $parentPageId;

    protected function setUp(): void
    {
        parent::setUp();
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
        StoreSettings::clearCache();

        $this->parentPageId = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'shop']);
        $this->accountPageId = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'account']);

        (new StoreSettings())->save(['customer_profile_page_id' => $this->accountPageId]);
    }

    protected function tearDown(): void
    {
        StoreSettings::clearCache();
        parent::tearDown();
    }

    private function savedSlug(): string
    {
        StoreSettings::clearCache();
        return (string) (new StoreSettings())->get('customer_profile_page_slug');
    }

    public function test_slug_follows_the_page_when_it_moves_under_a_parent(): void
    {
        $this->assertSame('account', $this->savedSlug());

        wp_update_post(['ID' => $this->accountPageId, 'post_parent' => $this->parentPageId]);

        $this->assertSame('shop/account', $this->savedSlug());
    }

    public function test_slug_follows_a_renamed_parent_page(): void
    {
        wp_update_post(['ID' => $this->accountPageId, 'post_parent' => $this->parentPageId]);
        wp_update_post(['ID' => $this->parentPageId, 'post_name' => 'store']);

        $this->assertSame('store/account', $this->savedSlug());
    }
}
