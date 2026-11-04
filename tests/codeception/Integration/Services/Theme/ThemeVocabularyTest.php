<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Themes that number their palette instead of naming it.
 *
 * Astra publishes `ast-global-color-0..8` and Kadence `theme-palette1..15`,
 * every entry a `var()` reference the theme itself declares on the page. The
 * numbers carry no meaning an outsider could guess — which slot is the
 * background differs per vendor — so each supported vendor's mapping is written
 * down from the theme's own sources, not inferred:
 *
 *  - Astra: 0 is the brand colour, 3 the body text, 4 the background.
 *  - Kadence: theme.json names `theme-palette1` "Accent"; its defaults set the
 *    body font to `palette4` and the content background to `palette9`.
 *
 * The vendor slugs sit last in every candidate list so the shared block-theme
 * vocabulary always wins first, and a theme outside the list still falls to the
 * stand-aside path rather than being guessed at.
 */
class ThemeVocabularyTest extends WPTestCase
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

    protected function inherit(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    /**
     * Spec: Cases/storefront-colours.md [61]
     *   "Astra's numbered palette is read with Astra's meanings."
     */
    public function test_astra_is_read_with_astra_meanings(): void
    {
        // Astra's palette verbatim: nine numbered references.
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (range(0, 8) as $index) {
                $palette[] = [
                    'slug'  => 'ast-global-color-' . $index,
                    'name'  => 'Theme Color ' . ($index + 1),
                    'color' => 'var(--ast-global-color-' . $index . ')',
                ];
            }

            return $palette;
        });

        $this->inherit();

        $map = ThemePalette::anchorMap();

        $this->assertSame('ast-global-color-0', $map['accent'], '0 is Astra\'s brand colour');
        $this->assertSame('ast-global-color-3', $map['text'], '3 is its body text');
        $this->assertSame('ast-global-color-4', $map['surface'], '4 is its background');

        $css = FrontendTheme::buildCss();

        // The accent reference survives to the page for the active states the
        // browser resolves — but the BUTTON is not owned: a reference cannot
        // be measured, so no readable text could be paired with it, and a
        // customised light accent would have produced white-on-light on the
        // primary purchase controls. The stylesheets' navy/white pair styles
        // the button instead.
        $this->assertStringContainsString(
            '--fct-active-border-color:var(--ast-global-color-0);',
            $css,
            'Astra\'s brand colour still reaches the storefront through the accent'
        );
        $this->assertStringNotContainsString(
            '--fct-btn-bg-color',
            $css,
            'an unmeasurable button colour is not owned — the safe pair styles the button'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [62]
     *   "Kadence's numbered palette is read with Kadence's meanings."
     */
    public function test_kadence_is_read_with_kadence_meanings(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (range(1, 15) as $index) {
                $palette[] = [
                    'slug'  => 'theme-palette' . $index,
                    'name'  => 'Palette ' . $index,
                    'color' => 'var(--global-palette' . $index . ')',
                ];
            }

            return $palette;
        });

        $this->inherit();

        $map = ThemePalette::anchorMap();

        $this->assertSame('theme-palette1', $map['accent'], 'palette1 is the colour Kadence calls Accent');
        $this->assertSame('theme-palette4', $map['text'], 'palette4 is what Kadence sets the body font to');
        $this->assertSame('theme-palette9', $map['surface'], 'palette9 is what Kadence sets the content background to');

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString(
            '--fct-active-border-color:var(--global-palette1);',
            $css,
            'Kadence\'s accent still reaches the storefront through the accent keys'
        );
        $this->assertStringNotContainsString(
            '--fct-btn-bg-color',
            $css,
            'an unmeasurable button colour is not owned — the safe pair styles the button'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [63]
     *   "Blocksy's numbered palette is read with Blocksy's meanings, and its
     *    hex fallbacks make it measurable."
     */
    public function test_blocksy_is_read_and_measured(): void
    {
        // Blocksy's palette verbatim: references WITH hex fallbacks — the
        // richest shape a theme can publish. The reference follows the
        // customiser live, and the fallback is a real colour, so unlike the
        // bare-reference vendors every derived tone and the button contrast
        // resolve too. The mapping is Blocksy's own: `fontColor` defaults to
        // palette 3, its card and list backgrounds inherit palette 8, and its
        // buttons paint with palette 1.
        add_filter('fluent_cart/theme/palette', function () {
            $defaults = [
                1 => '#2872fa', 2 => '#1559ed', 3 => '#3a4f66', 4 => '#192a3d',
                5 => '#e1e8ed', 6 => '#f2f5f7', 7 => '#fafbfc', 8 => '#ffffff',
            ];
            $palette = [];

            foreach ($defaults as $index => $hex) {
                $palette[] = [
                    'slug'  => 'palette-color-' . $index,
                    'name'  => 'Color ' . $index,
                    'color' => 'var(--theme-palette-color-' . $index . ', ' . $hex . ')',
                ];
            }

            return $palette;
        });

        $this->inherit();

        $map = ThemePalette::anchorMap();

        $this->assertSame('palette-color-1', $map['accent'], 'palette 1 is what Blocksy paints its buttons with');
        $this->assertSame('palette-color-3', $map['text'], 'palette 3 is Blocksy\'s Base Text default');
        $this->assertSame('palette-color-8', $map['surface'], 'palette 8 is what its surfaces inherit');

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString(
            '--fct-btn-bg-color:var(--theme-palette-color-1, #2872fa);',
            $css,
            'the reference reaches the storefront with its fallback intact'
        );
        // Contrast-picked for real: white on #2872fa is only 4.31:1, and
        // #1f2937 3.42:1, so the partner is black at 4.87:1 (spec 120). With
        // Blocksy's reader on, Blocksy's stated white is worn as given.
        $this->assertStringContainsString(
            '--fct-btn-text-color:#000000;',
            $css,
            'the fallback is measurable, so button text is contrast-picked for real'
        );
        $this->assertStringContainsString(
            '--fct-border-color:#',
            $css,
            'and the derived tones mix from the fallbacks instead of standing down'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [66]
     *   "A vendor's bare reference is resolved at its source: the vendor's own
     *    API supplies its current value as the fallback."
     */
    public function test_a_vendor_reference_is_resolved_at_its_source(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (range(0, 8) as $index) {
                $palette[] = [
                    'slug'  => 'ast-global-color-' . $index,
                    'name'  => 'Theme Color ' . ($index + 1),
                    'color' => 'var(--ast-global-color-' . $index . ')',
                ];
            }

            return $palette;
        });

        // Playing the part of Astra's API: the same values it prints the
        // custom properties from, keyed by property. The junk entries prove
        // the map is treated like any other filter — as a source of values,
        // not as trusted input.
        add_filter('fluent_cart/theme/vendor_values', function ($values) {
            return array_merge($values, [
                '--ast-global-color-0' => '#046bd2',
                '--ast-global-color-3' => '#334155',
                '--ast-global-color-4' => '#ffffff',
                '--ast-global-color-5' => 'red',
                'not-a-property'       => '#111111',
            ]);
        });

        $this->inherit();

        $css = FrontendTheme::buildCss();

        // The reference still reaches the page — the browser keeps following
        // the live property — but now it carries the vendor's own current
        // value as its fallback, which makes it measurable.
        $this->assertStringContainsString(
            '--fct-btn-bg-color:var(--ast-global-color-0, #046bd2);',
            $css,
            'the vendor\'s value rides along as the fallback'
        );
        $this->assertStringContainsString(
            '--fct-btn-text-color:#ffffff;',
            $css,
            'so button text is contrast-picked for real, and the button is owned again'
        );
        $this->assertStringContainsString(
            '--fct-border-color:#',
            $css,
            'and the tones derive from the vendor\'s values instead of standing down'
        );
        $this->assertStringNotContainsString('red', $css, 'a non-hex vendor value is refused');
        $this->assertStringNotContainsString('not-a-property', $css, 'and so is a key that is not a property');
    }

    /**
     * Spec: Cases/storefront-colours.md [67]
     *   "A vendor accent recoloured light gets dark button text — measured,
     *    never guessed."
     *
     * This is the exact defect the safety net alone could not fix without
     * losing the vendor's colour: the reviewer's white-on-light case.
     */
    public function test_a_light_vendor_accent_gets_dark_button_text(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'ast-global-color-0', 'name' => 'Brand', 'color' => 'var(--ast-global-color-0)'],
                ['slug' => 'ast-global-color-3', 'name' => 'Text', 'color' => 'var(--ast-global-color-3)'],
                ['slug' => 'ast-global-color-4', 'name' => 'Background', 'color' => 'var(--ast-global-color-4)'],
            ];
        });

        // A store owner has recoloured the brand to a pale tint.
        add_filter('fluent_cart/theme/vendor_values', function ($values) {
            return array_merge($values, [
                '--ast-global-color-0' => '#e8f0fe',
                '--ast-global-color-3' => '#334155',
                '--ast-global-color-4' => '#ffffff',
            ]);
        });

        $this->inherit();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString(
            '--fct-btn-bg-color:var(--ast-global-color-0, #e8f0fe);',
            $css,
            'the light accent is still the button background'
        );
        $this->assertStringContainsString(
            '--fct-btn-text-color:#1f2937;',
            $css,
            'and the text picked against it is dark, not defaulted white'
        );
    }

    /**
     * The shared names still win over a vendor's when a theme publishes both.
     */
    public function test_shared_names_win_over_vendor_names(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
                ['slug' => 'theme-palette1', 'name' => 'Palette 1', 'color' => 'var(--global-palette1)'],
            ];
        });
        $this->reset();

        $map = ThemePalette::anchorMap();

        // Preferring the shared names is not cosmetic: they are real colours,
        // so tones can be mixed and button text contrast-picked — a reference
        // can only be written through.
        $this->assertSame('primary', $map['accent'], 'a shared name is read before a vendor one');
        $this->assertSame('base', $map['surface'], 'and so is the shared surface');
    }
}
