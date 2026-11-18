<?php

namespace Tests\Integration\Checkout;

use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The checkout coupon Apply button uses the store's secondary button colors.
 */
class CouponButtonColorsTest extends WPTestCase
{
    private function couponButtonRule(): string
    {
        $scss = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/public/checkout/style/checkout.scss');
        $start = strpos($scss, '.fct_coupon_field {');
        $this->assertNotFalse($start, 'checkout.scss has the coupon field rule');

        $field = substr($scss, $start, strpos($scss, '.fct_order_note', $start) - $start);
        $buttonStart = strpos($field, 'button {');
        $this->assertNotFalse($buttonStart, 'the coupon field styles its button');

        return substr($field, $buttonStart);
    }

    private function hoverRule(string $button): string
    {
        $hoverStart = strpos($button, '&:hover {');
        $this->assertNotFalse($hoverStart, 'the coupon button has a hover rule');

        return substr($button, $hoverStart, strpos($button, '}', $hoverStart) - $hoverStart);
    }

    public function test_the_button_uses_the_secondary_button_background_and_text(): void
    {
        $button = $this->couponButtonRule();
        $normal = substr($button, 0, strpos($button, '&:hover {'));

        $this->assertMatchesRegularExpression('/background-color:\s*var\(--fct-secondary-btn-bg-color\b/', $normal);
        $this->assertMatchesRegularExpression('/\bcolor:\s*var\(--fct-secondary-btn-text-color\b/', $normal);
        $this->assertMatchesRegularExpression('/border:[^;]*var\(--fct-secondary-btn-border-color\b/', $normal);
    }

    public function test_hover_uses_the_secondary_button_hover_background_and_keeps_the_text_color(): void
    {
        $hover = $this->hoverRule($this->couponButtonRule());

        $this->assertMatchesRegularExpression('/background-color:\s*var\(--fct-secondary-btn-hover-bg-color\b/', $hover);
        $this->assertStringNotContainsString('--fct-border-color', $hover);
        // Without it the theme's button hover color wins (white on light gray in Kadence)
        $this->assertMatchesRegularExpression('/\bcolor:\s*var\(--fct-secondary-btn-text-color\b/', $hover);
    }
}
