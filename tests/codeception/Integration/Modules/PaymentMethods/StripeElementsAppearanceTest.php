<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The embedded Payment Element lives in a Stripe iframe, so the only styling
 * channel is the `appearance` / `fonts` options handed to `stripe.elements()`.
 * Both are filter-driven on the server and passed through verbatim by
 * stripe-checkout.js; these pin the two filters' defaults and pass-through.
 */
class StripeElementsAppearanceTest extends WPTestCase
{
    /** @var callable[] */
    private array $filters = [];

    protected function tearDown(): void
    {
        foreach ($this->filters as [$tag, $fn]) {
            remove_filter($tag, $fn);
        }
        $this->filters = [];

        parent::tearDown();
    }

    private function on(string $tag, callable $fn): void
    {
        add_filter($tag, $fn);
        $this->filters[] = [$tag, $fn];
    }

    public function test_appearance_defaults_to_stripe_theme(): void
    {
        $this->assertSame(['theme' => 'stripe'], (new Stripe())->getElementsAppearance());
    }

    /**
     * The filter is the whole styling surface — theme, labels, variables and
     * rules must all survive untouched, not just a theme swap.
     */
    public function test_appearance_filter_passes_full_appearance_api_object(): void
    {
        $custom = [
            'theme'     => 'night',
            'labels'    => 'floating',
            'variables' => ['colorPrimary' => '#f5c542', 'fontFamily' => 'Inter, sans-serif'],
            'rules'     => ['.Tab--selected' => ['borderColor' => '#f5c542']],
        ];

        $this->on('fluent_cart/stripe_appearance', function () use ($custom) {
            return $custom;
        });

        $this->assertSame($custom, (new Stripe())->getElementsAppearance());
    }

    public function test_fonts_default_to_empty_so_stripe_uses_its_own_stack(): void
    {
        $this->assertSame([], (new Stripe())->getElementsFonts());
    }

    public function test_fonts_filter_passes_font_sources_through(): void
    {
        $fonts = [
            ['cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter&display=swap'],
            ['family' => 'Brand Sans', 'src' => 'url(https://example.com/brand.woff2)', 'weight' => '400'],
        ];

        $this->on('fluent_cart/stripe_elements_fonts', function () use ($fonts) {
            return $fonts;
        });

        $this->assertSame($fonts, (new Stripe())->getElementsFonts());
    }

    /**
     * Stripe.js rejects a `fonts` list holding anything but source objects, so
     * a stray scalar from a listener must not reach the browser.
     */
    public function test_fonts_filter_drops_non_object_entries(): void
    {
        $this->on('fluent_cart/stripe_elements_fonts', function () {
            return ['https://fonts.googleapis.com/css2?family=Inter', ['cssSrc' => 'https://example.com/a.css'], null];
        });

        $this->assertSame([['cssSrc' => 'https://example.com/a.css']], (new Stripe())->getElementsFonts());
    }
}
