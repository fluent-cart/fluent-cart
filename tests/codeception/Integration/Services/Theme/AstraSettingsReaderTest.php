<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\AstraSettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours an Astra owner set, not the slots Astra ships them in.
 *
 * Inheritance used to read Astra's palette by position — swatch 0 the accent
 * and the button, 3 the text, 4 the surface. That holds only while every Theme
 * Color setting keeps its default. Astra's Customizer lets the owner point the
 * Accent, Body Text, Content Background and each Button colour at any swatch or
 * a custom hex, and the moment they did, FluentCart kept wearing swatch 0.
 *
 * The reader reads those settings through `astra_get_option()` — Astra's own
 * saved-over-defaults merge — and resolves each palette reference through the
 * palette FluentCart already reads, keeping the reference live and measuring
 * its fallback. Astra is not loaded in the test install; the function is
 * stubbed (tests/Support/Stubs/AstraStubs.php) and driven per test.
 */
class AstraSettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    /** Astra 4.13's default palette, as its Customizer saves it. */
    const PALETTE = ['#046bd2', '#045cb4', '#1e293b', '#334155', '#FFFFFF', '#F0F5FA', '#111111', '#D1D5DB', '#111111'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/AstraStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \AstraOptionsStub::reset();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \AstraOptionsStub::reset();
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
     * Astra's defaults for every key the reader reads, with overrides.
     *
     * Every Theme Color key is a palette reference; the four primary button
     * keys are empty, which Astra resolves through its own fallback chain.
     */
    protected function astra(array $overrides = []): void
    {
        $responsive = function ($color) {
            return [
                'desktop' => ['background-color' => $color, 'background-type' => 'color'],
                'tablet'  => ['background-color' => ''],
                'mobile'  => ['background-color' => ''],
            ];
        };

        \AstraOptionsStub::$options = array_merge([
            'global-color-palette'                  => ['palette' => self::PALETTE],
            'theme-color'                           => 'var(--ast-global-color-0)',
            'link-h-color'                          => 'var(--ast-global-color-1)',
            'text-color'                            => 'var(--ast-global-color-3)',
            'border-color'                          => 'var(--ast-global-color-7)',
            'content-bg-obj-responsive'             => $responsive('var(--ast-global-color-4)'),
            'site-layout-outside-bg-obj-responsive' => $responsive('var(--ast-global-color-5)'),
            'button-bg-color'                       => '',
            'button-color'                          => '',
            'button-bg-h-color'                     => '',
            'button-h-color'                        => '',
            'button-preset-style'                   => '',
        ], $overrides);

        $this->reset();
    }

    /** The palette Astra publishes to theme.json: bare references to its swatches. */
    protected function inherit(): void
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

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    protected function swatch(int $index): string
    {
        return 'var(--ast-global-color-' . $index . ', ' . strtolower(self::PALETTE[$index]) . ')';
    }

    /**
     * Spec: Cases/storefront-colours.md [81]
     *   "Inheriting from Astra, every role follows the Theme Color setting that
     *    drives it — accent and button from Accent, text from Body Text, the
     *    surface from Content Background, the border from Borders, the hover
     *    from Link Hover — each a live palette reference with a measurable
     *    fallback, and each button half readable on its background."
     */
    public function test_astras_defaults_are_read_from_its_settings(): void
    {
        $this->astra();
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(0), $roles['accent'], 'Accent (theme-color)');
        $this->assertSame($this->swatch(0), $roles['button_bg'], 'an empty button-bg-color follows theme-color');
        $this->assertSame($this->swatch(3), $roles['text'], 'Body Text (text-color)');
        $this->assertSame($this->swatch(4), $roles['surface'], 'Content Background, desktop');
        $this->assertSame($this->swatch(7), $roles['border'], 'Borders (border-color), not a derived mix');
        $this->assertSame($this->swatch(1), $roles['button_hover_bg'], 'an empty button-bg-h-color follows link-h-color');
        $this->assertSame(ColorMath::readableOn('#046bd2'), $roles['button_text']);
        $this->assertSame(ColorMath::readableOn('#045cb4'), $roles['button_hover_text']);

        // Tones nothing states still mix from the owner's real colours. Muted
        // text moves toward the body text until it reads (spec 118): the plain
        // 68% mix of Astra's #334155 was 4.12:1 on white.
        $this->assertSame(ColorMath::readableMix('#334155', '#ffffff', 68), $roles['text_muted']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#ffffff', $roles['text_muted']));

        $css = FrontendTheme::buildCss();
        $this->assertStringContainsString('--fct-btn-bg-color:' . $this->swatch(0) . ';', $css, 'the button is owned: its colour measures');
        $this->assertStringContainsString('--fct-border-color:' . $this->swatch(7) . ';', $css);
    }

    /**
     * Spec: Cases/storefront-colours.md [82]
     *   "An Astra owner who points Accent at another swatch sees the accent
     *    and the button follow it, instead of staying on swatch 0."
     */
    public function test_an_accent_moved_to_another_swatch_is_followed(): void
    {
        $this->astra(['theme-color' => 'var(--ast-global-color-2)']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(2), $roles['accent']);
        $this->assertSame($this->swatch(2), $roles['button_bg'], 'the button follows the accent, as Astra paints it');
        $this->assertSame(ColorMath::readableOn('#1e293b'), $roles['button_text']);
    }

    public function test_an_accent_set_to_a_custom_hex_is_worn(): void
    {
        $this->astra(['theme-color' => '#7C3AED']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#7c3aed', $roles['accent']);
        $this->assertSame('#7c3aed', $roles['button_bg']);
    }

    /**
     * Spec: Cases/storefront-colours.md [83]
     *   "Button colours the Astra owner set are worn as given; a background set
     *    without its text gets a readable partner."
     */
    public function test_custom_button_colours_are_worn_as_given(): void
    {
        $this->astra(['button-bg-color' => '#FF5500', 'button-color' => '#000000']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ff5500', $roles['button_bg']);
        $this->assertSame('#000000', $roles['button_text']);
        $this->assertSame($this->swatch(0), $roles['accent'], 'the accent keeps its own setting');

        $colors = FrontendTheme::getEffectiveColors();
        $this->assertSame('#ff5500', $colors['btn_bg_color']);
        $this->assertSame('#000000', $colors['btn_text_color']);
    }

    public function test_a_button_background_alone_gets_a_readable_partner(): void
    {
        $this->astra(['button-bg-color' => 'var(--ast-global-color-7)']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(7), $roles['button_bg']);
        $this->assertSame(ColorMath::readableOn('#d1d5db'), $roles['button_text'], 'light swatch, dark text');
    }

    /**
     * Spec: Cases/storefront-colours.md [84]
     *   "The Astra owner's button hover pair is followed."
     */
    public function test_the_owners_hover_pair_is_followed(): void
    {
        $this->astra(['button-bg-h-color' => '#222222', 'button-h-color' => '#FEFEFE']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);
    }

    public function test_a_hover_background_alone_gets_a_readable_partner(): void
    {
        $this->astra(['button-bg-h-color' => 'var(--ast-global-color-5)']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(5), $roles['button_hover_bg']);
        $this->assertSame(ColorMath::readableOn('#f0f5fa'), $roles['button_hover_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [85]
     *   "A content background the Astra owner set is the surface, and the
     *    derived tones mix from it; with none, the site background is used."
     */
    public function test_a_custom_content_background_drives_the_surface_and_tones(): void
    {
        $this->astra([
            'content-bg-obj-responsive' => ['desktop' => ['background-color' => '#101418']],
            'text-color'                => '#E5E7EB',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface']);
        $this->assertSame('#e5e7eb', $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt'], 'derived tones mix from the owner\'s colours');
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 68), $roles['text_muted']);
    }

    /**
     * Spec: Cases/storefront-colours.md [107]
     *   "A background the theme's settings state without a text colour takes
     *    the palette's text when that reads on it; only an unreadable one is
     *    replaced by a measured partner."
     */
    public function test_a_stated_background_without_text_keeps_the_palettes_text(): void
    {
        // Found in the browser on a real Astra install: Body Text saved empty.
        // The reader stated the surface alone, and its partner was invented
        // (#2F3448) where the palette's own text (swatch 3) was right there.
        $this->astra(['text-color' => '']);
        $this->inherit();

        $this->assertSame($this->swatch(3), ThemePalette::resolve()['text'], 'the palette text reads on the white surface, so it is kept');
    }

    public function test_a_dark_stated_background_without_text_gets_a_readable_partner(): void
    {
        // The pair law still holds: the palette's dark text is not printed
        // onto a dark background the owner stated.
        $this->astra([
            'text-color'                => '',
            'content-bg-obj-responsive' => ['desktop' => ['background-color' => '#101418']],
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface']);
        $this->assertSame(ColorMath::readableOn('#101418', '#F3F4F6', '#2F3448'), $roles['text']);
        $this->assertGreaterThanOrEqual(4.5, ColorMath::contrast('#101418', $roles['text']));
    }

    public function test_an_unset_content_background_falls_to_the_site_background(): void
    {
        $this->astra([
            'content-bg-obj-responsive' => ['desktop' => ['background-color' => '']],
        ]);
        $this->inherit();

        $this->assertSame($this->swatch(5), ThemePalette::resolve()['surface']);
    }

    /**
     * Spec: Cases/storefront-colours.md [86]
     *   "An Astra outline button preset states no filled button, so the button
     *    roles fall back as they did before."
     */
    public function test_an_outline_preset_reports_no_filled_button(): void
    {
        $this->astra([
            'button-preset-style' => 'button_05',
            'theme-color'         => 'var(--ast-global-color-2)',
            'button-bg-color'     => '#ff5500',
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ' is not reported for an outline preset');
        }

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(2), $roles['accent'], 'the accent is still read');
        $this->assertSame($this->swatch(0), $roles['button_bg'], 'the button keeps the palette-slot fallback');
    }

    /**
     * Spec: Cases/storefront-colours.md [87]
     *   "The site editor's button outranks the Astra settings."
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

        $this->astra([
            'button-bg-color'   => '#ff5500',
            'button-color'      => '#000000',
            'button-bg-h-color' => '#222222',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'Astra\'s hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [88]
     *   "With no Astra settings to read — Astra absent, or the reader turned
     *    off — inheritance behaves exactly as before; FluentCart's own and
     *    customised colours are untouched by the reader."
     */
    public function test_a_disabled_reader_leaves_the_palette_slot_behaviour(): void
    {
        // What the slot guess produces from the same palette with no settings.
        $this->astra();
        \AstraOptionsStub::$options = ['global-color-palette' => ['palette' => self::PALETTE]];
        $this->inherit();
        $this->assertSame([], ThemePalette::settingsRoles(), 'no stated settings, nothing read');
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();

        $this->assertSame($this->swatch(0), $before['button_bg']);
        $this->assertSame(ColorMath::mix('#334155', '#ffffff', 18), $before['border'], 'the border is derived as it always was');

        // The full Astra settings, but the reader filtered off.
        $this->astra(['theme-color' => 'var(--ast-global-color-2)', 'button-bg-color' => '#ff5500']);
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

            \AstraOptionsStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->astra(['theme-color' => 'var(--ast-global-color-2)', 'button-bg-color' => '#ff5500']);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with Astra settings present');
        }
    }

    public function test_the_filter_sees_the_theme_and_its_output_is_sanitised(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;
            $roles['accent'] = 'red; background:url(x)';
            $roles['border'] = 'var(--ast-global-color-6)';
            $roles['not_a_role'] = '#123456';

            return $roles;
        }, 10, 2);

        $this->astra();
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('astra', $seen['theme'] ?? null);
        $this->assertArrayNotHasKey('accent', $stated, 'a value that is not a colour is dropped');
        $this->assertArrayNotHasKey('not_a_role', $stated);
        $this->assertSame($this->swatch(6), $stated['border'], 'a filtered reference gains its measurable fallback too');
    }

    public function test_a_value_that_is_neither_hex_nor_a_reference_is_not_stated(): void
    {
        $this->astra([
            'theme-color'     => 'rgba(255, 0, 0, 0.5)',
            'button-bg-color' => 'javascript:alert(1)',
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated);
        $this->assertArrayNotHasKey('button_bg', $stated);
        $this->assertArrayNotHasKey('button_text', $stated, 'no partner for a background that was not stated');
        $this->assertSame($this->swatch(3), $stated['text'], 'the rest is still read');
    }

    /**
     * Astra's Border Radius control (`button-radius-fields`) as it saves it.
     */
    protected function radiusFields($top, $right, $bottom, $left, string $unit = 'px'): array
    {
        $empty = ['top' => '', 'right' => '', 'bottom' => '', 'left' => ''];

        return [
            'desktop'      => ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left],
            'tablet'       => $empty,
            'mobile'       => ['top' => 2, 'right' => 2, 'bottom' => 2, 'left' => 2],
            'desktop-unit' => $unit,
            'tablet-unit'  => 'px',
            'mobile-unit'  => 'px',
        ];
    }

    /**
     * Spec: Cases/storefront-colours.md [206]
     *   "Astra's button radius is read from its desktop corners and unit; an
     *    empty radius states nothing."
     */
    public function test_astras_button_radius_is_read_from_its_desktop_corners(): void
    {
        $this->astra(['button-radius-fields' => $this->radiusFields(30, 30, 30, 30)]);

        $this->assertSame(['btn' => '30px'], AstraSettingsReader::radii(), 'desktop speaks for all; Astra has no input or card radius');
        $this->assertSame(['btn' => '30px'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [206]
     */
    public function test_an_empty_astra_button_radius_states_nothing(): void
    {
        $this->astra(['button-radius-fields' => $this->radiusFields('', '', '', '')]);
        $this->assertSame([], AstraSettingsReader::radii(), 'Astra prints no radius, so none is stated');

        $this->astra();
        $this->assertSame([], AstraSettingsReader::radii(), 'never saved: Astra\'s default is empty');
    }

    /**
     * Spec: Cases/storefront-colours.md [207]
     *   "Astra corners that differ, or a % unit, state no button radius; an
     *    em unit is kept."
     */
    public function test_astra_radius_units_and_uneven_corners(): void
    {
        $this->astra(['button-radius-fields' => $this->radiusFields('1.5', '1.5', '1.5', '1.5', 'em')]);
        $this->assertSame(['btn' => '1.5em'], AstraSettingsReader::radii());

        $this->astra(['button-radius-fields' => $this->radiusFields(50, 50, 50, 50, '%')]);
        $this->assertSame([], ThemePalette::radii(), 'a percentage is outside the grammar');

        $this->astra(['button-radius-fields' => $this->radiusFields(10, 0, 10, 0)]);
        $this->assertSame([], AstraSettingsReader::radii(), 'corners that differ are not one length');

        $this->astra(['button-radius-fields' => $this->radiusFields(0, 0, 0, 0)]);
        $this->assertSame(['btn' => '0px'], ThemePalette::radii(), 'square is a statement');
    }

    /**
     * Spec: Cases/storefront-colours.md [204]
     *   "The site editor's radius outranks the theme's own setting, role by role."
     */
    public function test_the_site_editor_radius_outranks_astras(): void
    {
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 3,
                'styles'  => ['elements' => [
                    'button'    => ['border' => ['radius' => '4px']],
                    'textInput' => ['border' => ['radius' => '2px']],
                ]],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        $this->astra(['button-radius-fields' => $this->radiusFields(30, 30, 30, 30)]);

        $this->assertSame(['btn' => '4px', 'input' => '2px'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [205]
     *   "The filter sees the theme."
     */
    public function test_the_radius_filter_sees_the_reader(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/radius_roles', function ($roles, $context) use (&$seen) {
            $seen = [$roles, $context];

            return $roles;
        }, 10, 2);

        $this->astra(['button-radius-fields' => $this->radiusFields(8, 8, 8, 8)]);
        ThemePalette::radii();

        $this->assertSame(['btn' => '8px'], $seen[0]);
        $this->assertSame('astra', $seen[1]['reader']);
    }
}
