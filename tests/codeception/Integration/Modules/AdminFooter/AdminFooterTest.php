<?php

namespace Tests\Integration\Modules\AdminFooter;

use FluentCart\App\Modules\AdminFooter\AdminFooter;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * WP admin footer on FluentCart screens: feedback link + FluentCart versions.
 */
class AdminFooterTest extends WPTestCase
{
    public function test_footer_text_links_to_wordpress_org_feedback_without_rating_request(): void
    {
        $text = (new AdminFooter())->filterFooterText('Thank you for creating with WordPress.');

        $this->assertStringContainsString('Enjoying FluentCart? <a ', $text);
        $this->assertStringContainsString('>Tell us what you think</a>', $text);
        $this->assertStringContainsString('href="' . esc_url(AdminFooter::FEEDBACK_URL) . '"', $text);
        $this->assertSame('https://wordpress.org/support/plugin/fluent-cart/reviews/#new-post', AdminFooter::FEEDBACK_URL);
        $this->assertStringNotContainsString('Thank you for creating with WordPress', $text);
        $this->assertStringNotContainsString('★', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(5|five)[ -]?stars?\b|\brate\b|\brating\b/i', $text);
    }

    public function test_footer_text_can_be_disabled_by_filter_keeping_wordpress_text(): void
    {
        add_filter('fluent_cart/admin/footer/show_text', '__return_false');

        $text = (new AdminFooter())->filterFooterText('Thank you for creating with WordPress.');

        remove_filter('fluent_cart/admin/footer/show_text', '__return_false');

        $this->assertSame('Thank you for creating with WordPress.', $text);
    }

    public function test_version_footer_shows_fluentcart_version_instead_of_wordpress_version(): void
    {
        $footer = (new AdminFooter())->filterUpdateFooter('Version 9.9.9');

        $this->assertStringStartsWith(FLUENTCART_VERSION, $footer);
        $this->assertStringNotContainsString('9.9.9', $footer);
    }

    public function test_version_label_shows_core_only_when_pro_is_absent(): void
    {
        $this->assertSame(FLUENTCART_VERSION, AdminFooter::getVersionLabel(null));
    }

    public function test_version_label_includes_pro_version_when_it_differs_from_core(): void
    {
        $this->assertSame(
            FLUENTCART_VERSION . ' & Pro 0.0.1',
            AdminFooter::getVersionLabel('0.0.1')
        );
    }

    public function test_version_label_shows_one_version_when_pro_matches_core(): void
    {
        $this->assertSame(FLUENTCART_VERSION, AdminFooter::getVersionLabel(FLUENTCART_VERSION));
    }

    public function test_pro_version_comes_only_from_the_loaded_pro_plugin(): void
    {
        $expected = defined('FLUENTCART_PRO_PLUGIN_VERSION') ? (string)FLUENTCART_PRO_PLUGIN_VERSION : null;

        // An installed-but-inactive Pro must not be reported.
        wp_cache_set('plugins', ['' => [
            'fluent-cart-pro/fluent-cart-pro.php' => ['Name' => 'FluentCart Pro', 'Version' => '4.5.6'],
        ]], 'plugins');

        $this->assertSame($expected, AdminFooter::getProVersion());

        wp_cache_delete('plugins', 'plugins');
    }
}
