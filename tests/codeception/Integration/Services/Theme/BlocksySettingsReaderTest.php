<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\BlocksySettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours a Blocksy owner set, not the slots Blocksy ships them in.
 *
 * Inheritance read Blocksy's palette by position — color1 the accent and the
 * button, color3 the text, color8 the surface. Blocksy's Customizer keeps the
 * semantic colours as their own theme mods (Links, Base Text, Borders, Site
 * Background, Buttons), each pointing at any palette slot or holding a custom
 * colour, and the button is set apart from the link colour. Once the owner
 * moved one, FluentCart kept wearing the default slot.
 *
 * The reader reads those mods through `blocksy_get_theme_mod()` and resolves
 * each palette reference through `blocksy_manager()->colors->get_color_palette()`,
 * keeping the reference live (Companion Pro's dark mode redefines the palette
 * properties) and measuring its fallback. Blocksy is not loaded in the test
 * install; both functions are stubbed (tests/Support/Stubs/BlocksyStubs.php)
 * and driven per test.
 */
class BlocksySettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    /** Blocksy 2.1's default palette (inc/classes/colors.php), color1..color8. */
    const PALETTE = ['#2872fa', '#1559ed', '#3A4F66', '#192a3d', '#e1e8ed', '#f2f5f7', '#FAFBFC', '#ffffff'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BlocksyStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \BlocksyThemeModsStub::reset();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \BlocksyThemeModsStub::reset();
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

    /**
     * A Blocksy install with these theme mods saved. With none, every mod
     * takes Blocksy's own default — exactly an untouched install.
     */
    protected function blocksy(array $mods = []): void
    {
        \BlocksyThemeModsStub::$mods = $mods;
        $this->reset();
    }

    /** A colour mod as Blocksy's Customizer saves it. */
    protected function pair(string $color, string $hover = ''): array
    {
        $value = ['default' => ['color' => $color]];

        if ($hover !== '') {
            $value['hover'] = ['color' => $hover];
        }

        return $value;
    }

    /** A site background object as Blocksy saves it (flat). */
    protected function background(string $color, string $type = 'color'): array
    {
        return [
            'background_type' => $type,
            'backgroundColor' => ['default' => ['color' => $color]],
        ];
    }

    /** The editor palette Blocksy publishes: live references with hex fallbacks. */
    protected function inherit(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (self::PALETTE as $index => $hex) {
                $slot = $index + 1;
                $palette[] = [
                    'slug'  => 'palette-color-' . $slot,
                    'name'  => 'Color ' . $slot,
                    'color' => 'var(--theme-palette-color-' . $slot . ', ' . $hex . ')',
                ];
            }

            return $palette;
        });

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    /** Palette slot N (1-based, as Blocksy numbers them), live with its fallback. */
    protected function swatch(int $slot): string
    {
        return 'var(--theme-palette-color-' . $slot . ', ' . strtolower(self::PALETTE[$slot - 1]) . ')';
    }

    /**
     * Spec: Cases/storefront-colours.md [89]
     *   "Inheriting from Blocksy, every role follows the Customizer colour that
     *    drives it — accent from Links, text from Base Text, the surface from
     *    Site Background, the border from Borders, the button and its hover
     *    from Buttons — each palette colour a live reference with a measurable
     *    fallback, and Blocksy's stated button text worn as given."
     */
    public function test_blocksys_defaults_are_read_from_its_theme_mods(): void
    {
        $this->blocksy();
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(1), $roles['accent'], 'Links (linkColor)');
        $this->assertSame($this->swatch(3), $roles['text'], 'Base Text (fontColor)');
        $this->assertSame($this->swatch(7), $roles['surface'], 'Site Background, not the color8 slot guess');
        $this->assertSame($this->swatch(5), $roles['border'], 'Borders (border_color), not a derived mix');
        $this->assertSame($this->swatch(1), $roles['button_bg'], 'Buttons (buttonColor)');
        $this->assertSame('#ffffff', $roles['button_text'], 'Blocksy states its button text');
        $this->assertSame($this->swatch(2), $roles['button_hover_bg'], 'buttonColor hover');
        $this->assertSame('#ffffff', $roles['button_hover_text'], 'buttonTextColor hover');

        // Tones nothing states still mix from the owner's real colours. Muted
        // text moves toward the body text until it reads (spec 118): the plain
        // 68% mix of Blocksy's #3a4f66 was 3.6:1 on #fafbfc.
        $this->assertSame(ColorMath::readableMix('#3a4f66', '#fafbfc', 68), $roles['text_muted']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#fafbfc', $roles['text_muted']));

        $css = FrontendTheme::buildCss();
        $this->assertStringContainsString('--fct-btn-bg-color:' . $this->swatch(1) . ';', $css, 'the button is owned: its colour measures');
        $this->assertStringContainsString('--fct-border-color:' . $this->swatch(5) . ';', $css);
    }

    /**
     * Spec: Cases/storefront-colours.md [90]
     *   "A Blocksy owner who points Links at another palette colour sees the
     *    accent follow it while the button keeps its own Buttons colour — and
     *    the other way round."
     */
    public function test_links_moved_to_another_slot_moves_the_accent_not_the_button(): void
    {
        $this->blocksy(['linkColor' => $this->pair('var(--theme-palette-color-4)', 'var(--theme-palette-color-3)')]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(4), $roles['accent']);
        $this->assertSame($this->swatch(1), $roles['button_bg'], 'the button has its own setting in Blocksy');
    }

    public function test_buttons_moved_to_another_slot_moves_the_button_not_the_accent(): void
    {
        $this->blocksy(['buttonColor' => $this->pair('var(--theme-palette-color-4)', 'var(--theme-palette-color-3)')]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(1), $roles['accent'], 'Links keeps its own setting');
        $this->assertSame($this->swatch(4), $roles['button_bg']);
        $this->assertSame($this->swatch(3), $roles['button_hover_bg']);
    }

    /**
     * Spec: Cases/storefront-colours.md [91]
     *   "Button colours the Blocksy owner set are worn as given; a button text
     *    FluentCart cannot write gets a readable partner instead."
     */
    public function test_custom_button_colours_are_worn_as_given(): void
    {
        $this->blocksy([
            'buttonColor'     => $this->pair('#FF5500', '#222222'),
            'buttonTextColor' => $this->pair('#000000', '#FEFEFE'),
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ff5500', $roles['button_bg']);
        $this->assertSame('#000000', $roles['button_text']);
        $this->assertSame($this->swatch(1), $roles['accent'], 'the accent keeps its own setting');

        $colors = FrontendTheme::getEffectiveColors();
        $this->assertSame('#ff5500', $colors['btn_bg_color']);
        $this->assertSame('#000000', $colors['btn_text_color']);
    }

    public function test_an_unwritable_button_text_gets_a_readable_partner(): void
    {
        $this->blocksy([
            'buttonColor'     => $this->pair('var(--theme-palette-color-5)'),
            'buttonTextColor' => $this->pair('rgba(0, 0, 0, 0.8)', 'rgba(0, 0, 0, 0.8)'),
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(5), $roles['button_bg']);
        $this->assertSame(ColorMath::readableOn('#e1e8ed'), $roles['button_text'], 'light swatch, dark text');
        $this->assertSame(ColorMath::readableOn('#1559ed'), $roles['button_hover_text'], 'measured on the hover background');
    }

    /**
     * Spec: Cases/storefront-colours.md [92]
     *   "The Blocksy owner's button hover pair is followed; a hover half the
     *    owner never saved takes Blocksy's own default for it."
     */
    public function test_the_owners_hover_pair_is_followed(): void
    {
        $this->blocksy([
            'buttonColor'     => $this->pair('var(--theme-palette-color-1)', '#222222'),
            'buttonTextColor' => $this->pair('#ffffff', '#FEFEFE'),
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);
    }

    public function test_a_hover_the_owner_never_saved_takes_blocksys_default(): void
    {
        // Saved with the resting colour only: Blocksy fills each sub-key from
        // its own default (blocksy_get_colors()).
        $this->blocksy(['buttonColor' => $this->pair('#FF5500')]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ff5500', $roles['button_bg']);
        $this->assertSame($this->swatch(2), $roles['button_hover_bg']);
        $this->assertSame('#ffffff', $roles['button_hover_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [93]
     *   "A site background the Blocksy owner set — flat or per device — is the
     *    surface, and the derived tones mix from it; a gradient background
     *    states no surface colour."
     */
    public function test_a_custom_site_background_drives_the_surface_and_tones(): void
    {
        $this->blocksy([
            'site_background' => $this->background('#101418'),
            'fontColor'       => $this->pair('#E5E7EB'),
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface']);
        $this->assertSame('#e5e7eb', $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt'], 'derived tones mix from the owner\'s colours');
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 68), $roles['text_muted']);
    }

    public function test_a_responsive_site_background_reads_desktop(): void
    {
        $this->blocksy([
            'site_background' => [
                'desktop' => $this->background('#101418'),
                'tablet'  => $this->background('#ffffff'),
                'mobile'  => $this->background('#ffffff'),
            ],
        ]);
        $this->inherit();

        $this->assertSame('#101418', ThemePalette::resolve()['surface']);
    }

    public function test_a_gradient_site_background_states_no_surface(): void
    {
        $this->blocksy(['site_background' => $this->background('#101418', 'gradient')]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('surface', $stated, 'Blocksy paints a gradient, not the colour');
        $this->assertSame('var(--theme-palette-color-8, #ffffff)', ThemePalette::resolve()['surface'], 'the palette-slot guess stays');
    }

    /**
     * Spec: Cases/storefront-colours.md [94]
     *   "A Blocksy colour saved as its inherit sentinel (CT_CSS_SKIP_RULE) is
     *    unset and takes Blocksy's default; one FluentCart cannot write
     *    (rgba()) is not stated, and the role falls back as before."
     */
    public function test_a_skip_rule_sentinel_is_unset(): void
    {
        $this->blocksy([
            'linkColor'       => $this->pair('CT_CSS_SKIP_RULE'),
            'fontColor'       => $this->pair('CT_CSS_SKIP_RULEDEFAULT'),
            'site_background' => $this->background('CT_CSS_SKIP_RULE'),
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame($this->swatch(1), $stated['accent']);
        $this->assertSame($this->swatch(3), $stated['text']);
        $this->assertSame($this->swatch(7), $stated['surface']);
    }

    public function test_an_rgba_colour_is_not_stated(): void
    {
        $this->blocksy([
            'linkColor'   => $this->pair('rgba(40, 114, 250, 0.9)'),
            'buttonColor' => $this->pair('rgba(255, 0, 0, 0.5)', 'rgba(255, 0, 0, 0.7)'),
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated);

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ': no partner for a background that was not stated');
        }

        $this->assertSame($this->swatch(3), $stated['text'], 'the rest is still read');
        $this->assertSame($this->swatch(1), ThemePalette::resolve()['button_bg'], 'the button keeps the palette-slot fallback');
    }

    /**
     * Spec: Cases/storefront-colours.md [95]
     *   "The site editor's button outranks the Blocksy settings, and Blocksy's
     *    hover is not paired onto it."
     */
    public function test_the_site_editor_button_outranks_the_reader(): void
    {
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => ['elements' => ['button' => ['color' => ['background' => '#111111', 'text' => '#ffffff']]]],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $this->blocksy([
            'buttonColor'     => $this->pair('#ff5500', '#222222'),
            'buttonTextColor' => $this->pair('#000000', '#fefefe'),
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'Blocksy\'s hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [96]
     *   "With no Blocksy settings to read — Blocksy absent, or the reader
     *    filtered off — inheritance behaves exactly as before; FluentCart's own
     *    and customised colours are untouched by the reader."
     */
    public function test_a_disabled_reader_leaves_the_palette_slot_behaviour(): void
    {
        // Blocksy absent: the same editor palette, no theme mods to read.
        $this->inherit();
        $this->assertSame([], ThemePalette::settingsRoles(), 'no Blocksy, nothing read');
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();

        $this->assertSame($this->swatch(1), $before['button_bg']);
        $this->assertSame('var(--theme-palette-color-8, #ffffff)', $before['surface'], 'the slot guess');
        $this->assertSame(ColorMath::mix('#3a4f66', '#ffffff', 18), $before['border'], 'the border is derived as it always was');

        // Blocksy with custom settings, but the reader filtered off.
        $this->blocksy([
            'linkColor'       => $this->pair('var(--theme-palette-color-4)'),
            'buttonColor'     => $this->pair('#ff5500'),
            'site_background' => $this->background('#101418'),
        ]);
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->reset();

        $this->assertSame($before, ThemePalette::resolve());
        $this->assertSame($beforeCss, FrontendTheme::buildCss());
    }

    public function test_default_and_customize_are_untouched_by_the_reader(): void
    {
        $sources = [
            ColorPalette::SOURCE_DEFAULT => [],
            ColorPalette::SOURCE_CUSTOM  => ['btn_bg_color' => '#809400', 'primary_text_color' => '#222222'],
        ];

        foreach ($sources as $source => $colors) {
            update_option(self::OPTION, ['appearance_source' => $source, 'appearance_colors' => $colors]);

            \BlocksyThemeModsStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->blocksy(['linkColor' => $this->pair('var(--theme-palette-color-4)'), 'buttonColor' => $this->pair('#ff5500')]);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with Blocksy settings present');
        }
    }

    public function test_the_filter_sees_the_theme(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->blocksy();
        $this->inherit();

        ThemePalette::settingsRoles();

        $this->assertSame('blocksy', $seen['theme'] ?? null);
    }

    /**
     * Spec: Cases/storefront-colours.md [97]
     *   "A Blocksy palette colour is resolved by the property Blocksy prints it
     *    as — a slot's own custom variable name, and slots past the default
     *    eight — so the reference stays live and measurable."
     */
    public function test_a_custom_palette_variable_and_extra_slots_resolve(): void
    {
        $palette = [];

        foreach (self::PALETTE as $index => $hex) {
            $palette['color' . ($index + 1)] = ['color' => $hex];
        }

        $palette['color1']['variable'] = 'brand-primary';
        $palette['color9'] = ['color' => '#7C3AED'];

        $this->blocksy([
            'colorPalette' => $palette,
            'linkColor'    => $this->pair('var(--brand-primary)'),
            'buttonColor'  => $this->pair('var(--theme-palette-color-9)'),
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('var(--brand-primary, #2872fa)', $stated['accent']);
        $this->assertSame('var(--theme-palette-color-9, #7c3aed)', $stated['button_bg']);
    }

    /** A spacing value as Blocksy's ct-spacing control saves it. */
    protected function spacing(array $values, int $state = 1, string $custom = ''): array
    {
        return [
            'state'  => $state,
            'values' => array_map(function ($value) {
                return is_array($value) ? $value : ['value' => $value, 'unit' => 'px'];
            }, $values),
            'custom' => $custom,
        ];
    }

    /**
     * Spec: Cases/storefront-colours.md [208]
     *   "An untouched Blocksy states its own 3px button and 3px field radius."
     */
    public function test_an_untouched_blocksy_states_its_own_radii(): void
    {
        $this->blocksy();

        $this->assertSame(['btn' => '3px', 'input' => '3px'], BlocksySettingsReader::radii());

        $this->blocksy(['buttonRadius' => $this->spacing(['', '', '', ''])]);
        $this->assertSame('3px', BlocksySettingsReader::radii()['btn'], 'a linked value left empty is Blocksy\'s 3px');
    }

    /**
     * Spec: Cases/storefront-colours.md [209]
     *   "Blocksy's button radius follows the owner's linked value (desktop of
     *    a responsive one) and its custom value; modern forms state a 0 field
     *    radius."
     */
    public function test_blocksys_owner_radii_are_followed(): void
    {
        // The local site's own saved shape.
        $this->blocksy([
            'buttonRadius'          => $this->spacing(['100', '100', '100', '100']),
            'formFieldBorderRadius' => 30,
            'cardProductRadius'     => $this->spacing(['12', '12', '12', '12']),
        ]);

        $this->assertSame(['btn' => '100px', 'input' => '30px'], BlocksySettingsReader::radii(), 'the WooCommerce card radius is not a FluentCart card');
        $this->assertSame(['btn' => '100px', 'input' => '30px'], ThemePalette::radii());

        $this->blocksy([
            'buttonRadius' => [
                'desktop' => $this->spacing([['value' => 1, 'unit' => 'rem'], ['value' => 1, 'unit' => ''], ['value' => 1, 'unit' => ''], ['value' => 1, 'unit' => '']]),
                'tablet'  => $this->spacing(['4', '4', '4', '4']),
                'mobile'  => $this->spacing(['4', '4', '4', '4']),
            ],
            'forms_type' => 'modern-forms',
        ]);

        $this->assertSame(['btn' => '1rem', 'input' => '0'], BlocksySettingsReader::radii(), 'desktop speaks for all; a side with no unit takes the others\'');

        $this->blocksy(['buttonRadius' => $this->spacing(['', '', '', ''], 3, '12px')]);
        $this->assertSame('12px', BlocksySettingsReader::radii()['btn'], 'a custom value is worn');
    }

    /**
     * Spec: Cases/storefront-colours.md [203]
     *   "Per-corner radii that differ, or a value outside the grammar, state
     *    nothing."
     */
    public function test_blocksy_radii_outside_the_grammar_are_dropped(): void
    {
        $this->blocksy([
            'buttonRadius'          => $this->spacing(['10', '0', '10', '0'], 2),
            'formFieldBorderRadius' => 'large',
        ]);
        $this->assertSame([], BlocksySettingsReader::radii());

        $this->blocksy(['buttonRadius' => $this->spacing(['', '', '', ''], 3, '10px 4px')]);
        $this->assertArrayNotHasKey('btn', ThemePalette::radii(), 'a two-value custom radius is not one length');

        $this->blocksy(['buttonRadius' => $this->spacing([['value' => 50, 'unit' => '%'], ['value' => 50, 'unit' => '%'], ['value' => 50, 'unit' => '%'], ['value' => 50, 'unit' => '%']])]);
        $this->assertArrayNotHasKey('btn', ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [214]
     *   "A theme reader the site switched off states no radius either; the
     *    site editor's radius, which is not a reader, still counts."
     */
    public function test_a_switched_off_reader_states_no_radius_but_the_site_editor_does(): void
    {
        $this->blocksy([
            'buttonColor'           => $this->pair('#ff5500'),
            'buttonRadius'          => $this->spacing(['20', '20', '20', '20']),
            'formFieldBorderRadius' => 12,
        ]);
        $this->assertSame(['btn' => '20px', 'input' => '12px'], ThemePalette::radii(), 'switched on, the reader states its radii');

        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->reset();

        $this->assertSame([], ThemePalette::radii(), 'switched off, it states none');

        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 3,
                'styles'  => ['elements' => ['button' => ['border' => ['radius' => '6px']]]],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $this->reset();

        $this->assertSame(['btn' => '6px'], ThemePalette::radii(), 'the site editor is not a reader');
    }
}
