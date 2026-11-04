<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The primary button's hover pair.
 *
 * Every primary button (Place Order, Buy Now, the cart drawer's checkout, the
 * product card) used to pin its hover to its resting colour — the palette had
 * no hover to offer. Two globals now carry one: a background and its text.
 * The owner picks them under customize; theme inheritance takes what the site
 * editor states for `:hover`, or moves the button colour away from itself;
 * and a hover nobody set stays on the stylesheet's fallback, which is the
 * button's own resting colour — so a default store's buttons do not move.
 */
class ButtonHoverColoursTest extends WPTestCase
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

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $this->reset();
        parent::tearDown();
    }

    protected function reset(): void
    {
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
    }

    protected function inheritFrom(array $palette): void
    {
        add_filter('fluent_cart/theme/palette', function () use ($palette) {
            return $palette;
        });

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    protected function siteEditorButton(array $button): void
    {
        add_filter('wp_theme_json_data_user', function ($themeJson) use ($button) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => ['elements' => ['button' => $button]],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $this->reset();
    }

    /**
     * Spec: Cases/storefront-colours.md [77]
     *   "The primary button carries a hover pair the owner can set."
     */
    public function test_the_registry_offers_a_hover_pair_for_the_primary_button(): void
    {
        $globals = ColorPalette::globals();

        $this->assertSame('--fct-btn-hover-bg-color', $globals['btn_hover_bg_color']['var']);
        $this->assertSame('button', $globals['btn_hover_bg_color']['group'], 'a picker among the buttons');
        $this->assertSame('button_hover_bg', $globals['btn_hover_bg_color']['role']);

        $this->assertSame('--fct-btn-hover-text-color', $globals['btn_hover_text_color']['var']);
        $this->assertSame('button', $globals['btn_hover_text_color']['group']);
        $this->assertSame('button_hover_text', $globals['btn_hover_text_color']['role']);

        $this->assertArrayHasKey('button_hover_bg', ColorPalette::roles(), 'theme inheritance resolves the hover too');
        $this->assertArrayHasKey('button_hover_text', ColorPalette::roles());

        $keys = array_keys(ColorPalette::globalsFor('button'));
        $this->assertSame(
            array_search('btn_text_color', $keys, true) + 1,
            array_search('btn_hover_bg_color', $keys, true),
            'the hover pair sits right after the button pair it belongs to'
        );
    }

    public function test_the_owners_hover_pair_is_written_to_the_page(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color'         => '#809400',
                'btn_hover_bg_color'   => '#6a7a00',
                'btn_hover_text_color' => '#fefefe',
            ],
        ]);
        $this->reset();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-btn-hover-bg-color:#6a7a00;', $css);
        $this->assertStringContainsString('--fct-btn-hover-text-color:#fefefe;', $css);
    }

    public function test_an_unset_hover_is_never_invented_under_customize(): void
    {
        // With no button colour set at all, nothing is invented: the
        // stylesheets fall back to the button's resting colour — the hover
        // the store always had. (A button background set on its own now
        // derives its hover, spec 121: the resting-colour hover showed no
        // change at all.)
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => ['primary_text_color' => '#111827'],
        ]);
        $this->reset();

        $this->assertStringNotContainsString('--fct-btn-hover-', FrontendTheme::buildCss());
    }

    /**
     * Spec: Cases/storefront-colours.md [78]
     *   "Inheriting from a theme, the hover moves the button colour away from
     *    itself — darker for a light button, lighter for a dark one — and its
     *    text stays readable."
     */
    public function test_a_light_theme_button_hovers_darker(): void
    {
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#ffee58'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#ffee58', $roles['button_bg']);
        $this->assertSame(ColorMath::darken('#ffee58', 12), $roles['button_hover_bg'], 'a light button darkens');
        $this->assertLessThan(
            ColorMath::luminance('#ffee58'),
            ColorMath::luminance($roles['button_hover_bg']),
            'and really is darker'
        );
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast($roles['button_hover_bg'], $roles['button_hover_text']), 'its text reads');
    }

    public function test_a_dark_theme_button_hovers_lighter(): void
    {
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#1e3a8a'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame(ColorMath::lighten('#1e3a8a', 12), $roles['button_hover_bg'], 'a dark button lightens');
        $this->assertSame('#ffffff', $roles['button_hover_text'], 'the button\'s own readable text carries over');
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast($roles['button_hover_bg'], $roles['button_hover_text']));
    }

    public function test_the_hover_reaches_the_effective_colours(): void
    {
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#1e3a8a'],
        ]);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame(ColorMath::lighten('#1e3a8a', 12), $colors['btn_hover_bg_color']);
        $this->assertSame('#ffffff', $colors['btn_hover_text_color']);
    }

    /**
     * Spec: Cases/storefront-colours.md [79]
     *   "The site editor's button hover outranks the derived one."
     */
    public function test_the_site_editors_hover_outranks_the_derived_one(): void
    {
        $this->siteEditorButton([
            'color'  => ['background' => '#111111', 'text' => '#ffffff'],
            ':hover' => ['color' => ['background' => 'var:preset|color|primary', 'text' => '#fff000']],
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#7c3aed', $roles['button_hover_bg'], 'the stated hover resolves through the palette');
        $this->assertSame('#fff000', $roles['button_hover_text'], 'and its text is worn as given');
    }

    public function test_a_stated_hover_background_alone_gets_a_measured_partner(): void
    {
        // The resting text (#111111) would vanish on the stated dark hover,
        // so the partner is measured against the hover instead.
        $this->siteEditorButton([
            'color'  => ['background' => '#fde047', 'text' => '#111111'],
            ':hover' => ['color' => ['background' => '#1e1b4b']],
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#1e1b4b', $roles['button_hover_bg']);
        $this->assertSame('#ffffff', $roles['button_hover_text'], 'unreadable resting text is replaced by a measured one');
    }

    public function test_an_unmeasurable_button_leaves_the_hover_unwritten(): void
    {
        // Neutral slugs: a bare reference cannot be moved away from itself.
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Bg', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Text', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Brand', 'color' => 'var(--theme-brand)'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('', $roles['button_hover_bg']);
        $this->assertSame('', $roles['button_hover_text']);
        $this->assertArrayNotHasKey('btn_hover_bg_color', FrontendTheme::getEffectiveColors(), 'nothing is written for it');
    }
}
