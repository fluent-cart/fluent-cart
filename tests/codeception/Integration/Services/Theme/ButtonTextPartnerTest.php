<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * A customised button background never reaches the page without a readable
 * text partner.
 *
 * Customize lets the owner set a button background and leave its text unset.
 * Every stylesheet then fell back to its own literal text — white for most
 * primary buttons, but the product carousel's hovered arrow fell back to
 * #111827, so a dark button background painted a dark icon on a dark circle.
 * Theme inheritance already measures a missing partner; customize now does the
 * same, for the button pair and for the hover pair, so every consumer reads a
 * contrast-safe text token whenever a background is written.
 */
class ButtonTextPartnerTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        $this->reset();
        parent::tearDown();
    }

    protected function reset(): void
    {
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
    }

    protected function customize(array $colors): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => $colors,
        ]);

        $this->reset();
    }

    /**
     * What the product carousel's hovered arrow resolves to, following its
     * stylesheet's fallback chains against the colours written to the page:
     *   background: var(--fct-btn-hover-bg-color, var(--fct-btn-bg-color, #ffffff))
     *   icon:       var(--fct-btn-hover-text-color, var(--fct-btn-text-color, #111827))
     */
    protected function carouselArrowHover(): array
    {
        $colors = FrontendTheme::getEffectiveColors();

        return [
            'background' => $colors['btn_hover_bg_color'] ?? $colors['btn_bg_color'] ?? '#ffffff',
            'icon'       => $colors['btn_hover_text_color'] ?? $colors['btn_text_color'] ?? '#111827',
        ];
    }

    /**
     * Spec: Cases/storefront-colours.md [80]
     *   "A customised button background without a text colour is written with
     *    a measured, readable partner — for the button and for its hover."
     */
    public function test_a_dark_custom_button_without_text_keeps_the_carousel_arrow_readable(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b']);

        $css = FrontendTheme::buildCss();
        $this->assertStringContainsString('--fct-btn-text-color:#ffffff;', $css, 'a readable partner is written beside the background');

        // The hovered arrow fills with the button's hover, which customize now
        // derives from the button as inheriting does (spec 121) — before, it
        // was the resting colour and the hover showed no change.
        $arrow = $this->carouselArrowHover();
        $this->assertSame(ColorMath::lighten('#1e1b4b', 12), $arrow['background'], 'the hovered arrow fills with the button\'s hover');
        $this->assertGreaterThanOrEqual(
            4.5,
            ColorMath::contrast($arrow['background'], $arrow['icon']),
            'and its icon stays readable on it'
        );
    }

    public function test_a_light_custom_button_without_text_gets_dark_text(): void
    {
        // The stylesheets' own fallback here is white — unreadable on yellow.
        $this->customize(['btn_bg_color' => '#fde047']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame('#1f2937', $colors['btn_text_color']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#fde047', $colors['btn_text_color']));
    }

    public function test_the_owners_own_text_is_worn_as_given(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b', 'btn_text_color' => '#ffd700']);

        $this->assertSame('#ffd700', FrontendTheme::getEffectiveColors()['btn_text_color'], 'a chosen partner is never re-measured');
    }

    public function test_a_hover_background_without_hover_text_is_measured_against_the_hover(): void
    {
        // The resting text (white) would vanish on this pale hover.
        $this->customize([
            'btn_bg_color'       => '#1e1b4b',
            'btn_text_color'     => '#ffffff',
            'btn_hover_bg_color' => '#e0e7ff',
        ]);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame('#1f2937', $colors['btn_hover_text_color']);

        $arrow = $this->carouselArrowHover();
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast($arrow['background'], $arrow['icon']));
    }

    public function test_nothing_is_invented_without_a_background(): void
    {
        $this->customize(['primary_text_color' => '#111827']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertArrayNotHasKey('btn_text_color', $colors, 'no background, no partner');
        $this->assertArrayNotHasKey('btn_hover_text_color', $colors);
    }

    public function test_the_hand_set_colours_stay_what_the_owner_set(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b']);

        $this->assertSame(['btn_bg_color' => '#1e1b4b'], FrontendTheme::getCustomColors(), 'the partner is added to the page, not to the owner\'s picks');
    }
}
