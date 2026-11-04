<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Where FluentCart's own colours live.
 *
 * In the stylesheets, and only there. Every FluentCart colour is written
 * `var(--fct-x, <fallback>)` by the file that uses it, so the value already has
 * a home; restating those hexes in the registry made PHP a second source of
 * truth for the same colour.
 *
 * That second copy was not merely redundant. Theme inheritance fell back to it
 * whenever the theme could not be read — which is most themes, since Astra and
 * Kadence name their palettes in vocabularies of their own and Blocksy, Sydney
 * and Hello Elementor publish no palette at all. The result was twenty
 * declarations of FluentCart's own colours printed under the active theme's
 * name: a store on Sydney reported itself as inheriting while wearing
 * FluentCart's palette, and no scoped variable could tell the difference
 * because the property was set either way.
 *
 * With the colours gone from PHP, a role that cannot be read produces nothing,
 * the property goes undeclared, and the stylesheet's own fallback is what
 * applies — which is the same colour, arrived at once instead of twice.
 */
class NoDefaultsInPhpTest extends WPTestCase
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
     * Spec: Cases/storefront-colours.md [52]
     *   "The registry names properties and roles, never colours."
     */
    public function test_the_registry_names_no_colour(): void
    {
        foreach (ColorPalette::globals() as $key => $definition) {
            $this->assertArrayNotHasKey(
                'default',
                $definition,
                "'{$key}' still carries a colour, so PHP is a second source of truth for it"
            );

            foreach ($definition as $field => $value) {
                if (!is_string($value)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/#[0-9a-f]{3,8}\b/i',
                    $value,
                    "'{$key}.{$field}' has a hex colour in it; colours belong in the stylesheets"
                );
            }
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [53]
     *   "A theme whose palette reaches no anchor writes nothing at all."
     */
    public function test_a_palette_that_reaches_no_anchor_writes_nothing(): void
    {
        // Sydney's real palette, as wp_get_global_settings() reports it. Ten
        // entries, every one a reference named in Sydney's own vocabulary, and
        // not one of them matching an anchor. This is the case that used to
        // print twenty of FluentCart's own colours and call them the theme's.
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (range(1, 9) as $index) {
                $palette[] = [
                    'slug'  => 'global_color_' . $index,
                    'name'  => 'Global ' . $index,
                    'color' => 'var(--sydney-global-color-' . $index . ')',
                ];
            }

            return $palette;
        });

        $this->inherit();

        $this->assertSame(
            ['surface' => '', 'text' => '', 'accent' => '', 'button_bg' => '', 'button_text' => ''],
            ThemePalette::anchors(),
            'nothing was read, so nothing is an anchor'
        );
        $this->assertSame(
            '',
            FrontendTheme::buildCss(),
            'with nothing read, the storefront keeps the fallbacks its own stylesheets carry'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [54]
     *   "A role that cannot be measured is left unwritten rather than invented."
     */
    public function test_a_role_that_cannot_be_measured_is_left_unwritten(): void
    {
        // A palette using the shared names, published as references. The four
        // anchors are writable — the browser resolves them on the page — but a
        // reference cannot be mixed or measured, so the tones derived from it
        // and the contrast-picked button text have no value to give.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => 'var(--theme-base)'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => 'var(--theme-contrast)'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => 'var(--theme-primary)'],
            ];
        });

        $this->inherit();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-primary-text-color:var(--theme-contrast);', $css, 'body text is still written as the reference');
        $this->assertStringContainsString('--fct-active-border-color:var(--theme-primary);', $css, 'and so is the accent, for the active states');
        $this->assertStringNotContainsString(
            '--fct-btn-bg-color',
            $css,
            'the button background now obeys the same rule: unmeasurable means unwritten, and the safe pair styles the button'
        );

        // Previously these were written as FluentCart's own greys, which is how
        // a half-inherited store came to look deliberate.
        $this->assertStringNotContainsString('--fct-border-color', $css, 'a border mixed from a reference is not a border');
        $this->assertStringNotContainsString('--fct-divider-color', $css, 'nor is a divider');
        $this->assertStringNotContainsString('--fct-btn-text-color', $css, 'and contrast cannot be measured against a reference');
        $this->assertStringNotContainsString('#', $css, 'no colour of FluentCart\'s own appears anywhere');
    }

    /**
     * Spec: Cases/storefront-colours.md [55]
     *   "A theme whose palette does reach an anchor is still written."
     */
    public function test_a_readable_palette_is_still_written(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'accent-1', 'name' => 'Accent 1', 'color' => '#ffe2c7'],
            ];
        });

        $this->inherit();

        $css = FrontendTheme::buildCss();

        $this->assertStringContainsString('--fct-primary-text-color:#111111;', $css, 'body text comes straight from the palette');

        // Real colours can be mixed and measured, so the tones no theme
        // publishes are still derived — from the theme, not from us.
        $this->assertStringContainsString('--fct-border-color:#', $css, 'a border mixed from real colours is a real border');
        $this->assertStringContainsString('--fct-btn-text-color:#', $css, 'and button text can be contrast-picked');
    }

    /**
     * Spec: Cases/storefront-colours.md [56]
     *   "The preview ships no colour of its own either."
     */
    public function test_the_preview_ships_no_colour_of_its_own(): void
    {
        // appearance.scss already writes every preview surface as
        // `var(--fct-pv-x, <fallback>)`, so the preview has the same single
        // source of truth the storefront does. Sending a colour from PHP would
        // reintroduce the second copy on the other side of the wire.
        $slots = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview.slots',
            []
        );

        $this->assertNotEmpty($slots, 'the preview still names the surfaces it paints');

        foreach ($slots as $slot) {
            $this->assertArrayNotHasKey(
                'default',
                $slot,
                "preview slot '{$slot['var']}' still carries a colour from PHP"
            );
        }
    }
}
