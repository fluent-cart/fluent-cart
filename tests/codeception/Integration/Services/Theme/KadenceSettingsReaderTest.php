<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\KadenceSettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours a Kadence owner set, not the slots Kadence ships them in.
 *
 * Inheritance read Kadence's palette by position — palette1 the accent and the
 * button, palette4 the text, palette9 the surface. Kadence's Customizer keeps
 * the semantic colours as their own theme mods (Links, Base Font, Content and
 * Site Background, Buttons), each holding a palette slug (`palette3`) or a
 * custom colour, and the button is set apart from the link colour. Once the
 * owner moved one, FluentCart kept wearing the default slot.
 *
 * The reader reads those mods through `\Kadence\kadence()->sub_option()` and
 * turns a slug into the property Kadence prints it as
 * (`var(--global-paletteN)`), measured through `palette_option()` — the active
 * palette of three. Kadence is not loaded in the test install; `kadence()` is
 * stubbed (tests/Support/Stubs/KadenceStubs.php) and driven per test.
 */
class KadenceSettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    /** Kadence 1.5.0's default palette (palette_defaults()), palette1..palette9. */
    const PALETTE = ['#2B6CB0', '#215387', '#1A202C', '#2D3748', '#4A5568', '#718096', '#EDF2F7', '#F7FAFC', '#ffffff'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/KadenceStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \KadenceOptionsStub::reset();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \KadenceOptionsStub::reset();
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
     * A Kadence install with these theme mods saved. With none, every mod
     * takes Kadence's own default — exactly an untouched install.
     */
    protected function kadence(array $mods = []): void
    {
        \KadenceOptionsStub::$mods = $mods;
        $this->reset();
    }

    /** A responsive background as Kadence's Customizer saves it. */
    protected function background(string $desktop, array $extra = []): array
    {
        return [
            'desktop' => array_merge(['color' => $desktop], $extra),
            'tablet'  => ['color' => '#000000'],
            'mobile'  => ['color' => '#000000'],
        ];
    }

    /** The editor palette Kadence publishes: bare references, no hex. */
    protected function inherit(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (range(1, 15) as $slot) {
                $palette[] = [
                    'slug'  => 'theme-palette' . $slot,
                    'name'  => 'Palette ' . $slot,
                    'color' => 'var(--global-palette' . $slot . ')',
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

    /** Palette slot N (1-based, as Kadence numbers them), live with its fallback. */
    protected function swatch(int $slot): string
    {
        return 'var(--global-palette' . $slot . ', ' . strtolower(self::PALETTE[$slot - 1]) . ')';
    }

    /**
     * Spec: Cases/storefront-colours.md [98]
     *   "Inheriting from Kadence, every role follows the Customizer colour that
     *    drives it — accent from Links, text from Base Font, the surface from
     *    Content Background, the button and its hover from Buttons — each
     *    palette colour a live reference with a measurable fallback, Kadence's
     *    stated button text worn as given, and the border still derived."
     */
    public function test_kadences_defaults_are_read_from_its_theme_mods(): void
    {
        $this->kadence();
        $this->inherit();

        $stated = ThemePalette::settingsRoles();
        $this->assertArrayNotHasKey('border', $stated, 'Kadence has no border setting');

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(1), $roles['accent'], 'Links highlight');
        $this->assertSame($this->swatch(4), $roles['text'], 'Base Font colour');
        $this->assertSame($this->swatch(9), $roles['surface'], 'Content Background');
        $this->assertSame($this->swatch(1), $roles['button_bg'], 'buttons_background color');
        $this->assertSame($this->swatch(9), $roles['button_text'], 'Kadence states its button text');
        $this->assertSame($this->swatch(2), $roles['button_hover_bg'], 'buttons_background hover, not a darkened button');
        $this->assertSame($this->swatch(9), $roles['button_hover_text'], 'buttons_color hover');
        $this->assertSame(ColorMath::mix('#2d3748', '#ffffff', 18), $roles['border'], 'the border is derived as before');

        $css = FrontendTheme::buildCss();
        $this->assertStringContainsString('--fct-btn-bg-color:' . $this->swatch(1) . ';', $css, 'the button is owned: its colour measures');
    }

    /**
     * Spec: Cases/storefront-colours.md [99]
     *   "A Kadence owner who points Links at another palette colour sees the
     *    accent follow it while the button keeps its own Buttons colour — and
     *    the other way round."
     */
    public function test_links_moved_to_another_slot_moves_the_accent_not_the_button(): void
    {
        $this->kadence(['link_color' => ['highlight' => 'palette3', 'highlight-alt' => 'palette2']]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(3), $roles['accent']);
        $this->assertSame($this->swatch(1), $roles['button_bg'], 'the button has its own setting in Kadence');
    }

    public function test_buttons_moved_to_another_slot_moves_the_button_not_the_accent(): void
    {
        $this->kadence(['buttons_background' => ['color' => 'palette3', 'hover' => 'palette4']]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(1), $roles['accent'], 'Links keeps its own setting');
        $this->assertSame($this->swatch(3), $roles['button_bg']);
        $this->assertSame($this->swatch(4), $roles['button_hover_bg']);
    }

    /**
     * Spec: Cases/storefront-colours.md [100]
     *   "Button colours the Kadence owner set as custom colours are worn as
     *    given; a button text FluentCart cannot write gets a readable partner,
     *    and one the owner cleared takes Kadence's default."
     */
    public function test_custom_button_colours_are_worn_as_given(): void
    {
        $this->kadence([
            'buttons_background' => ['color' => '#FF5500', 'hover' => 'palette2'],
            'buttons_color'      => ['color' => '#000000', 'hover' => 'palette9'],
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
        $this->kadence([
            'buttons_background' => ['color' => 'palette7', 'hover' => 'palette2'],
            'buttons_color'      => ['color' => 'rgba(0, 0, 0, 0.8)', 'hover' => 'rgba(0, 0, 0, 0.8)'],
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(7), $roles['button_bg']);
        $this->assertSame(ColorMath::readableOn('#edf2f7'), $roles['button_text'], 'light swatch, dark text');
        $this->assertSame(ColorMath::readableOn('#215387'), $roles['button_hover_text'], 'measured on the hover background');
    }

    public function test_a_cleared_button_text_takes_kadences_default(): void
    {
        // Saved with the text cleared: sub_option() answers null, and Kadence's
        // default for the setting is what speaks for it.
        $this->kadence([
            'buttons_background' => ['color' => '#FF5500', 'hover' => '#222222'],
            'buttons_color'      => ['color' => '', 'hover' => ''],
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame($this->swatch(9), $stated['button_text']);
        $this->assertSame($this->swatch(9), $stated['button_hover_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [101]
     *   "The Kadence owner's button hover pair is followed."
     */
    public function test_the_owners_hover_pair_is_followed(): void
    {
        $this->kadence([
            'buttons_background' => ['color' => 'palette1', 'hover' => '#222222'],
            'buttons_color'      => ['color' => 'palette9', 'hover' => '#FEFEFE'],
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame($this->swatch(1), $roles['button_bg']);
        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [102]
     *   "A content background the Kadence owner set is the surface — desktop
     *    speaking for every device — and the derived tones mix from it; with
     *    none, the site background is used; a gradient content background
     *    states no surface colour."
     */
    public function test_a_custom_content_background_drives_the_surface_and_tones(): void
    {
        $this->kadence([
            'content_background' => $this->background('#101418'),
            'base_font'          => ['color' => '#E5E7EB'],
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface'], 'desktop, not the tablet/mobile values');
        $this->assertSame('#e5e7eb', $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt'], 'derived tones mix from the owner\'s colours');
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 68), $roles['text_muted']);
    }

    public function test_an_empty_content_background_falls_back_to_the_site_background(): void
    {
        $this->kadence([
            'content_background' => ['desktop' => ['color' => '']],
            'site_background'    => $this->background('palette7'),
        ]);
        $this->inherit();

        $this->assertSame($this->swatch(7), ThemePalette::settingsRoles()['surface']);
    }

    public function test_a_gradient_content_background_states_no_surface(): void
    {
        $this->kadence([
            'content_background' => $this->background('', ['type' => 'gradient', 'gradient' => 'linear-gradient(135deg,#101418 0%,#303438 100%)']),
        ]);
        $this->inherit();

        $this->assertArrayNotHasKey('surface', ThemePalette::settingsRoles(), 'Kadence paints a gradient, not a colour');
        $this->assertSame($this->swatch(9), ThemePalette::resolve()['surface'], 'the palette-slot guess stays');
    }

    /**
     * Spec: Cases/storefront-colours.md [103]
     *   "A Kadence colour FluentCart cannot write — a gradient button, an
     *    rgba() link, the oklch() complement palette10 — is not stated, and
     *    the role falls back as before."
     */
    public function test_a_gradient_button_states_no_button(): void
    {
        $this->kadence([
            'buttons_background' => [
                'color' => 'linear-gradient(135deg,#ff5500 0%,#222222 100%)',
                'hover' => 'linear-gradient(135deg,#222222 0%,#ff5500 100%)',
            ],
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ': no pair for a background that was not stated');
        }

        $this->assertSame($this->swatch(1), $stated['accent'], 'the rest is still read');
        $this->assertSame($this->swatch(1), ThemePalette::resolve()['button_bg'], 'the button keeps the palette-slot fallback');
    }

    public function test_rgba_and_oklch_colours_are_not_stated(): void
    {
        $this->kadence([
            'link_color' => ['highlight' => 'rgba(43, 108, 176, 0.9)'],
            'base_font'  => ['color' => 'palette10'],
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated, 'rgba() cannot be written');
        $this->assertArrayNotHasKey('text', $stated, 'palette10\'s oklch() complement cannot be measured');
        $this->assertSame($this->swatch(1), $stated['button_bg'], 'the rest is still read');
    }

    /**
     * Spec: Cases/storefront-colours.md [104]
     *   "A Kadence palette colour is measured from the palette Kadence has
     *    active — the second or third palette as well as the first."
     */
    public function test_the_active_palette_is_honoured(): void
    {
        $first = \KadenceOptionsStub::defaultSwatches();
        $second = $first;
        $second[0]['color'] = '#7C3AED';
        $second[8]['color'] = '#0B0B10';

        \KadenceOptionsStub::$palette = [
            'palette'        => $first,
            'second-palette' => $second,
            'third-palette'  => $first,
            'active'         => 'second-palette',
        ];

        $this->kadence();
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('var(--global-palette1, #7c3aed)', $stated['accent']);
        $this->assertSame('var(--global-palette9, #0b0b10)', $stated['surface']);
    }

    /**
     * Spec: Cases/storefront-colours.md [105]
     *   "The site editor's button outranks the Kadence settings, and Kadence's
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

        $this->kadence([
            'buttons_background' => ['color' => '#ff5500', 'hover' => '#222222'],
            'buttons_color'      => ['color' => '#000000', 'hover' => '#fefefe'],
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'Kadence\'s hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [106]
     *   "With no Kadence settings to read — Kadence absent, or the reader
     *    filtered off — inheritance behaves exactly as before; FluentCart's own
     *    and customised colours are untouched by the reader."
     */
    public function test_a_disabled_reader_leaves_the_palette_slot_behaviour(): void
    {
        // The palette-slot result with Kadence's palette measurable and its
        // settings turned off, before any custom setting exists.
        $this->kadence();
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles());
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();

        $this->assertSame($this->swatch(1), $before['button_bg'], 'slot guess');
        $this->assertSame($this->swatch(9), $before['surface'], 'slot guess');
        $derivedHover = ColorMath::isDark('#2b6cb0') ? ColorMath::lighten('#2b6cb0', 12) : ColorMath::darken('#2b6cb0', 12);
        $this->assertSame($derivedHover, $before['button_hover_bg'], 'the hover is derived as it always was');

        // Custom Kadence settings, still filtered off: nothing moves.
        $this->kadence([
            'link_color'         => ['highlight' => 'palette3'],
            'buttons_background' => ['color' => '#ff5500', 'hover' => '#222222'],
            'content_background' => $this->background('#101418'),
        ]);

        $this->assertSame($before, ThemePalette::resolve());
        $this->assertSame($beforeCss, FrontendTheme::buildCss());
    }

    public function test_an_absent_kadence_states_nothing(): void
    {
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles(), 'no Kadence, nothing read');
        $this->assertSame('var(--global-palette1)', ThemePalette::resolve()['accent'], 'the bare reference, as before');
    }

    public function test_default_and_customize_are_untouched_by_the_reader(): void
    {
        $sources = [
            ColorPalette::SOURCE_DEFAULT => [],
            ColorPalette::SOURCE_CUSTOM  => ['btn_bg_color' => '#809400', 'primary_text_color' => '#222222'],
        ];

        foreach ($sources as $source => $colors) {
            update_option(self::OPTION, ['appearance_source' => $source, 'appearance_colors' => $colors]);

            \KadenceOptionsStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->kadence(['link_color' => ['highlight' => 'palette3'], 'buttons_background' => ['color' => '#ff5500']]);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with Kadence settings present');
        }
    }

    public function test_the_filter_sees_the_theme(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->kadence();
        $this->inherit();

        ThemePalette::settingsRoles();

        $this->assertSame('kadence', $seen['theme'] ?? null);
    }

    /**
     * Only one theme runs on a site, but the test process keeps every vendor
     * stub another test class loaded. Astra's stub answers for
     * `astra_get_option()` forever once loaded, so Kadence has to be asked
     * before Astra to get its turn.
     */
    public function test_kadence_is_read_with_other_vendor_stubs_loaded(): void
    {
        require_once dirname(__DIR__, 3) . '/Support/Stubs/AstraStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BlocksyStubs.php';

        \AstraOptionsStub::reset();
        \BlocksyThemeModsStub::reset();

        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->kadence(['link_color' => ['highlight' => 'palette3']]);
        $this->inherit();

        $this->assertSame($this->swatch(3), ThemePalette::settingsRoles()['accent']);
        $this->assertSame('kadence', $seen['theme'] ?? null);
    }

    /** Kadence's Buttons → Border Radius range, as it saves it. */
    protected function range($desktop, string $unit = 'px'): array
    {
        return [
            'size' => ['mobile' => 2, 'tablet' => '', 'desktop' => $desktop],
            'unit' => ['mobile' => 'px', 'tablet' => 'px', 'desktop' => $unit],
        ];
    }

    /**
     * Spec: Cases/storefront-colours.md [210]
     *   "Kadence's button radius is its desktop size and unit; empty is its
     *    stylesheet's 3px; a % states nothing."
     */
    public function test_kadences_button_radius_is_read(): void
    {
        $this->kadence();
        $this->assertSame(['btn' => '3px'], KadenceSettingsReader::radii(), 'untouched: the stylesheet\'s 3px; Kadence has no input or card radius setting');

        $this->kadence(['buttons_border_radius' => $this->range(24)]);
        $this->assertSame(['btn' => '24px'], ThemePalette::radii());

        $this->kadence(['buttons_border_radius' => $this->range(0.75, 'rem')]);
        $this->assertSame(['btn' => '0.75rem'], KadenceSettingsReader::radii(), 'desktop speaks for all');

        $this->kadence(['buttons_border_radius' => $this->range(50, '%')]);
        $this->assertSame([], ThemePalette::radii(), 'a percentage is outside the grammar');
    }
}
