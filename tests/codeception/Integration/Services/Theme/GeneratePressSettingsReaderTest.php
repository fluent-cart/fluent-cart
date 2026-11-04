<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\GeneratePressSettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours a GeneratePress owner set.
 *
 * GeneratePress publishes its Global Colors to the editor palette as bare
 * references (`var(--accent)`), so inheriting could name them but never
 * measure them: no derived tones, no button owned, and the slot guesses
 * picked `base` (#f0f0f0) as the surface where GeneratePress paints its
 * content area `base-3` (#ffffff). Its Customizer keeps the semantic colours
 * in the `generate_settings` option row — Body background and text, Links,
 * the Content area's background/text/link, and the Buttons pair with its
 * hover — each a `var(--slug)` global colour or a custom colour.
 *
 * The reader reads that row over GeneratePress's own defaults, exactly as its
 * dynamic CSS is printed (inc/css-output.php), and measures `var(--slug)`
 * through the Global Colors GeneratePress prints on :root. GeneratePress is
 * not loaded in the test install; its option API is stubbed
 * (tests/Support/Stubs/GeneratePressStubs.php) and driven per test, the
 * option supplied through a `pre_option_` filter, never written.
 */
class GeneratePressSettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/GeneratePressStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \GeneratePressStub::reset();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \GeneratePressStub::reset();
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
     * A GeneratePress install with this `generate_settings` row saved. With
     * none, every setting takes GeneratePress's own default — exactly an
     * untouched install.
     */
    protected function generatepress(array $settings = []): void
    {
        \GeneratePressStub::reset();
        \GeneratePressStub::arm($settings);
        $this->reset();
    }

    /** The editor palette GeneratePress publishes: bare references, no hex. */
    protected function inherit(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            $palette = [];

            foreach (\GeneratePressStub::defaultGlobalColors() as $color) {
                $palette[] = [
                    'slug'  => $color['slug'],
                    'name'  => $color['name'],
                    'color' => 'var(--' . $color['slug'] . ')',
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

    /** The Global Colors, with the given slugs recoloured. */
    protected function globalColors(array $overrides): array
    {
        $colors = \GeneratePressStub::defaultGlobalColors();

        foreach ($colors as $index => $color) {
            if (isset($overrides[$color['slug']])) {
                $colors[$index]['color'] = $overrides[$color['slug']];
            }
        }

        return $colors;
    }

    protected function bodyClasses(): array
    {
        $this->reset();

        return apply_filters('body_class', ['existing-theme-class'], []);
    }

    /**
     * Spec: Cases/storefront-colours.md [134]
     *   "Inheriting from GeneratePress, the accent follows the Link colour, the
     *    text the Body text colour and the surface the Content background —
     *    each Global Color a live reference with its colour as the measurable
     *    fallback — and the button and its hover follow the Buttons colours.
     *    The border is still derived."
     */
    public function test_generatepress_defaults_are_read_from_its_settings(): void
    {
        $this->generatepress();
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('var(--accent, #1e73be)', $stated['accent'], 'link_color default');
        $this->assertSame('var(--contrast, #222222)', $stated['text'], 'text_color default');
        $this->assertSame('var(--base-3, #ffffff)', $stated['surface'], 'content_background_color default, not the body\'s base-2');
        $this->assertSame('#55555e', $stated['button_bg']);
        $this->assertSame('#ffffff', $stated['button_text']);
        $this->assertSame('#3f4047', $stated['button_hover_bg']);
        $this->assertSame('#ffffff', $stated['button_hover_text']);
        $this->assertArrayNotHasKey('border', $stated, 'GeneratePress has no general border setting');

        $roles = ThemePalette::resolve();

        $this->assertSame(ColorMath::mix('#222222', '#ffffff', 4), $roles['surface_alt'], 'the derived tones mix from GeneratePress\'s pair');
        $this->assertSame(ColorMath::mix('#222222', '#ffffff', 18), $roles['border']);
        $this->assertSame('#55555e', $roles['button_bg'], 'GeneratePress\'s grey button, not its accent');
        $this->assertSame('#3f4047', $roles['button_hover_bg']);
    }

    /**
     * Spec: Cases/storefront-colours.md [135]
     *   "A GeneratePress owner who points the Link colour at another Global
     *    Color moves the accent there, not the button; recolouring a Global
     *    Color moves every role that points at it; the Content area's own link
     *    colour, when set, is the accent."
     */
    public function test_links_moved_to_another_global_colour_moves_the_accent_not_the_button(): void
    {
        $this->generatepress(['link_color' => 'var(--contrast-2)']);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--contrast-2, #575760)', $roles['accent']);
        $this->assertSame('#55555e', $roles['button_bg'], 'the button has its own setting');
    }

    public function test_a_recoloured_global_colour_is_measured_as_its_new_colour(): void
    {
        $this->generatepress(['global_colors' => $this->globalColors(['accent' => '#7C3AED', 'base-3' => '#FAF7F2'])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('var(--accent, #7c3aed)', $stated['accent']);
        $this->assertSame('var(--base-3, #faf7f2)', $stated['surface']);
        $this->assertSame(ColorMath::mix('#222222', '#faf7f2', 4), ThemePalette::resolve()['surface_alt']);
    }

    public function test_the_content_link_colour_outranks_the_link_colour(): void
    {
        $this->generatepress(['link_color' => 'var(--contrast-2)', 'content_link_color' => '#0F766E']);
        $this->inherit();

        $this->assertSame('#0f766e', ThemePalette::settingsRoles()['accent'], 'GeneratePress paints .inside-article a with it');
    }

    /**
     * Spec: Cases/storefront-colours.md [136]
     *   "Custom colours a GeneratePress owner picked instead of a Global Color
     *    are worn as the hex they are."
     */
    public function test_custom_hex_colours_are_worn_as_given(): void
    {
        $this->generatepress([
            'link_color'               => '#FF5500',
            'text_color'               => '#333333',
            'content_background_color' => '#FFFFFF',
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#ff5500', $stated['accent']);
        $this->assertSame('#333333', $stated['text']);
        $this->assertSame('#ffffff', $stated['surface']);
    }

    /**
     * Spec: Cases/storefront-colours.md [137]
     *   "The GeneratePress owner's button and hover pair are worn — a Global
     *    Color kept live — and FluentCart's buttons compute to them. A button
     *    background or text saved empty is what GeneratePress's stylesheet
     *    paints (#55555e / #ffffff); a hover saved empty states no hover, so
     *    FluentCart derives it, and a hover text saved empty keeps the resting
     *    text while it reads."
     */
    public function test_the_owners_button_and_hover_pair_are_worn(): void
    {
        $this->generatepress([
            'form_button_background_color'       => 'var(--accent)',
            'form_button_text_color'             => '#FFFFFF',
            'form_button_background_color_hover' => '#222222',
            'form_button_text_color_hover'       => '#FEFEFE',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--accent, #1e73be)', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);

        $colors = FrontendTheme::getEffectiveColors();
        $this->assertSame('var(--accent, #1e73be)', $colors['btn_bg_color']);
        $this->assertSame('#ffffff', $colors['btn_text_color']);
    }

    public function test_an_empty_button_takes_the_stylesheets_colours(): void
    {
        $this->generatepress(['form_button_background_color' => '', 'form_button_text_color' => '']);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#55555e', $stated['button_bg'], 'main.css: button{background:#55555e}');
        $this->assertSame('#ffffff', $stated['button_text'], 'main.css: button{color:#fff}');
    }

    public function test_an_empty_hover_states_no_hover(): void
    {
        $this->generatepress([
            'form_button_background_color'       => '#FF5500',
            'form_button_text_color'             => '#000000',
            'form_button_background_color_hover' => '',
            'form_button_text_color_hover'       => '',
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('button_hover_bg', $stated, 'GeneratePress prints no hover rule');
        $this->assertArrayNotHasKey('button_hover_text', $stated);
        $this->assertSame(ColorMath::shiftFromItself('#ff5500', 12), ThemePalette::resolve()['button_hover_bg'], 'derived from the button as before');
    }

    public function test_a_hover_background_alone_keeps_the_resting_text(): void
    {
        $this->generatepress([
            'form_button_background_color'       => '#FF5500',
            'form_button_text_color'             => '#FFFFFF',
            'form_button_background_color_hover' => '#222222',
            'form_button_text_color_hover'       => '',
        ]);
        $this->inherit();

        $this->assertArrayNotHasKey('button_hover_text', ThemePalette::settingsRoles());

        $roles = ThemePalette::resolve();
        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#ffffff', $roles['button_hover_text'], 'the resting text carries over while it reads');
    }

    /**
     * Spec: Cases/storefront-colours.md [138]
     *   "The Content background a GeneratePress owner set is the surface, with
     *    the Content text colour (else the Body text) as its text, and the
     *    derived tones mix from that pair. A Content background saved empty
     *    lets the Body background through, and that is the surface."
     */
    public function test_the_content_background_drives_the_surface_and_tones(): void
    {
        $this->generatepress([
            'content_background_color' => '#101418',
            'text_color'               => '#E5E7EB',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface']);
        $this->assertSame('#e5e7eb', $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt']);
        $this->assertSame(ColorMath::readableMix('#e5e7eb', '#101418', 68), $roles['text_muted']);
    }

    public function test_the_content_text_colour_outranks_the_body_text(): void
    {
        $this->generatepress(['text_color' => '#333333', 'content_text_color' => 'var(--contrast-2)']);
        $this->inherit();

        $this->assertSame('var(--contrast-2, #575760)', ThemePalette::settingsRoles()['text'], 'GeneratePress paints .inside-article with it');
    }

    public function test_an_empty_content_background_lets_the_body_background_through(): void
    {
        $this->generatepress(['content_background_color' => '']);
        $this->inherit();

        $this->assertSame('var(--base-2, #f7f8f9)', ThemePalette::settingsRoles()['surface'], 'background_color default');
    }

    /**
     * Spec: Cases/storefront-colours.md [139]
     *   "GeneratePress's editor palette — bare `var(--slug)` references — is
     *    measured from the Global Colors GeneratePress prints, so even the
     *    palette-slot path (the reader filtered off) derives tones and owns a
     *    readable button; without GeneratePress running the references stay
     *    bare, as before."
     */
    public function test_the_bare_palette_becomes_measurable(): void
    {
        $this->generatepress(['global_colors' => $this->globalColors(['accent' => '#7c3aed'])]);
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->inherit();

        $this->assertSame('#7c3aed', ThemePalette::color('accent'));
        $this->assertSame('var(--accent, #7c3aed)', ThemePalette::value('accent'));
        $this->assertSame('var(--base, #f0f0f0)', ThemePalette::value('base'));

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--accent, #7c3aed)', $roles['button_bg'], 'the slot guess, now measurable');
        $this->assertSame(ColorMath::readableText('#7c3aed'), $roles['button_text']);
        $this->assertSame(ColorMath::mix('#222222', '#f0f0f0', 18), $roles['border'], 'derived tones mix');
    }

    public function test_without_generatepress_the_palette_stays_bare(): void
    {
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles(), 'no GeneratePress, nothing read');
        $this->assertSame('var(--accent)', ThemePalette::value('accent'));

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--accent)', $roles['accent'], 'the bare reference, as before');
        $this->assertSame('', $roles['button_bg'], 'an unmeasurable button is not owned');
        $this->assertSame('', $roles['border']);
    }

    /**
     * Spec: Cases/storefront-colours.md [140]
     *   "A GeneratePress colour FluentCart cannot write — rgba(), a named
     *    colour, a reference to a Global Color that does not exist — is not
     *    stated, and a set-but-unwritable Content background or button is not
     *    replaced by the next setting or the stylesheet default."
     */
    public function test_unwritable_colours_are_not_stated(): void
    {
        $this->generatepress([
            'link_color'                         => 'rgba(30,115,190,0.8)',
            'text_color'                         => 'var(--no-such-colour)',
            'content_background_color'           => 'rgba(255,255,255,0.6)',
            'background_color'                   => '#101418',
            'form_button_background_color'       => 'red',
            'form_button_background_color_hover' => '#222222',
        ]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated);
        $this->assertArrayNotHasKey('text', $stated);
        $this->assertArrayNotHasKey('surface', $stated, 'a translucent content area is not the body background');

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ': GeneratePress paints a button FluentCart cannot write');
        }
    }

    public function test_an_unwritable_button_text_gets_a_readable_partner(): void
    {
        $this->generatepress(['form_button_background_color' => '#FF5500', 'form_button_text_color' => 'rgba(0,0,0,0.5)']);
        $this->inherit();

        $this->assertSame(ColorMath::readableText('#ff5500'), ThemePalette::settingsRoles()['button_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [141]
     *   "The site editor's button outranks the GeneratePress settings, and
     *    GeneratePress's hover is not paired onto it."
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

        $this->generatepress([
            'form_button_background_color'       => '#ff5500',
            'form_button_text_color'             => '#000000',
            'form_button_background_color_hover' => '#222222',
            'form_button_text_color_hover'       => '#fefefe',
        ]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'GeneratePress\'s hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [142]
     *   "With the GeneratePress reader filtered off, inheriting behaves exactly
     *    as the palette alone makes it, whatever the settings say, and
     *    FluentCart's own and customised colours are untouched by the reader."
     */
    public function test_a_disabled_reader_leaves_the_palette_slot_behaviour(): void
    {
        $this->generatepress();
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles());
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();
        $beforeClasses = $this->bodyClasses();

        $this->assertSame('var(--base, #f0f0f0)', $before['surface'], 'slot guess');
        $this->assertSame('var(--accent, #1e73be)', $before['button_bg'], 'slot guess');

        // Custom GeneratePress settings, still filtered off: nothing moves.
        $this->generatepress([
            'link_color'                   => 'var(--contrast-2)',
            'content_background_color'     => '#101418',
            'form_button_background_color' => '#ff5500',
        ]);

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

            \GeneratePressStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->generatepress(['link_color' => '#7c3aed', 'form_button_background_color' => '#ff5500']);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with GeneratePress settings present');
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [143]
     *   "The GeneratePress reader applies only while GeneratePress runs — a
     *    loaded but idle GeneratePress does not claim — and GeneratePress gets
     *    its turn when other vendors' readers are loaded; the filter is told
     *    the theme is 'generatepress'."
     */
    public function test_an_idle_generatepress_does_not_apply(): void
    {
        $this->assertTrue(function_exists('generate_get_option') && defined('GENERATE_VERSION'), 'precondition: the API is loaded');
        $this->inherit();

        $this->assertFalse(GeneratePressSettingsReader::applies());
        $this->assertSame([], GeneratePressSettingsReader::roles());
        $this->assertSame([], GeneratePressSettingsReader::paletteValues());
    }

    public function test_the_filter_sees_the_theme(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->generatepress();
        $this->inherit();

        ThemePalette::settingsRoles();

        $this->assertSame('generatepress', $seen['theme'] ?? null);
    }

    /**
     * Only one theme runs on a site, but the test process keeps every vendor
     * stub another test class loaded. Astra's stub answers for
     * `astra_get_option()` forever once loaded, so GeneratePress has to be
     * asked before Astra — and an idle GeneratePress stub must not claim
     * ahead of Astra.
     */
    public function test_generatepress_is_read_with_other_vendor_stubs_loaded(): void
    {
        require_once dirname(__DIR__, 3) . '/Support/Stubs/AstraStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BlocksyStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/KadenceStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/DiviStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BricksStubs.php';

        \AstraOptionsStub::reset();
        \BlocksyThemeModsStub::reset();
        \KadenceOptionsStub::reset();
        \DiviOptionsStub::reset();
        \BricksStub::reset();

        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->generatepress(['link_color' => '#7c3aed']);
        $this->inherit();

        $this->assertSame('#7c3aed', ThemePalette::settingsRoles()['accent']);
        $this->assertSame('generatepress', $seen['theme'] ?? null);

        // GeneratePress gone: the idle stub stands aside and Astra's answers.
        \GeneratePressStub::reset();
        $this->reset();

        ThemePalette::settingsRoles();
        $this->assertSame('astra', $seen['theme'] ?? null);
    }

    /**
     * Spec: Cases/storefront-colours.md [213]
     *   "GeneratePress, which has no radius setting, states no radius."
     */
    public function test_generatepress_states_no_radius(): void
    {
        $this->generatepress();

        $this->assertFalse(
            is_subclass_of(GeneratePressSettingsReader::class, \FluentCart\App\Services\Theme\Readers\ThemeRadiusReader::class),
            'GeneratePress free keeps no button, field or card radius'
        );
        $this->assertSame([], ThemePalette::radii());
    }
}
