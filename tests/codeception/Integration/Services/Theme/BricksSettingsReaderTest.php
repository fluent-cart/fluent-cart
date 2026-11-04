<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\Readers\BricksSettingsReader;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Following the colours a Bricks owner set in a theme style.
 *
 * Bricks publishes neither its colour palette nor its theme styles to
 * theme.json, so inheriting from it read nothing at all: the store wrote no
 * colours and marked the body `fluent-cart-no-colors`. Bricks keeps its colours
 * in theme styles (`bricks_theme_styles`), several of them, each applied by its
 * own conditions: the one Bricks chose for the page lives in
 * `Theme_Styles::$settings_by_id`. A colour there is an object
 * `{hex, rgb, raw, id}` whose `id` can point into the palette
 * (`bricks_color_palette`), which Bricks prints as `--bricks-color-{id}`.
 *
 * Bricks is not loaded in the test install; `Bricks\Theme` and
 * `Bricks\Theme_Styles` are stubbed (tests/Support/Stubs/BricksStubs.php) and
 * both options are supplied through `pre_option_*` filters, never written.
 */
class BricksSettingsReaderTest extends WPTestCase
{
    const OPTION = 'fluent_cart_store_settings';

    /** A palette with a plain colour and one whose raw value names its own property. */
    const PALETTE = [
        [
            'id'     => 'pal1',
            'name'   => 'Default',
            'colors' => [
                ['id' => 'brand', 'hex' => '#7c3aed', 'name' => 'Brand'],
                ['id' => 'ink', 'hex' => '#111827', 'raw' => 'var(--ink)', 'name' => 'Ink'],
                ['id' => 'haze', 'hex' => '#7c3aed', 'rgb' => 'rgba(124, 58, 237, 0.4)', 'name' => 'Haze'],
            ],
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BricksStubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        \BricksStub::reset();
        $this->notYetChosen();
        $this->reset();
    }

    protected function tearDown(): void
    {
        \BricksStub::reset();
        $this->notYetChosen();
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
     * No front-end page has been set up in this request, so Bricks has not
     * chosen a style yet — the state of an admin or REST request, where the
     * settings preview is built.
     */
    protected function notYetChosen(): void
    {
        unset($GLOBALS['wp_actions']['wp']);
    }

    /** One theme style, applied to the entire website unless told otherwise. */
    protected function style(array $settings, array $conditions = [['main' => 'any']]): array
    {
        $settings['conditions'] = ['conditions' => $conditions];

        return ['label' => 'Style', 'settings' => $settings];
    }

    /** A running Bricks with these theme styles saved. */
    protected function bricks(array $styles = [], array $palette = self::PALETTE): void
    {
        \BricksStub::arm($styles, $palette);
        $this->reset();
    }

    protected function hex(string $hex): array
    {
        return ['hex' => $hex];
    }

    /** Bricks publishes no palette: inheriting has only its settings to go on. */
    protected function inherit(): void
    {
        add_filter('fluent_cart/theme/palette', '__return_empty_array');

        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);

        $this->reset();
    }

    protected function bodyClasses(): array
    {
        $this->reset();

        return apply_filters('body_class', ['existing-theme-class'], []);
    }

    /**
     * Spec: Cases/storefront-colours.md [123]
     *   "Inheriting from Bricks with no theme style — a fresh install, or a
     *    page no style's conditions apply to — states nothing, and inheriting
     *    stands aside exactly as before: nothing written, the body marked
     *    no-colors."
     */
    public function test_no_theme_style_states_nothing(): void
    {
        $this->bricks([]);
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles());
        $this->assertFalse(ThemePalette::hasUsableSource());
        $this->assertSame([], FrontendTheme::getThemeColors());
        $this->assertContains('fluent-cart-no-colors', $this->bodyClasses());
    }

    public function test_a_style_that_states_no_colour_states_nothing(): void
    {
        $this->bricks(['s1' => $this->style(['general' => ['siteLayout' => 'wide']])]);
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles(), 'a style is not a colour');
    }

    /**
     * Spec: Cases/storefront-colours.md [124]
     *   "A Bricks Primary color is the accent, and fills the button the way
     *    Bricks fills its default primary button; its text is the body text
     *    Bricks paints on it while that reads (4.5:1), otherwise a readable
     *    partner. Without a Primary color the Link colour is the accent. The
     *    content area is the white Bricks' stylesheet paints while the style
     *    sets no background."
     */
    public function test_the_primary_colour_is_the_accent_and_the_button(): void
    {
        $this->bricks(['s1' => $this->style(['colors' => ['colorPrimary' => $this->hex('#FFD64F')]])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#ffd64f', $stated['accent']);
        $this->assertSame('#ffd64f', $stated['button_bg'], '.bricks-background-primary');
        $this->assertSame(ColorMath::readableText('#ffd64f'), $stated['button_text']);
        $this->assertSame('#ffffff', $stated['surface'], 'body{background-color:#fff} in Bricks\' stylesheet');
        $this->assertArrayNotHasKey('text', $stated);
        $this->assertArrayNotHasKey('border', $stated, 'Bricks has no border colour setting');

        $roles = ThemePalette::resolve();
        $this->assertSame('#ffd64f', $roles['button_bg']);
    }

    public function test_an_unset_button_text_keeps_the_body_text_while_it_reads(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors'     => ['colorPrimary' => $this->hex('#ffd64f')],
            'typography' => ['typographyBody' => ['color' => $this->hex('#222222')]],
        ])]);
        $this->inherit();

        $this->assertSame('#222222', ThemePalette::settingsRoles()['button_text'], 'inherited from body');

        $this->bricks(['s1' => $this->style([
            'colors'     => ['colorPrimary' => $this->hex('#7c3aed')],
            'typography' => ['typographyBody' => ['color' => $this->hex('#222222')]],
        ])]);
        $this->reset();

        $this->assertLessThan(4.5, ColorMath::contrast('#7c3aed', '#222222'), 'precondition');
        $this->assertSame(ColorMath::readableText('#7c3aed'), ThemePalette::settingsRoles()['button_text']);
    }

    public function test_the_link_colour_is_the_accent_without_a_primary_colour(): void
    {
        $this->bricks(['s1' => $this->style(['links' => ['typography' => ['color' => $this->hex('#0f766e')]]])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#0f766e', $stated['accent']);
        $this->assertArrayNotHasKey('button_bg', $stated, 'links do not paint Bricks\' button');
    }

    /**
     * Spec: Cases/storefront-colours.md [125]
     *   "The body text and the site background a Bricks owner set are the text
     *    and surface, and the derived tones mix from them. On a boxed layout
     *    the content background is the surface; on a wide one Bricks does not
     *    paint it, so the site background is. A background image states no
     *    surface."
     */
    public function test_body_text_and_site_background_drive_the_pair_and_tones(): void
    {
        $this->bricks(['s1' => $this->style([
            'typography' => ['typographyBody' => ['color' => $this->hex('#E5E7EB'), 'font-size' => '16px']],
            'general'    => ['siteBackground' => ['color' => $this->hex('#101418')]],
        ])]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#101418', $roles['surface']);
        $this->assertSame('#e5e7eb', $roles['text']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 4), $roles['surface_alt']);
        $this->assertSame(ColorMath::mix('#e5e7eb', '#101418', 18), $roles['border']);
    }

    public function test_the_content_background_is_the_surface_only_on_a_boxed_layout(): void
    {
        $general = [
            'contentBackground' => ['color' => $this->hex('#fafafa')],
            'siteBackground'    => ['color' => $this->hex('#101418')],
        ];

        $this->bricks(['s1' => $this->style(['general' => $general + ['siteLayout' => 'boxed']])]);
        $this->inherit();
        $this->assertSame('#fafafa', ThemePalette::settingsRoles()['surface'], '.brx-boxed');

        $this->bricks(['s1' => $this->style(['general' => $general])]);
        $this->reset();
        $this->assertSame('#101418', ThemePalette::settingsRoles()['surface'], 'wide: .brx-boxed never exists');

        $this->bricks(['s1' => $this->style(['general' => ['siteLayout' => 'boxed', 'siteBackground' => ['color' => $this->hex('#101418')]]])]);
        $this->reset();
        $this->assertSame('#101418', ThemePalette::settingsRoles()['surface'], 'boxed with no content background shows the site background');
    }

    public function test_a_background_image_states_no_surface(): void
    {
        $this->bricks(['s1' => $this->style([
            'typography' => ['typographyBody' => ['color' => $this->hex('#e5e7eb')]],
            'general'    => ['siteBackground' => ['color' => $this->hex('#101418'), 'image' => ['url' => 'https://example.test/bg.jpg']]],
        ])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('surface', $stated, 'an image paints no colour, and white is not guessed under it');
        $this->assertSame('', ThemePalette::resolve()['surface'], 'nothing guessed under the image');
        $this->assertSame('#e5e7eb', ThemePalette::resolve()['text'], 'the stated body text is worn as given');
    }

    /**
     * Spec: Cases/storefront-colours.md [126]
     *   "A primary button background and text the Bricks owner set are worn
     *    as given, over the Primary color; a button text set for every button
     *    is worn when the primary one sets none."
     */
    public function test_the_primary_button_is_worn_as_given(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => $this->hex('#ffd64f')],
            'button' => [
                'primaryBackground' => $this->hex('#FF5500'),
                'primaryTypography' => ['color' => $this->hex('#000000')],
            ],
        ])]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ff5500', $roles['button_bg']);
        $this->assertSame('#000000', $roles['button_text']);
        $this->assertSame('#ffd64f', $roles['accent'], 'the accent keeps its own setting');

        $colors = FrontendTheme::getEffectiveColors();
        $this->assertSame('#ff5500', $colors['btn_bg_color']);
        $this->assertSame('#000000', $colors['btn_text_color']);
    }

    public function test_the_every_button_text_is_worn_when_the_primary_sets_none(): void
    {
        $this->bricks(['s1' => $this->style([
            'button' => [
                'primaryBackground' => $this->hex('#ff5500'),
                'typography'        => ['color' => $this->hex('#fefefe')],
            ],
        ])]);
        $this->inherit();

        $this->assertSame('#fefefe', ThemePalette::settingsRoles()['button_text']);
    }

    /**
     * Spec: Cases/storefront-colours.md [127]
     *   "The Bricks owner's `:hover` button pair is followed; a hover
     *    background alone keeps the resting text while it reads, and no hover
     *    is stated without a filled button."
     */
    public function test_the_hover_pair_is_followed(): void
    {
        $this->bricks(['s1' => $this->style([
            'button' => [
                'primaryBackground'       => $this->hex('#ff5500'),
                'primaryTypography'       => ['color' => $this->hex('#ffffff')],
                'primaryBackground:hover' => $this->hex('#222222'),
                'primaryTypography:hover' => ['color' => $this->hex('#FEFEFE')],
            ],
        ])]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#222222', $roles['button_hover_bg']);
        $this->assertSame('#fefefe', $roles['button_hover_text']);
    }

    public function test_a_hover_background_alone_keeps_the_resting_text(): void
    {
        $this->bricks(['s1' => $this->style([
            'button' => [
                'primaryBackground'       => $this->hex('#ff5500'),
                'primaryTypography'       => ['color' => $this->hex('#ffffff')],
                'primaryBackground:hover' => $this->hex('#222222'),
            ],
        ])]);
        $this->inherit();

        $this->assertArrayNotHasKey('button_hover_text', ThemePalette::settingsRoles());
        $this->assertSame('#ffffff', ThemePalette::resolve()['button_hover_text'], 'the resting text carries over');
    }

    public function test_no_hover_without_a_button(): void
    {
        $this->bricks(['s1' => $this->style([
            'typography' => ['typographyBody' => ['color' => $this->hex('#222222')]],
            'button'     => ['primaryBackground:hover' => $this->hex('#222222')],
        ])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('button_bg', $stated);
        $this->assertArrayNotHasKey('button_hover_bg', $stated);
    }

    /**
     * Spec: Cases/storefront-colours.md [128]
     *   "A Bricks colour picked from the palette stays live — written as the
     *    palette property Bricks prints, with the palette colour as its
     *    measurable fallback — including a palette colour whose raw value names
     *    its own property. A colour whose palette entry is gone is the colour
     *    it saved."
     */
    public function test_a_palette_colour_is_written_live(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors'     => ['colorPrimary' => ['id' => 'brand', 'hex' => '#7c3aed', 'name' => 'Brand']],
            'typography' => ['typographyBody' => ['color' => ['id' => 'ink', 'hex' => '#111827', 'raw' => 'var(--ink)']]],
            'general'    => ['siteBackground' => ['color' => ['id' => 'gone', 'hex' => '#FAFAFA']]],
        ])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('var(--bricks-color-brand, #7c3aed)', $stated['accent']);
        $this->assertSame('var(--ink, #111827)', $stated['text'], 'Bricks prints `--ink` for this palette colour');
        $this->assertSame('#fafafa', $stated['surface'], 'no palette entry: Bricks prints the saved colour');

        $css = FrontendTheme::buildCss();
        $this->assertStringContainsString('var(--bricks-color-brand, #7c3aed)', $css);
    }

    public function test_a_palette_edit_is_measured_from_the_palette(): void
    {
        $palette = self::PALETTE;
        $palette[0]['colors'][0]['hex'] = '#0f766e';

        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => ['id' => 'brand', 'hex' => '#7c3aed']],
        ])], $palette);
        $this->inherit();

        $this->assertSame('var(--bricks-color-brand, #0f766e)', ThemePalette::settingsRoles()['accent'], 'the style keeps a stale copy; the palette is what Bricks prints');
    }

    public function test_an_opaque_rgb_is_the_colour_it_names(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => ['hex' => '#000000', 'rgb' => 'rgba(124, 58, 237, 1)']],
        ])]);
        $this->inherit();

        $this->assertSame('#7c3aed', ThemePalette::settingsRoles()['accent'], 'Bricks prints rgb before hex');
    }

    /**
     * Spec: Cases/storefront-colours.md [129]
     *   "A Bricks colour FluentCart cannot write — a translucent rgba(), a
     *    palette colour that is translucent, a raw var() nothing measures, a
     *    gradient or a dynamic-data tag — is not stated, and a primary button
     *    background like that is not replaced by the Primary color."
     */
    public function test_unwritable_colours_are_not_stated(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors'     => ['colorPrimary' => ['hex' => '#7c3aed', 'rgb' => 'rgba(124, 58, 237, 0.5)']],
            'links'      => ['typography' => ['color' => $this->hex('#0f766e')]],
            'typography' => ['typographyBody' => ['color' => ['raw' => 'var(--not-a-bricks-colour)']]],
            'general'    => ['siteBackground' => ['color' => ['raw' => 'linear-gradient(#fff, #000)']]],
        ])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertArrayNotHasKey('accent', $stated, 'set but translucent: the link colour does not stand in');
        $this->assertArrayNotHasKey('text', $stated);
        $this->assertArrayNotHasKey('surface', $stated);
        $this->assertArrayNotHasKey('button_bg', $stated);
    }

    public function test_a_dynamic_or_translucent_palette_colour_is_not_stated(): void
    {
        $this->bricks(['s1' => $this->style([
            'links' => ['typography' => ['color' => ['raw' => '{acf_brand_colour}']]],
        ])]);
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles(), 'dynamic data is resolved on the page, not here');

        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => ['id' => 'haze', 'hex' => '#7c3aed']],
        ])]);
        $this->reset();

        $this->assertSame([], ThemePalette::settingsRoles(), 'Bricks prints the palette colour\'s rgba(), not the saved hex');
    }

    public function test_an_unwritable_primary_button_is_not_replaced(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => $this->hex('#ffd64f')],
            'button' => [
                'primaryBackground'       => ['rgb' => 'rgba(255, 85, 0, 0.6)'],
                'primaryBackground:hover' => $this->hex('#222222'),
            ],
        ])]);
        $this->inherit();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#ffd64f', $stated['accent']);

        foreach (['button_bg', 'button_text', 'button_hover_bg', 'button_hover_text'] as $role) {
            $this->assertArrayNotHasKey($role, $stated, $role . ': Bricks paints the rgba(), not the Primary color');
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [130]
     *   "The theme style read is the one Bricks chose for the page by its
     *    conditions: a style for one post outranks the site-wide one there and
     *    nowhere else, a page no style applies to states nothing, and when
     *    Bricks applies several styles the most specific one that sets a
     *    colour wins, as in its stylesheet. Where no page is being shown (the
     *    settings preview) Bricks is asked for the site-wide style, and its own
     *    choice is left as it was."
     */
    public function test_the_style_bricks_chose_for_the_page_is_read(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $otherId = self::factory()->post->create(['post_status' => 'publish']);

        $this->bricks([
            'site' => $this->style(['colors' => ['colorPrimary' => $this->hex('#ffd64f')]]),
            'post' => $this->style(['colors' => ['colorPrimary' => $this->hex('#7c3aed')]], [['main' => 'ids', 'ids' => [$postId]]]),
        ]);
        $this->inherit();

        $this->go_to(get_permalink($postId));
        $this->reset();
        $this->assertSame('#7c3aed', ThemePalette::settingsRoles()['accent'], 'the post\'s own style');

        \Bricks\Theme_Styles::$settings_by_id = [];
        $this->go_to(get_permalink($otherId));
        $this->reset();
        $this->assertSame('#ffd64f', ThemePalette::settingsRoles()['accent'], 'the site-wide style elsewhere');
    }

    public function test_a_page_no_style_applies_to_states_nothing(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $otherId = self::factory()->post->create(['post_status' => 'publish']);

        $this->bricks([
            'post' => $this->style(['colors' => ['colorPrimary' => $this->hex('#7c3aed')]], [['main' => 'ids', 'ids' => [$postId]]]),
        ]);
        $this->inherit();

        $this->go_to(get_permalink($otherId));
        $this->reset();

        $this->assertSame([], \Bricks\Theme_Styles::$settings_by_id, 'precondition: Bricks chose nothing');
        $this->assertSame([], ThemePalette::settingsRoles());
        $this->assertSame([], \Bricks\Theme_Styles::$settings_by_id, 'Bricks\' choice is not second-guessed');
    }

    public function test_the_most_specific_style_wins_per_colour(): void
    {
        $this->bricks([]);
        $this->inherit();

        // Bricks' "collect all styles that match" loading method: least
        // specific first, as its stylesheet prints them.
        \Bricks\Theme_Styles::$settings_by_id = [
            'site' => [
                'colors'     => ['colorPrimary' => $this->hex('#ffd64f')],
                'typography' => ['typographyBody' => ['color' => $this->hex('#222222')]],
            ],
            'shop' => [
                'colors'     => ['colorPrimary' => $this->hex('#7c3aed')],
                'typography' => ['typographyBody' => ['font-size' => '18px']],
            ],
        ];
        $GLOBALS['wp_actions']['wp'] = 1;
        $this->reset();

        $stated = ThemePalette::settingsRoles();

        $this->assertSame('#7c3aed', $stated['accent'], 'the later style wins');
        $this->assertSame('#222222', $stated['text'], 'a colour the later style does not set still applies');
    }

    public function test_with_no_page_bricks_is_asked_for_the_site_wide_style(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->bricks([
            'site' => $this->style(['colors' => ['colorPrimary' => $this->hex('#ffd64f')]]),
            'post' => $this->style(['colors' => ['colorPrimary' => $this->hex('#7c3aed')]], [['main' => 'ids', 'ids' => [$postId]]]),
        ]);
        $this->inherit();

        $this->assertSame(0, did_action('wp'), 'precondition: no page');
        $this->assertSame('#ffd64f', ThemePalette::settingsRoles()['accent']);
        $this->assertSame([], \Bricks\Theme_Styles::$settings_by_id, 'Bricks\' own state is left as it was');
    }

    /**
     * Spec: Cases/storefront-colours.md [131]
     *   "The site editor's button outranks the Bricks theme style, and the
     *    style's hover is not paired onto it."
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

        $this->bricks(['s1' => $this->style([
            'button' => [
                'primaryBackground'       => $this->hex('#ff5500'),
                'primaryTypography'       => ['color' => $this->hex('#000000')],
                'primaryBackground:hover' => $this->hex('#222222'),
            ],
        ])]);
        $this->inherit();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg']);
        $this->assertSame('#ffffff', $roles['button_text']);
        $this->assertSame(ColorMath::lighten('#111111', 12), $roles['button_hover_bg'], 'Bricks\' hover is not paired onto another button');
    }

    /**
     * Spec: Cases/storefront-colours.md [132]
     *   "Bricks, which publishes no palette, becomes a theme FluentCart can
     *    inherit from once a theme style states colours: they are written and
     *    the body is not marked no-colors. With the reader filtered off it
     *    behaves exactly as before, and FluentCart's own and customised colours
     *    are untouched by the reader."
     */
    public function test_bricks_becomes_a_usable_source(): void
    {
        $this->bricks(['s1' => $this->style([
            'colors'     => ['colorPrimary' => $this->hex('#ffd64f')],
            'typography' => ['typographyBody' => ['color' => $this->hex('#222222')]],
        ])]);
        $this->inherit();

        $this->assertTrue(ThemePalette::hasUsableSource());
        $this->assertNotSame([], FrontendTheme::getThemeColors(), 'Bricks\' colours are written');
        $this->assertNotContains('fluent-cart-no-colors', $this->bodyClasses());
    }

    public function test_a_disabled_reader_leaves_the_before_behaviour(): void
    {
        $this->bricks([]);
        add_filter('fluent_cart/theme/settings_roles', '__return_empty_array');
        $this->inherit();

        $this->assertSame([], ThemePalette::settingsRoles());
        $before = ThemePalette::resolve();
        $beforeCss = FrontendTheme::buildCss();
        $beforeClasses = $this->bodyClasses();

        $this->assertSame([], FrontendTheme::getThemeColors(), 'no palette, no reader: nothing to write');
        $this->assertContains('fluent-cart-no-colors', $beforeClasses);

        // A theme style that states colours, still filtered off: nothing moves.
        $this->bricks(['s1' => $this->style([
            'colors' => ['colorPrimary' => $this->hex('#7c3aed')],
            'button' => ['primaryBackground' => $this->hex('#ff5500')],
        ])]);

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

            \BricksStub::reset();
            $this->reset();
            $without = FrontendTheme::buildCss();

            $this->bricks(['s1' => $this->style([
                'colors' => ['colorPrimary' => $this->hex('#7c3aed')],
                'button' => ['primaryBackground' => $this->hex('#ff5500')],
            ])]);
            $with = FrontendTheme::buildCss();

            $this->assertSame($without, $with, $source . ' is byte-identical with a Bricks theme style present');
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [133]
     *   "The Bricks reader applies only while Bricks runs — a loaded but idle
     *    Bricks does not claim — and Bricks gets its turn when other vendors'
     *    readers are loaded; the filter is told the theme is 'bricks'."
     */
    public function test_an_idle_bricks_does_not_apply(): void
    {
        $this->assertTrue(class_exists('Bricks\\Theme_Styles', false), 'precondition: the classes are loaded');
        $this->inherit();

        $this->assertFalse(\FluentCart\App\Services\Theme\Readers\BricksSettingsReader::applies());
        $this->assertSame([], \FluentCart\App\Services\Theme\Readers\BricksSettingsReader::roles());
    }

    public function test_the_filter_sees_the_theme(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->bricks(['s1' => $this->style(['colors' => ['colorPrimary' => $this->hex('#7c3aed')]])]);
        $this->inherit();

        ThemePalette::settingsRoles();

        $this->assertSame('bricks', $seen['theme'] ?? null);
    }

    /**
     * Only one theme runs on a site, but the test process keeps every vendor
     * stub another test class loaded. Astra's stub answers for
     * `astra_get_option()` forever once loaded, so Bricks has to be asked
     * before Astra — and an idle Bricks stub must not claim ahead of Astra.
     */
    public function test_bricks_is_read_with_other_vendor_stubs_loaded(): void
    {
        require_once dirname(__DIR__, 3) . '/Support/Stubs/AstraStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/BlocksyStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/KadenceStubs.php';
        require_once dirname(__DIR__, 3) . '/Support/Stubs/DiviStubs.php';

        \AstraOptionsStub::reset();
        \BlocksyThemeModsStub::reset();
        \KadenceOptionsStub::reset();
        \DiviOptionsStub::reset();

        $seen = null;

        add_filter('fluent_cart/theme/settings_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return $roles;
        }, 10, 2);

        $this->bricks(['s1' => $this->style(['colors' => ['colorPrimary' => $this->hex('#7c3aed')]])]);
        $this->inherit();

        $this->assertSame('#7c3aed', ThemePalette::settingsRoles()['accent']);
        $this->assertSame('bricks', $seen['theme'] ?? null);

        // Bricks gone: the idle stub stands aside and Astra's answers.
        \BricksStub::reset();
        $this->reset();

        ThemePalette::settingsRoles();
        $this->assertSame('astra', $seen['theme'] ?? null);
    }

    /** A Bricks border control value with only a radius. */
    protected function radius($top, $right = null, $bottom = null, $left = null, array $unit = []): array
    {
        $right = $right === null ? $top : $right;
        $bottom = $bottom === null ? $top : $bottom;
        $left = $left === null ? $top : $left;
        $radius = ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left];

        if ($unit) {
            $radius['unit'] = $unit;
        }

        return ['radius' => $radius];
    }

    /**
     * Spec: Cases/storefront-colours.md [212]
     *   "Bricks' button radius is the primary button's border radius, else
     *    every button's; its form-field border radius is the input radius; a
     *    style that sets none states none."
     */
    public function test_bricks_radii_are_read_from_the_applied_style(): void
    {
        $this->bricks(['s1' => $this->style([
            'button' => ['border' => $this->radius('6')],
            'form'   => ['fieldBorder' => $this->radius('0.5rem')],
        ])]);

        $this->assertSame(['btn' => '6px', 'input' => '0.5rem'], BricksSettingsReader::radii(), 'a bare number is px, as Bricks prints it');

        $this->bricks(['s1' => $this->style([
            'button' => [
                'border'        => $this->radius('6'),
                'primaryBorder' => $this->radius('2', null, null, null, ['top' => 'em', 'right' => 'em', 'bottom' => 'em', 'left' => 'em']),
            ],
        ])]);

        $this->assertSame(['btn' => '2px'], ThemePalette::radii(), 'the primary button wins; Bricks forces px onto a bare non-zero number');
    }

    /**
     * Spec: Cases/storefront-colours.md [212]
     */
    public function test_a_bricks_style_without_a_radius_states_none(): void
    {
        $this->bricks();
        $this->assertSame([], BricksSettingsReader::radii(), 'no theme style at all');

        $this->bricks(['s1' => $this->style(['colors' => ['colorPrimary' => $this->hex('#ff5500')]])]);
        $this->assertSame([], BricksSettingsReader::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [203]
     */
    public function test_uneven_or_unwritable_bricks_radii_are_dropped(): void
    {
        $this->bricks(['s1' => $this->style([
            'button' => ['border' => $this->radius('6', '0')],
            'form'   => ['fieldBorder' => $this->radius('50%')],
        ])]);

        $this->assertSame([], ThemePalette::radii());

        $this->bricks(['s1' => $this->style([
            'button' => ['border' => ['radius' => ['top' => '4', 'left' => '4']]],
        ])]);

        $this->assertSame([], BricksSettingsReader::radii(), 'two unset corners are not one length');
    }
}
