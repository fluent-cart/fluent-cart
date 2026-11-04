<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The data the appearance settings hand the storefront preview.
 *
 * The preview paints a mock store from the same colours the storefront will
 * use, which only works if it is keyed the way the storefront is keyed. Four
 * separate globals claim the `accent` role — active text, the brand background
 * and both active borders — so a preview keyed by role would let one picker
 * drive surfaces the storefront keeps apart, and leave the rest with no visible
 * effect. Roles survive for theme inheritance alone, where collapsing keys that
 * share one is what FrontendTheme::getThemeColors() itself does.
 *
 * Everything is read through the public settings schema rather than the
 * builders behind it, so these also prove the payload is actually wired to the
 * component that renders it.
 */
class AppearancePreviewTest extends WPTestCase
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
     * Spec: Cases/storefront-colours.md [38]
     *   "The appearance settings carry a preview of the storefront."
     */
    public function test_the_appearance_settings_carry_a_preview(): void
    {
        $fields = (new StoreSettings())->fields();

        $component = Arr::get(
            $fields,
            'setting_tabs.schema.appearance.schema.appearance_component',
            []
        );

        $this->assertSame(
            'StoreSettings/AppearanceComponent',
            Arr::get($component, 'component'),
            'the appearance tab is rendered by the component that owns the preview'
        );
        $this->assertNotEmpty(
            Arr::get($component, 'preview.slots', []),
            'the component cannot paint anything without the colour slots'
        );
        $this->assertArrayHasKey(
            'theme_roles',
            Arr::get($component, 'preview', []),
            'theme colours are mixed server side and reach the browser only through this payload'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [39]
     *   "Every surface the preview paints names a colour the registry knows."
     */
    public function test_every_preview_surface_names_a_known_colour(): void
    {
        $globals = ColorPalette::globals();
        $roles = ColorPalette::roles();
        $slots = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview.slots',
            []
        );

        foreach ($slots as $slot) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9-]+$/',
                $slot['var'],
                "preview slot '{$slot['var']}' becomes a CSS custom property name and cannot carry anything else"
            );
            $this->assertArrayHasKey(
                $slot['role'],
                $roles,
                "preview slot '{$slot['var']}' claims a role the theme resolver never produces"
            );

            // A slot with no key is a surface no global controls; it follows
            // the role's default and cannot be edited.
            if (isset($slot['key'])) {
                $this->assertArrayHasKey(
                    $slot['key'],
                    $globals,
                    "preview slot '{$slot['var']}' is driven by a picker the registry does not have"
                );
            }
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [41]
     *   "Two colours that happen to share a semantic role stay independent in
     *    the preview."
     */
    public function test_colours_sharing_a_role_stay_independent(): void
    {
        $slots = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview.slots',
            []
        );

        $byKey = [];

        foreach ($slots as $slot) {
            if (isset($slot['key'])) {
                $byKey[$slot['key']] = $slot;
            }
        }

        $this->assertArrayHasKey('primary_active_text_color', $byKey, 'active text is a surface the preview paints');
        $this->assertArrayHasKey('active_border_color', $byKey, 'the active border is a surface the preview paints');

        // Both claim `accent`. Keyed by role, the first would have driven both
        // and the second would have had no visible effect at all.
        $this->assertSame(
            'accent',
            $byKey['primary_active_text_color']['role'],
            'active text claims the accent role'
        );
        $this->assertSame(
            'accent',
            $byKey['active_border_color']['role'],
            'the active border claims the same role'
        );
        $this->assertNotSame(
            $byKey['primary_active_text_color']['var'],
            $byKey['active_border_color']['var'],
            'sharing a role must not mean sharing one custom property in the preview'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [42]
     *   "With a theme that publishes colours, the preview shows the theme's
     *    colours."
     */
    public function test_a_theme_that_publishes_colours_is_shown(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#eeeeee'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
            ];
        });
        ThemePalette::clearCache();

        $preview = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview',
            []
        );

        $this->assertSame(
            '#7c3aed',
            Arr::get($preview, 'theme_roles.accent'),
            'the accent the theme published is what the preview paints with'
        );
        $this->assertSame(
            '#111111',
            Arr::get($preview, 'theme_roles.surface'),
            'the surface the theme published is what the preview paints with'
        );
        $this->assertSame(
            '',
            Arr::get($preview, 'notes.' . ColorPalette::SOURCE_THEME),
            'nothing needs explaining when every colour resolved'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [43]
     *   "With a theme that publishes CSS variables, the preview says it cannot
     *    show them."
     */
    public function test_a_theme_that_publishes_variables_is_explained(): void
    {
        // Astra, Kadence and GeneratePress publish their palettes this way. The
        // storefront resolves them perfectly well because the theme declares
        // those properties there — but wp-admin never loads that stylesheet, so
        // the preview genuinely cannot show them.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => 'var(--base)'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => 'var(--contrast)'],
                ['slug' => 'accent', 'name' => 'Accent', 'color' => 'var(--accent)'],
            ];
        });
        ThemePalette::clearCache();

        $preview = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview',
            []
        );

        $this->assertArrayNotHasKey(
            'accent',
            Arr::get($preview, 'theme_roles', []),
            'a reference is not a colour and must never be handed to the preview as one'
        );
        $this->assertNotSame(
            '',
            Arr::get($preview, 'notes.' . ColorPalette::SOURCE_THEME),
            'showing FluentCart colours without saying why would misrepresent what inheriting does'
        );

        // The storefront is unaffected: it writes the reference through and the
        // browser resolves it there.
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        StoreSettings::clearCache();

        $this->assertStringContainsString(
            'var(--contrast)',
            FrontendTheme::buildCss(),
            'the preview falling back must not change what the storefront is given'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [122]
     *   "A theme colour written as a live reference with a hex fallback is
     *    previewed by its fallback, and the preview says nothing is missing."
     */
    public function test_a_reference_with_a_fallback_is_previewed_by_it(): void
    {
        // Every theme reader (and Blocksy's palette) hands the storefront
        // `var(--x, #hex)`: live on the storefront, measurable here. Found in
        // the browser: an Astra store on the Viola palette previewed navy
        // buttons and a "cannot be resolved" note while its storefront was
        // purple, because only a plain hex reached the preview.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => 'var(--theme-base, #ffffff)'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => 'var(--theme-contrast, #454f5e)'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => 'var(--theme-primary, #6528f7)'],
            ];
        });
        ThemePalette::clearCache();

        $preview = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview',
            []
        );

        $this->assertSame('#6528f7', Arr::get($preview, 'theme_roles.accent'), 'the accent previews as its fallback');
        $this->assertSame('#6528f7', Arr::get($preview, 'theme_roles.button_bg'), 'so does the button');
        $this->assertSame('#ffffff', Arr::get($preview, 'theme_roles.surface'));
        $this->assertSame('#454f5e', Arr::get($preview, 'theme_roles.text'));
        $this->assertSame('', Arr::get($preview, 'notes.' . ColorPalette::SOURCE_THEME), 'nothing is missing, so nothing is explained');
    }

    /**
     * Spec: Cases/storefront-colours.md [44]
     *   "With nothing to inherit from, the preview keeps FluentCart's colours
     *    and says so."
     */
    public function test_nothing_to_inherit_is_explained(): void
    {
        add_filter('fluent_cart/theme/palette', '__return_empty_array');
        ThemePalette::clearCache();

        $preview = Arr::get(
            (new StoreSettings())->fields(),
            'setting_tabs.schema.appearance.schema.appearance_component.preview',
            []
        );

        $this->assertSame(
            [],
            Arr::get($preview, 'theme_roles', ['unset']),
            'with nothing to inherit the storefront writes nothing, so the preview must change nothing either'
        );
        $this->assertNotSame(
            '',
            Arr::get($preview, 'notes.' . ColorPalette::SOURCE_THEME),
            'a store owner picking inheritance deserves to know it will do nothing here'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [45]
     *   "Setting the card background writes the property the product card
     *    reads."
     */
    public function test_the_card_background_writes_the_property_the_card_reads(): void
    {
        // product-card.scss hard-coded #ffffff for the card surface — the only
        // scoped variable in that file not deferring to a global, which is why
        // no knob for it existed. It now reads var(--fct-card-bg-color).
        $this->assertSame(
            '--fct-card-bg-color',
            Arr::get(ColorPalette::globals(), 'card_bg_color.var'),
            'the card background must write the property the product card reads'
        );

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => ['card_bg_color' => '#fff8e1'],
        ]);
        StoreSettings::clearCache();

        $this->assertStringContainsString(
            '--fct-card-bg-color:#fff8e1',
            FrontendTheme::buildCss(),
            'a card background the owner set has to reach the page to be worth setting'
        );
    }
}
