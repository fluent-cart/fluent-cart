<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\DiviSettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours a Divi owner set.
 *
 * Divi publishes no palette to theme.json, so inheriting from it read nothing
 * at all: the store wrote no colours and marked the body `fluent-cart-no-colors`.
 * Divi's Customizer keeps its colours as flat values in the `et_divi` option —
 * Theme Accent Color, Body Text Color and the Buttons section — and paints the
 * content area from the core Background Color.
 *
 * The reader reads them through `et_get_option()`, the call Divi prints its own
 * CSS from. Accent and text are Divi 5's Customizer global colours, stated live
 * as `var(--gcid-primary-color, #hex)` / `var(--gcid-body-color, #hex)`. Divi's
 * default button is an outline, so only a solid background the owner set is a
 * filled button. Divi is not loaded in the test install; `et_get_option()` is
 * stubbed (tests/Support/Stubs/DiviStubs.php) and driven per test, and the
 * background theme mods are filtered, never written.
 */
class DiviSettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/DiviStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \DiviOptionsStub::reset();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \DiviOptionsStub::reset();
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
     * A Divi install with these `et_divi` values saved. With none, every
     * setting takes Divi's own default — exactly an untouched install.
     */
    protected function divi(array $options = []): void
    {
        \DiviOptionsStub::arm($options);
        $this->reset();
    }

    /** The core Background Color theme mod, as WordPress saves it (no `#`). */
    protected function background(string $color): void
    {
        add_filter('theme_mod_background_color', function () use ($color) {
            return $color;
        });

        $this->reset();
    }

    /** Divi publishes no palette: inheriting has only its settings to go on. */
    protected function inherit(): void
    {
        add_filter('fluent_cart/theme/palette', '__return_empty_array');

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    protected function accent(string $hex): string
    {
        return 'var(--gcid-primary-color, ' . $hex . ')';
    }

    protected function text(string $hex): string
    {
        return 'var(--gcid-body-color, ' . $hex . ')';
    }

    protected function bodyClasses(): array
    {
        $this->reset();

        return apply_filters('body_class', ['existing-theme-class'], []);
    }

    /**
     * Spec: Cases/storefront-colours.md [108]
     *   "Inheriting from Divi, the accent follows Theme Accent Color and the
     *    text Body Text Color — each a live Divi global colour with its hex as
     *    the fallback — on the white content area Divi paints, and the derived
     *    tones mix from that pair. Divi's default outline button states no
     *    filled button."
     */
    public function test_divis_defaults_are_read_from_its_settings(): void
    {
        $this->divi();
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame($this->accent('#2ea3f2'), $stated['accent'], 'Theme Accent Color default');
        $this->assertSame($this->text('#666666'), $stated['text'], 'Body Text Color default');
        $this->assertSame('#ffffff', $stated['surface'], '#main-content is white with no Background Color');
        $this->assertArrayNotHasKey('border', $stated, 'Divi has no general border setting');

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ': Divi\'s default button is an outline, not a fill');
        }

        $roles = ThemePalette::resolve();

        $this->assertSame(ColorMath::mix('#666666', '#ffffff', 4), $roles['surface_alt'], 'the derived tones mix from Divi\'s pair');
        $this->assertSame(ColorMath::mix('#666666', '#ffffff', 18), $roles['border']);
        $this->assertSame($this->accent('#2ea3f2'), $roles['button_bg'], 'no filled button: the accent fallback, as before');
    }

    /**
     * Spec: Cases/storefront-colours.md [109]
     *   "A Divi owner who changes Theme Accent Color or Body Text Color sees
     *    the accent and the text follow; a value saved empty is what Divi's
     *    stylesheet treats as unset, and takes Divi's default."
     */
    public function test_a_custom_accent_and_text_are_followed(): void
    {
        $this->divi(['accent_color' => '#7C3AED', 'font_color' => '#222222']);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame($this->accent('#7c3aed'), $stated['accent']);
        $this->assertSame($this->text('#222222'), $stated['text']);
        $this->assertArrayNotHasKey('button_bg', $stated, 'Divi\'s button does not fill with the accent');
        $this->assertSame($this->accent('#7c3aed'), ThemePalette::resolve()['button_bg'], 'FluentCart\'s own accent fallback');
    }

    public function test_an_empty_saved_colour_takes_divis_default(): void
    {
        $this->divi(['accent_color' => '', 'font_color' => '']);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame($this->accent('#2ea3f2'), $stated['accent']);
        $this->assertSame($this->text('#666666'), $stated['text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [110]
     *   "A filled button the Divi owner set — a solid background colour — is
     *    worn with its text; a text left empty keeps the accent Divi paints on
     *    it while that reads, otherwise a readable partner."
     */
    public function test_a_custom_filled_button_is_worn_as_given(): void
    {
        $this->divi(['all_buttons_bg_color' => '#FF5500', 'all_buttons_text_color' => '#000000']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ff5500', $roles['button_bg']);
        $this->assertSame('#000000', $roles['button_text']);
        $this->assertSame($this->accent('#2ea3f2'), $roles['accent'], 'the accent keeps its own setting');

        $colors = FrontendTheme::getEffectiveColors();
        $this->assertSame('#ff5500', $colors['btn_bg_color']);
        $this->assertSame('#000000', $colors['btn_text_color']);
    }

    public function test_an_empty_button_text_keeps_the_accent_while_it_reads(): void
    {
        $this->divi(['all_buttons_bg_color' => '#111111']);
        $this->inherit();

        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#111111', '#2ea3f2'), 'precondition');
        $this->assertSame($this->accent('#2ea3f2'), ThemePalette::settingsRoles()['button_text'], 'what Divi paints on its button');
    }

    public function test_an_empty_button_text_that_would_not_read_gets_a_readable_partner(): void
    {
        $this->divi(['all_buttons_bg_color' => '#2EA3F2']);
        $this->inherit();

        $this->assertSame(ColorMath::readableOn('#2ea3f2'), ThemePalette::settingsRoles()['button_text'], 'accent on accent cannot be read');
    }

    /**
     * Spec: Cases/storefront-colours.md [111]
     *   "The Divi owner's button hover pair is followed; a hover background
     *    alone keeps the resting text while it reads, and Divi's default hover
     *    tint states no hover."
     */
    public function test_the_owners_hover_pair_is_followed(): void
    {
        $this->divi([
            'all_buttons_bg_color'         => '#FF5500',
            'all_buttons_text_color'       => '#000000',
            'all_buttons_bg_color_hover'   => '#222222',
            'all_buttons_text_color_hover' => '#FEFEFE',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);
    }

    public function test_a_hover_background_alone_keeps_the_resting_text(): void
    {
        $this->divi([
            'all_buttons_bg_color'       => '#FF5500',
            'all_buttons_text_color'     => '#FFFFFF',
            'all_buttons_bg_color_hover' => '#222222',
        ]);
        $this->inherit();

        $this->assertArrayNotHasKey('button_hover_text', ThemePalette::settingsRoles(), 'Divi prints no hover text');

        $roles = ThemePalette::resolve();
        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#ffffff', $roles['button_hover_text'], 'the resting text carries over, as Divi paints it');
    }

    public function test_divis_default_hover_tint_states_no_hover(): void
    {
        $this->divi(['all_buttons_bg_color' => '#FF5500', 'all_buttons_text_color' => '#FFFFFF']);
        $this->inherit();

        $this->assertArrayNotHasKey('button_hover_bg', ThemePalette::settingsRoles(), 'rgba(255,255,255,0.2) is a tint');
        $derived = ColorMath::isDark('#ff5500') ? ColorMath::lighten('#ff5500', 12) : ColorMath::darken('#ff5500', 12);
        $this->assertSame($derived, ThemePalette::resolve()['button_hover_bg'], 'derived from the button as before');
    }

    /**
     * Spec: Cases/storefront-colours.md [112]
     *   "Divi's outline button — its transparent default, a background saved
     *    empty, or any background that is not a solid colour — states no
     *    filled button, and its hover is not stated without it."
     */
    public function test_an_outline_button_states_no_button(): void
    {
        $outlines = ['', 'rgba(0,0,0,0)', 'rgba(255, 85, 0, 0.6)', 'rgb(255,85,0)'];

        foreach ($outlines as $background) {
            $this->divi([
                'all_buttons_bg_color'         => $background,
                'all_buttons_text_color'       => '#ffffff',
                'all_buttons_bg_color_hover'   => '#222222',
                'all_buttons_text_color_hover' => '#fefefe',
            ]);
            $this->inherit();

            $stated = ThemePalette::settingsRoles();

            foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
                $this->assertArrayNotHasKey($role, $stated, $role . ' with background "' . $background . '"');
            }
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [113]
     *   "A Background Color the Divi owner set is the surface Divi paints the
     *    content area with, and the derived tones mix from it and the body
     *    text; a Background Image states no surface colour."
     */
    public function test_a_background_colour_drives_the_surface_and_tones(): void
    {
        $this->divi(['font_color' => '#E5E7EB']);
        $this->background('101418');
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface'], 'the theme mod is saved without its #');
        $this->assertSame($this->text('#e5e7eb'), $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 68), $roles['text_muted']);
    }

    public function test_a_background_image_states_no_surface(): void
    {
        $this->divi();
        $this->background('101418');
        add_filter('theme_mod_background_image', function () {
            return 'https://example.test/bg.jpg';
        });
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('surface', $stated, '#main-content turns transparent over an image');
        $this->assertSame('', ThemePalette::resolve()['surface'], 'nothing guessed');
        $this->assertSame('var(--gcid-body-color, #666666)', ThemePalette::resolve()['text'], 'the stated body text is worn as given');
    }

    /**
     * Spec: Cases/storefront-colours.md [114]
     *   "A Divi colour FluentCart cannot write — rgb(), rgba(), a Divi 5
     *    relative hsl() or a `$variable()$` reference — is not stated."
     */
    public function test_unwritable_colours_are_not_stated(): void
    {
        $this->divi([
            'accent_color' => 'rgba(46,163,242,0.9)',
            'font_color'   => '$variable({"type":"color","value":{"name":"gcid-abc","settings":{}}})$',
            'all_buttons_bg_color' => 'hsl(from var(--gcid-primary-color) calc(h + 0) calc(s + 0) calc(l + 0) / 0.5)',
        ]);
        $this->background('zzzzzz');
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated);
        $this->assertArrayNotHasKey('text', $stated);
        $this->assertArrayNotHasKey('surface', $stated, 'a theme mod that is not a colour');
        $this->assertArrayNotHasKey('button_bg', $stated);
    }

    /**
     * Spec: Cases/storefront-colours.md [115]
     *   "The site editor's button outranks the Divi settings, and Divi's hover
     *    is not paired onto it."
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

        $this->divi([
            'all_buttons_bg_color'         => '#ff5500',
            'all_buttons_text_color'       => '#000000',
            'all_buttons_bg_color_hover'   => '#222222',
            'all_buttons_text_color_hover' => '#fefefe',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'Divi\'s hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [116]
     *   "Divi, which publishes no palette, becomes a theme FluentCart can
     *    inherit from: its settings are written and the body is not marked
     *    no-colors. With the reader filtered off it behaves exactly as before
     *    — nothing written, the body marked no-colors — and FluentCart's own
     *    and customised colours are untouched by the reader."
     */
    public function test_divi_becomes_a_usable_source(): void
    {
        $this->divi();
        $this->inherit();

        $this->assertTrue(ThemePalette::hasUsableSource());
        $this->assertNotSame([], FrontendTheme::getThemeColors(), 'Divi\'s colours are written');
        $this->assertNotContains('fluent-cart-no-colors', $this->bodyClasses());
    }

    public function test_a_disabled_reader_leaves_the_before_behaviour(): void
    {
        // Before: Divi loaded, nothing read.
        $this->divi();
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles());
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();
        $beforeClasses = $this->bodyClasses();

        $this->assertSame([], FrontendTheme::getThemeColors(), 'no palette, no reader: nothing to write');
        $this->assertContains('fluent-cart-no-colors', $beforeClasses);

        // Custom Divi settings, still filtered off: nothing moves.
        $this->divi([
            'accent_color'         => '#7c3aed',
            'all_buttons_bg_color' => '#ff5500',
        ]);
        $this->background('101418');

        $this->assertSame($before, ThemePalette::resolve());
        $this->assertSame($beforeCss, FrontendTheme::buildCss());
        $this->assertSame($beforeClasses, $this->bodyClasses());
    }

    public function test_default_and_customize_are_untouched_by_the_reader(): void
    {
        $sources = [
            ColorPalette::SOURCE_DEFAULT => [],
            ColorPalette::SOURCE_CUSTOM  => ['btn_bg_color' => '#809400', 'primary_text_color' => '#222222'],
        ];

        foreach ($sources as $source => $colors) {
            update_option(self::OPTION, ['appearance_source' => $source, 'appearance_colors' => $colors]);

            \DiviOptionsStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->divi(['accent_color' => '#7c3aed', 'all_buttons_bg_color' => '#ff5500']);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with Divi settings present');
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [117]
     *   "The Divi reader applies only to Divi: another Elegant Themes product
     *    sharing `et_get_option()` is not read as Divi, and Divi still gets its
     *    turn when other vendors' readers are loaded."
     */
    public function test_another_elegant_themes_product_is_not_divi(): void
    {
        $this->divi(['accent_color' => '#7c3aed']);
        $GLOBALS['shortname'] = 'extra';
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles(), 'et_extra is not et_divi');
    }

    public function test_the_filter_sees_the_theme(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->divi();
        $this->inherit();

        ThemePalette::settingsRoles();

        $this->assertSame('divi', $seen['theme'] ?? null);
    }

    /**
     * Only one theme runs on a site, but the test process keeps every vendor
     * stub another test class loaded. Astra's stub answers for
     * `astra_get_option()` forever once loaded, so Divi has to be asked
     * before Astra — and an idle Divi stub must not claim ahead of Astra.
     */
    public function test_divi_is_read_with_other_vendor_stubs_loaded(): void
    {
        require_once dirname(__DIR__, 3) . '/Support/Stubs/AstraStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BlocksyStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/KadenceStubs.php';

        \AstraOptionsStub::reset();
        \BlocksyThemeModsStub::reset();
        \KadenceOptionsStub::reset();

        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->divi(['accent_color' => '#7c3aed']);
        $this->inherit();

        $this->assertSame($this->accent('#7c3aed'), ThemePalette::settingsRoles()['accent']);
        $this->assertSame('divi', $seen['theme'] ?? null);

        // Divi gone: the idle stub stands aside and Astra's answers.
        \DiviOptionsStub::reset();
        $this->reset();

        ThemePalette::settingsRoles();
        $this->assertSame('astra', $seen['theme'] ?? null);
    }

    /**
     * Spec: Cases/storefront-colours.md [211]
     *   "Divi's button radius is its Buttons border radius in px, 3px untouched."
     */
    public function test_divis_button_radius_is_read(): void
    {
        $this->divi();
        $this->assertSame(['btn' => '3px'], DiviSettingsReader::radii(), 'Divi\'s default; it has no input or card radius setting');

        $this->divi(['all_buttons_border_radius' => '18']);
        $this->assertSame(['btn' => '18px'], ThemePalette::radii());

        $this->divi(['all_buttons_border_radius' => '0']);
        $this->assertSame(['btn' => '0px'], DiviSettingsReader::radii());
    }
}
