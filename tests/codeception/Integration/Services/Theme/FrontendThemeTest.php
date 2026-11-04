<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * What actually reaches the storefront.
 *
 * These globals cascade — every scoped FluentCart variable is written
 * `var(--fct-<global>, <fallback>)` — so anything written here changes the
 * whole store, and anything hostile written here would land inside a style
 * element on every page.
 */
class FrontendThemeTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    protected function setUp(): void
    {
        parent::setUp();
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
        parent::tearDown();
    }

    /**
     * Spec: Cases/storefront-colours.md [25]
     *   "With FluentCart's own colours selected, nothing is written to the page."
     */
    public function test_the_default_source_writes_nothing(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_DEFAULT,
            'appearance_colors' => ['btn_bg_color' => '#b91c1c'],
        ]);
        StoreSettings::clearCache();

        // The stored colours are deliberately ignored rather than cleared, so
        // switching back to Customise restores what the owner had picked.
        $this->assertSame('', FrontendTheme::buildCss(), 'the default source leaves FluentCart\'s own cascade alone');
    }

    /**
     * Spec: Cases/storefront-colours.md [26]
     *   "With colours customised, only the ones the owner actually set are
     *    written."
     */
    public function test_customising_writes_only_what_was_set(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color'       => '#b91c1c',
                'primary_text_color' => '#111827',
            ],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-btn-bg-color:#b91c1c;', $css, 'a set colour is written');
        $this->assertStringContainsString('--fct-primary-text-color:#111827;', $css, 'and so is the other one');
        $this->assertStringNotContainsString('--fct-border-color', $css, 'an unset colour keeps its FluentCart fallback');
        $this->assertStringStartsWith(':root{', $css, 'the globals are declared at the document root');
    }

    /**
     * Spec: Cases/storefront-colours.md [27]
     *   "A customised colour also writes the second spelling the modal checkout
     *    reads."
     */
    public function test_a_colour_with_an_alias_writes_both_spellings(): void
    {
        // checkout-iframe.scss reads --fct-input-disabled-bg while every other
        // surface reads --fct-input-disabled-bg-color. One setting has to drive
        // both or the modal checkout drifts away from the rest of the store.
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => ['input_disabled_bg_color' => '#eeeeee'],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-input-disabled-bg-color:#eeeeee;', $css, 'the documented property');
        $this->assertStringContainsString('--fct-input-disabled-bg:#eeeeee;', $css, 'and the modal checkout spelling');
    }

    /**
     * Spec: Cases/storefront-colours.md [28]
     *   "With the theme selected, every global the palette can supply is
     *    written."
     */
    public function test_inheriting_writes_every_global_the_palette_supplies(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'accent-1', 'name' => 'Accent 1', 'color' => '#ffe2c7'],
            ];
        });
        ThemePalette::clearCache();

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        foreach (ColorPalette::globals() as $key => $definition) {
            $this->assertStringContainsString(
                $definition['var'] . ':',
                $css,
                "inheriting rebuilds the whole palette, so '{$key}' must be written"
            );
        }

        $this->assertStringContainsString('--fct-primary-text-color:#111111;', $css, 'body text comes straight from the palette');
        $this->assertStringContainsString('--fct-btn-text-color:#ffffff;', $css, 'button text is contrast-picked against the button');
    }

    /**
     * Spec: Cases/storefront-colours.md [37]
     *   "A theme with nothing to inherit from writes nothing at all, rather
     *    than pinning FluentCart's own colours."
     */
    public function test_a_theme_with_nothing_to_inherit_writes_nothing(): void
    {
        add_filter('fluent_cart/theme/palette', '__return_empty_array');
        ThemePalette::clearCache();

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        StoreSettings::clearCache();

        // Pinning FluentCart's defaults is not the same as leaving them alone:
        // the per-file fallbacks differ from one surface to the next, so one
        // pinned value quietly changes the store while claiming to follow a
        // theme that said nothing.
        $this->assertSame(
            '',
            FrontendTheme::buildCss(),
            'with nothing to inherit, inheriting must be indistinguishable from the default source'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [21]
     *   "A palette colour written as a CSS variable reference is passed through
     *    for the browser to resolve."
     */
    public function test_a_referenced_palette_colour_reaches_the_page(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Background', 'color' => 'var(--base)'],
                ['slug' => 'contrast', 'name' => 'Text', 'color' => 'var(--contrast)'],
                ['slug' => 'accent', 'name' => 'Brand', 'color' => 'var(--accent)'],
            ];
        });
        ThemePalette::clearCache();

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString(
            '--fct-primary-text-color:var(--contrast);',
            $css,
            'the browser resolves what we cannot, so the reference is written as-is'
        );
        $this->assertStringNotContainsString('--fct-border-color', $css, 'a tone it cannot derive is not written at all');
        // Spec 65: the button is the exception — an unmeasurable background
        // cannot be paired with readable text, so neither half is owned.
        $this->assertStringNotContainsString('--fct-btn-bg-color', $css, 'an unmeasurable button colour is not owned');
        $this->assertStringNotContainsString('var(--accent,', $css, 'no fallback expression is ever emitted');
    }

    /**
     * Spec: Cases/storefront-colours.md [29]
     *   "An unrecognised stored colour key never reaches the page."
     */
    public function test_an_unknown_stored_key_never_reaches_the_page(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color'         => '#b91c1c',
                'not_a_registry_key'   => '#000000',
                '--fct-injected-thing' => '#000000',
            ],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-btn-bg-color:#b91c1c;', $css, 'the real colour is still written');
        $this->assertStringNotContainsString('not_a_registry_key', $css, 'an unknown key is not a property we own');
        $this->assertStringNotContainsString('--fct-injected-thing', $css, 'and a key shaped like a property is still unknown');
    }

    /**
     * Spec: Cases/storefront-colours.md [30]
     *   "A stored value that is not a colour never reaches the page."
     */
    public function test_a_stored_value_that_is_not_a_colour_never_reaches_the_page(): void
    {
        // The option is sanitised on save, but a value written by an older
        // build, by WP-CLI, or by another plugin must not be trusted on read —
        // this string lands inside a <style> element on every storefront page.
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color'       => '#b91c1c',
                'border_color'       => 'red; } body { display:none',
                'divider_color'      => 'javascript:alert(1)',
                'input_bg_color'     => 'var(--evil)',
                'primary_text_color' => '</style><script>alert(1)</script>',
            ],
        ]);
        StoreSettings::clearCache();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-btn-bg-color:#b91c1c;', $css, 'the valid colour survives');
        $this->assertStringNotContainsString('display:none', $css, 'a declaration breakout is refused');
        $this->assertStringNotContainsString('javascript:', $css, 'a javascript url is refused');
        $this->assertStringNotContainsString('var(', $css, 'an unresolvable reference is refused');
        $this->assertStringNotContainsString('<script', $css, 'a style-element breakout is refused');
        $this->assertStringNotContainsString('</style', $css, 'including the closing tag');
    }
}
