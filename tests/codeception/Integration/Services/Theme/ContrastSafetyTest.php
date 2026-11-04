<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Contrast the storefront palette guarantees, found missing by browser QA.
 *
 * - Muted text was a fixed 68% mix of the body text into the surface. On a
 *   theme whose body text is already mid-grey that mix fell below AA: Divi's
 *   #666666 on white gave #979797 (2.92:1).
 * - A button text the theme states is the owner's choice and is worn as
 *   given at any contrast (Astra QA: red on gold, 1.93:1, was replaced by a
 *   measured dark text). Only a text nobody chose is measured.
 * - The measured partner only chose between #ffffff and #1f2937, so a
 *   mid-tone button (#809400) peaked at 4.30:1.
 * - Customize with only a button background printed no hover, so the button
 *   hovered in its resting colour.
 */
class ContrastSafetyTest extends WPTestCase
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

    protected function statedSettings(array $roles): void
    {
        add_filter('fluent_cart/theme/settings_roles', function () use ($roles) {
            return $roles;
        });

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

    protected function customize(array $colors): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => $colors,
        ]);

        $this->reset();
    }

    /**
     * Spec: Cases/storefront-colours.md [118]
     *   "Muted text derived from the theme always reads on the surface (WCAG
     *    4.5:1): when the usual mix falls short it moves toward the body text,
     *    only as far as it has to; a mix that already reads is unchanged."
     */
    public function test_muted_text_from_a_mid_grey_body_text_still_reads(): void
    {
        // Divi's defaults: #666666 body text on a white content area.
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#666666'],
        ]);

        $muted = ThemePalette::resolve()['text_muted'];

        $this->assertLessThan(4.5, ColorMath::contrast('#ffffff', ColorMath::mix('#666666', '#ffffff', 68)), 'the plain mix is the bug');
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#ffffff', $muted), 'the muted text reads');
        $this->assertLessThan(
            ColorMath::contrast('#ffffff', '#666666'),
            ColorMath::contrast('#ffffff', $muted),
            'and is still muted — lighter than the body text'
        );
        $this->assertSame($muted, FrontendTheme::getEffectiveColors()['secondary_text_color'], 'it is what the page is given');
    }

    public function test_muted_text_that_already_reads_is_unchanged(): void
    {
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $this->assertSame(ColorMath::mix('#111111', '#ffffff', 68), ThemePalette::resolve()['text_muted']);
    }

    public function test_muted_text_on_a_dark_surface_still_reads(): void
    {
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#1a1a1a'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#a0a0a0'],
        ]);

        $muted = ThemePalette::resolve()['text_muted'];

        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#1a1a1a', $muted));
    }

    public function test_body_text_that_does_not_read_itself_is_the_muted_text(): void
    {
        // Nothing between it and the surface can read better than the text.
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#999999'],
        ]);

        $this->assertSame('#999999', ThemePalette::resolve()['text_muted']);
    }

    /**
     * Spec: Cases/storefront-colours.md [119]
     *   "A button text the theme states — in the site editor or its own
     *    settings — is worn exactly as given, whatever its contrast with the
     *    background. The same holds for the stated hover text."
     */
    public function test_a_site_editor_button_text_is_worn_as_given_at_any_contrast(): void
    {
        // Twenty Twenty-Five's site-editor pair: 2.26:1. The owner's choice.
        $this->siteEditorButton([
            'color' => ['background' => '#503aa8', 'text' => '#111111'],
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#503aa8', $roles['button_bg']);
        $this->assertSame('#111111', $roles['button_text'], 'a chosen text is never replaced');
    }

    public function test_a_deliberate_brand_pair_above_three_to_one_is_kept(): void
    {
        // White on #ff5500 is 3.21:1 — below AA for body text, but an
        // owner's brand pair, and readable at button size.
        $this->siteEditorButton([
            'color' => ['background' => '#ff5500', 'text' => '#ffffff'],
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $this->assertSame('#ffffff', ThemePalette::resolve()['button_text']);
    }

    public function test_a_stated_hover_text_is_worn_as_given_at_any_contrast(): void
    {
        $this->siteEditorButton([
            'color'  => ['background' => '#111111', 'text' => '#ffffff'],
            ':hover' => ['color' => ['background' => '#7c3aed', 'text' => '#6d28d9']],
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#7c3aed', $roles['button_hover_bg']);
        $this->assertSame('#6d28d9', $roles['button_hover_text'], 'a chosen hover text is never replaced');
    }

    public function test_a_text_from_the_theme_settings_is_worn_as_given_at_any_contrast(): void
    {
        // What a settings reader (Blocksy, Kadence, Divi, Astra) states.
        // Astra QA: button text red on a gold background (1.93:1).
        $this->statedSettings([
            'surface'           => '#ffffff',
            'text'              => '#111111',
            'button_bg'         => '#dbaf00',
            'button_text'       => '#ff0000',
            'button_hover_bg'   => '#1e1b4b',
            'button_hover_text' => '#111111',
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#dbaf00', $roles['button_bg']);
        $this->assertSame('#ff0000', $roles['button_text'], 'red on gold is the owner\'s choice');
        $this->assertSame('#1e1b4b', $roles['button_hover_bg']);
        $this->assertSame('#111111', $roles['button_hover_text'], 'near-black on navy is the owner\'s choice');

        $effective = FrontendTheme::getEffectiveColors();

        $this->assertSame('#ff0000', $effective['btn_text_color'], 'and it is what the storefront prints');
        $this->assertSame('#111111', $effective['btn_hover_text_color']);
    }

    public function test_a_stated_button_that_cannot_be_measured_is_still_worn(): void
    {
        // A settings reader can state a live reference whose value it could
        // not look up. It is still the owner's button: written as given, with
        // the text and hover the owner set — and nothing invented beside it.
        $this->statedSettings([
            'button_bg'         => 'var(--theme-brand)',
            'button_text'       => '#ff0000',
            'button_hover_bg'   => 'var(--theme-brand-dark)',
            'button_hover_text' => '#ffffff',
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--theme-brand)', $roles['button_bg']);
        $this->assertSame('#ff0000', $roles['button_text']);
        $this->assertSame('var(--theme-brand-dark)', $roles['button_hover_bg']);
        $this->assertSame('#ffffff', $roles['button_hover_text']);
    }

    public function test_a_stated_button_that_cannot_be_measured_gets_nothing_invented(): void
    {
        $this->statedSettings(['button_bg' => 'var(--theme-brand)']);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--theme-brand)', $roles['button_bg'], 'the owner\'s background is worn');
        $this->assertSame('', $roles['button_text'], 'no text can be measured against it, so none is invented');
        $this->assertSame('', $roles['button_hover_bg'], 'and no hover is derived from it');
        $this->assertSame('', $roles['button_hover_text']);
    }

    public function test_a_readable_text_from_the_theme_settings_is_worn_as_given(): void
    {
        $this->statedSettings([
            'surface'           => '#ffffff',
            'text'              => '#111111',
            'button_bg'         => '#1e1b4b',
            'button_text'       => '#fde047',
            'button_hover_bg'   => '#312e81',
            'button_hover_text' => '#fef08a',
        ]);
        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ]);

        $roles = ThemePalette::resolve();

        $this->assertSame('#fde047', $roles['button_text']);
        $this->assertSame('#fef08a', $roles['button_hover_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [120]
     *   "A measured text partner reaches 4.5:1 on a mid-tone background where
     *    neither of the usual pair does, by taking pure black or white; every
     *    partner the usual pair already made readable is unchanged."
     */
    public function test_a_mid_tone_background_gets_a_partner_that_reads(): void
    {
        $this->assertLessThan(4.5, ColorMath::contrast('#809400', ColorMath::readableOn('#809400')), 'the usual pair falls short');

        $text = ColorMath::readableText('#809400');

        $this->assertSame('#000000', $text);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#809400', $text));
    }

    public function test_partners_that_already_read_are_unchanged(): void
    {
        foreach (['#1e1b4b', '#fde047', '#253241', '#ffffff', '#000000', '#7c3aed', '#ffee58', '#1e3a8a'] as $background) {
            $this->assertSame(ColorMath::readableOn($background), ColorMath::readableText($background), $background);
        }
    }

    public function test_a_mid_tone_button_reaches_the_page_with_a_readable_partner(): void
    {
        $this->customize(['btn_bg_color' => '#809400']);
        $colors = FrontendTheme::getEffectiveColors();
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#809400', $colors['btn_text_color']), 'customize');

        $this->inheritFrom([
            ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#809400'],
        ]);
        $roles = ThemePalette::resolve();
        $this->assertSame('#809400', $roles['button_bg']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#809400', $roles['button_text']), 'inherit');
    }

    /**
     * Spec: Cases/storefront-colours.md [121]
     *   "Customize with a button background and no hover background derives
     *    the hover the same way inheriting does — the button moved 12% away
     *    from itself — with a readable text partner; a hover the owner set is
     *    worn, and Default still prints nothing."
     */
    public function test_a_custom_button_alone_gets_a_derived_hover(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame(ColorMath::lighten('#1e1b4b', 12), $colors['btn_hover_bg_color'], 'a dark button hovers lighter');
        $this->assertNotSame($colors['btn_bg_color'], $colors['btn_hover_bg_color'], 'the hover is visible');
        $this->assertSame('#ffffff', $colors['btn_hover_text_color'], 'the resting partner carries over');
        $this->assertStringContainsString('--fct-btn-hover-bg-color:' . ColorMath::lighten('#1e1b4b', 12) . ';', FrontendTheme::buildCss());
    }

    public function test_a_light_custom_button_alone_hovers_darker_with_readable_text(): void
    {
        $this->customize(['btn_bg_color' => '#fde047']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame(ColorMath::darken('#fde047', 12), $colors['btn_hover_bg_color']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast($colors['btn_hover_bg_color'], $colors['btn_hover_text_color']));
    }

    public function test_the_owners_hover_text_is_kept_on_the_derived_hover(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b', 'btn_hover_text_color' => '#fde047']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame(ColorMath::lighten('#1e1b4b', 12), $colors['btn_hover_bg_color']);
        $this->assertSame('#fde047', $colors['btn_hover_text_color']);
    }

    public function test_the_owners_hover_is_not_rederived(): void
    {
        $this->customize(['btn_bg_color' => '#1e1b4b', 'btn_hover_bg_color' => '#e0e7ff']);

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame('#e0e7ff', $colors['btn_hover_bg_color']);
        $this->assertSame('#1f2937', $colors['btn_hover_text_color']);
        $this->assertSame(['btn_bg_color' => '#1e1b4b', 'btn_hover_bg_color' => '#e0e7ff'], FrontendTheme::getCustomColors(), 'the owner\'s picks are untouched');
    }

    public function test_default_still_prints_nothing(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_DEFAULT,
            'appearance_colors' => ['btn_bg_color' => '#1e1b4b'],
        ]);
        $this->reset();

        $this->assertSame([], FrontendTheme::getEffectiveColors());
    }
}
