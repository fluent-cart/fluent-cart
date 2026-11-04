<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Reading the active theme's palette.
 *
 * Two things make this less obvious than it looks. WordPress ships its own
 * twelve-colour palette that every site has whether or not the theme declares
 * anything, so counting it would let a theme claim colours it never published.
 * And several popular themes publish their palette as CSS variable references
 * rather than literal colours, which cannot be resolved server-side.
 */
class ThemePaletteTest extends WPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThemePalette::clearCache();
    }

    protected function tearDown(): void
    {
        ThemePalette::clearCache();

        // Several tests fixture user global styles through the
        // `wp_theme_json_data_user` filter. The resolver caches what it
        // computed while that filter was live, and the cache outlives the
        // filter — without this, the LAST fixture in this class leaks into
        // whichever test class runs next.
        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        parent::tearDown();
    }

    /**
     * Spec: Cases/storefront-colours.md [20]
     *   "WordPress's own default palette is not offered as the theme's palette."
     */
    public function test_wordpress_own_default_palette_is_not_treated_as_the_themes(): void
    {
        // Astra and every other classic theme leave the `default` origin
        // populated with black/white/vivid-*. Counting those made the settings
        // screen tell the store owner the theme "provides 12 palette colours".
        $slugs = array_column(ThemePalette::palette(), 'slug');

        foreach (['vivid-red', 'luminous-vivid-orange', 'pale-cyan-blue', 'vivid-purple'] as $coreSlug) {
            $this->assertNotContains(
                $coreSlug,
                $slugs,
                "'{$coreSlug}' is WordPress's colour, not the theme's, and must not count toward the palette"
            );
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [21]
     *   "A palette colour written as a CSS variable reference is passed through
     *    for the browser to resolve."
     */
    public function test_a_variable_reference_colour_is_passed_through(): void
    {
        // Astra, Kadence and GeneratePress publish their palette this way: the
        // real value lives in the theme's own settings and is emitted as a
        // custom property at runtime. Refusing these made inheritance a no-op
        // on a large share of real stores.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'accent', 'name' => 'Accent', 'color' => 'var(--accent)'],
            ];
        });

        $this->assertSame('#ffffff', ThemePalette::value('base'), 'a literal colour resolves to itself');
        $this->assertSame(
            'var(--accent)',
            ThemePalette::value('accent'),
            'a reference is kept so the browser can resolve what we cannot'
        );
        $this->assertSame(
            '',
            ThemePalette::color('accent'),
            'but it is still not a colour, so anything doing arithmetic sees nothing'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [35]
     *   "Only a bare custom-property reference, or one whose sole fallback is
     *    a hex colour, is accepted; anything more elaborate is refused."
     */
    public function test_only_a_bare_custom_property_reference_is_accepted(): void
    {
        // This value is written into a declaration on every storefront page,
        // so the accepted grammar has to be narrow enough that nothing else
        // can ride along inside it.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'plain', 'name' => 'Plain', 'color' => 'var(--ok)'],
                ['slug' => 'spaced', 'name' => 'Spaced', 'color' => '  var( --ok-2 )  '],
                ['slug' => 'hexfb', 'name' => 'Hex fallback', 'color' => 'var(--customify-primary, #0e7c7b)'],
                ['slug' => 'fallback', 'name' => 'Fallback', 'color' => 'var(--a, red)'],
                ['slug' => 'nested', 'name' => 'Nested', 'color' => 'var(--a, var(--b))'],
                ['slug' => 'breakout', 'name' => 'Breakout', 'color' => 'var(--a); } body { display:none'],
                ['slug' => 'notvar', 'name' => 'Not var', 'color' => 'url(evil.png)'],
                ['slug' => 'badname', 'name' => 'Bad name', 'color' => 'var(--a[b])'],
            ];
        });

        $this->assertSame('var(--ok)', ThemePalette::value('plain'), 'the bare form is accepted');
        $this->assertSame('var(--ok-2)', ThemePalette::value('spaced'), 'and normalised');

        // Customify publishes its palette exactly like this at runtime. The
        // hex fallback is the one extra shape allowed: it is the only fallback
        // that is itself checkable, and it is the difference between a theme
        // we can follow live AND measure, and one we must refuse entirely.
        $this->assertSame(
            'var(--customify-primary, #0e7c7b)',
            ThemePalette::value('hexfb'),
            'a reference with a hex fallback is accepted and normalised'
        );
        $this->assertSame(
            '#0e7c7b',
            ThemePalette::color('hexfb'),
            'and the fallback is the measurable colour it carries'
        );

        foreach (['fallback', 'nested', 'breakout', 'notvar', 'badname'] as $slug) {
            $this->assertSame('', ThemePalette::value($slug), "'{$slug}' is not a bare reference and must be refused");
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [59]
     *   "A reference carrying a hex fallback is written as the reference and
     *    measured as the fallback."
     */
    public function test_a_hex_fallback_reference_is_written_live_and_measured_static(): void
    {
        // Customify's runtime palette: shared slugs, var() values, hex
        // fallbacks. The reference is what reaches the page — the store keeps
        // following the theme's customiser — while the fallback is what the
        // derivation mixes and measures, so borders, muted text and button
        // contrast all resolve instead of standing down.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => 'var(--customify-base, #ffffff)'],
                ['slug' => 'text', 'name' => 'Text', 'color' => 'var(--customify-text, #686868)'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => 'var(--customify-primary, #0e7c7b)'],
            ];
        });

        $roles = ThemePalette::resolve();

        $this->assertSame(
            'var(--customify-primary, #0e7c7b)',
            $roles['button_bg'],
            'the reference is inherited, so the store follows live edits'
        );
        $this->assertSame(
            ColorMath::mix('#686868', '#ffffff', 18),
            $roles['border'],
            'the derived tones are mixed from the fallbacks the theme shipped'
        );
        $this->assertSame(
            ColorMath::readableOn('#0e7c7b'),
            $roles['button_text'],
            'and button text is contrast-picked against the fallback'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [36]
     *   "When an anchor is a reference that cannot be measured, the derived
     *    tones are left unresolved."
     */
    public function test_derived_tones_stand_down_when_the_anchors_cannot_be_measured(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Background', 'color' => 'var(--base)'],
                ['slug' => 'contrast', 'name' => 'Text', 'color' => 'var(--contrast)'],
                ['slug' => 'accent', 'name' => 'Brand', 'color' => 'var(--accent)'],
            ];
        });

        $roles = ThemePalette::resolve();

        $this->assertSame('var(--accent)', $roles['accent'], 'the anchor is still inherited');

        // Mixing an unreadable value collapses to whichever end parsed, which
        // would make muted text the same colour as the surface behind it. So
        // nothing is produced — and nothing is what gets written, leaving each
        // property on the fallback its own stylesheet carries. Substituting
        // that fallback here instead would write the same colour twice, under
        // the active theme's name the second time.
        foreach (['surface_alt', 'surface_mute', 'divider', 'border', 'text_placeholder', 'text_muted'] as $tone) {
            $this->assertSame(
                '',
                $roles[$tone],
                "'{$tone}' cannot be mixed from a reference, so it must resolve to nothing"
            );
        }
        $this->assertSame(
            '',
            $roles['button_text'],
            'contrast cannot be measured against a reference, so button text resolves to nothing'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [23]
     *   "Anchors are matched to the palette slugs block themes actually publish."
     */
    public function test_anchors_match_the_slugs_block_themes_publish(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            // Twenty Twenty-Four's palette.
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'accent-1', 'name' => 'Accent 1', 'color' => '#ffe2c7'],
            ];
        });

        $map = ThemePalette::anchorMap();

        $this->assertSame('base', $map['surface'], 'base is the surface slug block themes use');
        $this->assertSame('contrast', $map['text'], 'contrast is the body-text slug');
        $this->assertSame('accent-1', $map['accent'], 'accent-1 is the first brand slug');

        $anchors = ThemePalette::anchors();
        $this->assertSame('#f9f9f9', $anchors['surface'], 'the anchor resolves to the palette colour');
        $this->assertSame('#111111', $anchors['text'], 'and so does the text anchor');
    }

    /**
     * Spec: Cases/storefront-colours.md [60]
     *   "The site's own global background and text outrank a palette guess."
     */
    public function test_global_styles_outrank_a_palette_guess(): void
    {
        // Twenty Twenty-Five with a dark style variation: the palette still
        // declares `base: #FFFFFF` — that is the list of AVAILABLE colours —
        // but the site's global styles say what the body actually RENDERS:
        // a dark background and muted text. Guessing the surface from the
        // `base` slug painted dark text and light borders onto a dark store,
        // which is how card titles went invisible. For surface and text the
        // global styles are definitionally right, so they win; the palette
        // slugs remain the fallback for sites that never set global styles.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'accent-1', 'name' => 'Accent 1', 'color' => '#ffee58'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'color' => ['background' => '#111111', 'text' => '#686868'],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $anchors = ThemePalette::anchors();

        $this->assertSame('#111111', $anchors['surface'], 'the surface is what the site renders, not the palette guess');
        $this->assertSame('#686868', $anchors['text'], 'and so is the body text');
        $this->assertSame('#ffee58', $anchors['accent'], 'the accent still comes from the palette — global styles do not name one');
    }

    /**
     * Spec: Cases/storefront-colours.md [64]
     *   "A body text the site states is worn as given, even without a stated
     *    background; the surface then comes from the next source."
     */
    public function test_a_stated_text_alone_is_worn_as_given(): void
    {
        // A site whose global styles set ONLY the text. It is the owner's
        // choice, so it is worn — never swapped for the palette's text.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => ['color' => ['text' => '#fbfaf3']],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $anchors = ThemePalette::anchors();

        $this->assertSame('#ffffff', $anchors['surface'], 'the surface comes from the palette');
        $this->assertSame('#fbfaf3', $anchors['text'], 'the text is the one the site chose');
    }

    public function test_a_stated_settings_text_alone_is_worn_as_given(): void
    {
        // A theme settings reader that states the body text but no surface.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });
        add_filter('fluent_cart/theme/settings_roles', function () {
            return ['text' => '#d280ff'];
        });
        ThemePalette::clearCache();

        $anchors = ThemePalette::anchors();

        $this->assertSame('#ffffff', $anchors['surface'], 'the surface comes from the palette');
        $this->assertSame('#d280ff', $anchors['text'], 'the text is the one the owner chose');
    }

    /**
     * Spec: Cases/storefront-colours.md [64]
     *   A surface with no text partner anywhere derives one by contrast.
     */
    public function test_a_lone_surface_derives_a_readable_text_partner(): void
    {
        // A palette that names only its background. Leaving text unwritten
        // would put the stylesheets' dark fallback text on whatever surface
        // this is — unreadable when the surface is dark — so the partner is
        // picked by measuring the surface, the same way button text is.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#1b2430'],
            ];
        });
        ThemePalette::clearCache();

        $anchors = ThemePalette::anchors();

        $this->assertSame('#1b2430', $anchors['surface'], 'the surface is read');
        $this->assertNotSame('', $anchors['text'], 'a lone surface gets a text partner');
        $this->assertGreaterThan(
            4.5,
            ColorMath::contrast($anchors['text'], $anchors['surface']),
            'and the partner is picked for contrast, not guessed'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [24]
     *   "A theme that publishes no palette says so rather than claiming colours
     *    it does not have."
     */
    public function test_a_theme_without_a_palette_says_so(): void
    {
        add_filter('fluent_cart/theme/palette', '__return_empty_array');

        $this->assertFalse(ThemePalette::available(), 'an empty palette is not available to inherit from');
        $this->assertStringContainsString(
            'does not publish a colour palette',
            ThemePalette::sourceLabel(),
            'the settings screen must not offer a count the theme did not earn'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [69]
     *   "The site editor's button colours outrank every guess: what the owner
     *    set is what the buttons wear."
     */
    public function test_site_editor_button_colours_outrank_every_guess(): void
    {
        // The palette would guess the accent for the button — but the owner
        // opened Styles → Buttons in the site editor and said otherwise.
        // That choice lands in styles.elements.button.color, backgrounds
        // often as a preset reference, and it is the most explicit statement
        // a site can make about its buttons.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'accent-1', 'name' => 'Accent 1', 'color' => '#ffee58'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => [
                                'background' => 'var:preset|color|contrast',
                                'text'       => '#f81212',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['button_bg'], 'the preset reference resolves through the palette to what the owner picked');
        $this->assertSame('#f81212', $roles['button_text'], 'and the owner\'s text is worn as given, not re-derived');
        $this->assertSame('#ffee58', $roles['accent'], 'the accent still comes from the palette — the owner only spoke about buttons');
    }

    /**
     * Spec: Cases/storefront-colours.md [70]
     *   "A site-editor button background without a text partner gets a
     *    measured one; a button text the owner set alone is worn as given
     *    on whatever background the button otherwise gets."
     */
    public function test_a_lone_site_editor_button_background_gets_a_measured_partner(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => ['background' => '#e8f0fe'],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#e8f0fe', $roles['button_bg'], 'the lone background is still the owner\'s choice');
        $this->assertSame('#1f2937', $roles['button_text'], 'and its partner is measured against it, dark on a pale button');
    }

    public function test_a_lone_site_editor_button_text_is_worn_as_given(): void
    {
        // The owner set only the button text: it is worn on whatever
        // background the button otherwise gets.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => ['text' => '#f81212'],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#7c3aed', $roles['button_bg'], 'the palette carries the background');
        $this->assertSame('#f81212', $roles['button_text'], 'the text is the one the owner chose');
    }

    /**
     * Spec: Cases/storefront-colours.md [71]
     *   "The secondary button keeps its outline: it borrows only the stated
     *    pair's readable half as its label, measured against the page
     *    surface."
     */
    public function test_the_secondary_button_borrows_the_pairs_readable_half(): void
    {
        // Twenty Twenty-Five's light face states black-on-white buttons.
        // Wearing that pair on the secondary button painted BOTH CTAs black
        // and erased the hierarchy; ignoring the pair entirely left the
        // label unrelated to the buttons beside it. So the outline stays —
        // the page surface is the background — and the label is whichever
        // half of the stated pair reads best on that surface.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => [
                                'background' => '#000000',
                                'text'       => '#f81212',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#ffffff', $roles['secondary_button_bg'], 'the outline keeps the page surface');
        $this->assertSame('#000000', $roles['secondary_button_text'], 'on a light surface the pair\'s dark half is the label');
        $this->assertSame('#000000', $roles['button_bg'], 'the primary button still wears the pair in full');
    }

    public function test_a_half_that_does_not_actually_read_is_not_borrowed(): void
    {
        // Adversarial case (found in review): a mid-grey page whose stated
        // pair is dark-on-white. BOTH halves fall short of readable contrast
        // on the grey surface — the "better" half is still only ~3.6:1. The
        // label must then stay the page's own text, which does read.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#888888'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#000000'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => [
                                'background' => '#333333',
                                'text'       => '#ffffff',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#888888', $roles['secondary_button_bg'], 'the outline keeps the surface');
        $this->assertSame('#000000', $roles['secondary_button_text'], 'neither half reads on grey, so the page text stays');
    }

    public function test_on_a_dark_surface_the_pairs_light_half_is_the_label(): void
    {
        // The mirror case: a dark style variation whose stated button text is
        // a brand red. The red half reads on the dark surface; the black
        // background half does not.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#fbfaf3'],
            ];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => [
                                'background' => '#111111',
                                'text'       => '#f81212',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $roles = ThemePalette::resolve();

        $this->assertSame('#111111', $roles['secondary_button_bg'], 'the outline keeps the dark surface');
        $this->assertSame('#f81212', $roles['secondary_button_text'], 'and the stated red is the half that reads on it');
    }

    /**
     * Spec: Cases/storefront-colours.md [72]
     *   "A theme that states only its button pair is still a usable source."
     */
    public function test_a_button_only_configuration_is_a_usable_source(): void
    {
        // No palette, no page globals — the owner opened the site editor and
        // set ONLY the buttons. The source gate must not discard that: the
        // pair was explicitly configured, and getThemeColors() returning
        // nothing here would keep it out of the storefront CSS entirely.
        add_filter('fluent_cart/theme/palette', function () {
            return [];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => [
                                'background' => '#000000',
                                'text'       => '#f81212',
                            ],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $this->assertTrue(ThemePalette::hasUsableSource(), 'a stated button pair is something to inherit');

        update_option('fluent_cart_store_settings', [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        ColorPalette::clearCache();
        StoreSettings::clearCache();

        try {
            $css = FrontendTheme::buildCss();

            $this->assertStringContainsString('--fct-btn-bg-color:#000000;', $css, 'the pair reaches the storefront CSS');
            $this->assertStringContainsString('--fct-btn-text-color:#f81212;', $css, 'both halves of it');
            $this->assertNotContains(
                'fluent-cart-no-colors',
                FrontendTheme::bodyClasses([]),
                'and a store wearing the pair is not marked as wearing nothing'
            );
        } finally {
            delete_option('fluent_cart_store_settings');
            ColorPalette::clearCache();
            StoreSettings::clearCache();
        }
    }

    public function test_a_lone_site_editor_button_text_is_a_usable_source(): void
    {
        // The owner set ONLY the button text in the site editor, on a theme
        // with nothing else to read. That text is a choice to wear, so the
        // source gate must not discard it.
        add_filter('fluent_cart/theme/palette', function () {
            return [];
        });
        add_filter('wp_theme_json_data_user', function ($themeJson) {
            return $themeJson->update_with([
                'version' => 2,
                'styles'  => [
                    'elements' => [
                        'button' => [
                            'color' => ['text' => '#f81212'],
                        ],
                    ],
                ],
            ]);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }
        ThemePalette::clearCache();

        $this->assertTrue(ThemePalette::hasUsableSource(), 'a stated button text is something to inherit');

        update_option('fluent_cart_store_settings', [
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_colors' => [],
        ]);
        ColorPalette::clearCache();
        StoreSettings::clearCache();

        try {
            $css = FrontendTheme::buildCss();

            $this->assertStringContainsString('--fct-btn-text-color:#f81212;', $css, 'the text reaches the storefront CSS');
            $this->assertStringNotContainsString('--fct-btn-bg-color:', $css, 'and no background is invented for it');
            $this->assertNotContains('fluent-cart-no-colors', FrontendTheme::bodyClasses([]));
        } finally {
            delete_option('fluent_cart_store_settings');
            ColorPalette::clearCache();
            StoreSettings::clearCache();
        }
    }

    /**
     * Fixture user global styles (and settings), as the site editor saves them.
     */
    protected function userGlobalStyles(array $styles, array $settings = []): void
    {
        add_filter('wp_theme_json_data_user', function ($themeJson) use ($styles, $settings) {
            $data = ['version' => 3, 'styles' => $styles];

            if ($settings) {
                $data['settings'] = $settings;
            }

            return $themeJson->update_with($data);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        ThemePalette::clearCache();
    }

    /**
     * Spec: Cases/storefront-colours.md [200]
     *   "The site editor's button radius is the button radius."
     */
    public function test_the_site_editor_button_radius_is_the_button_radius(): void
    {
        $this->userGlobalStyles(['elements' => ['button' => ['border' => ['radius' => '12px']]]]);

        $radii = ThemePalette::radii();

        $this->assertSame('12px', $radii['btn']);
        $this->assertArrayNotHasKey('card', $radii, 'the site editor has no card radius to state');
    }

    /**
     * Spec: Cases/storefront-colours.md [201]
     *   "A site-editor radius given as a preset or a custom property resolves
     *    to the length it names."
     */
    public function test_a_site_editor_radius_preset_or_custom_property_resolves(): void
    {
        $this->userGlobalStyles(
            ['elements' => [
                'button'    => ['border' => ['radius' => 'var:preset|border-radius|md']],
                'textInput' => ['border' => ['radius' => 'var(--wp--custom--radius--field)']],
            ]],
            [
                'border' => ['radiusSizes' => [['slug' => 'md', 'name' => 'Medium', 'size' => '0.5rem']]],
                'custom' => ['radius' => ['field' => '6px']],
            ]
        );

        $radii = ThemePalette::radii();

        $this->assertSame('0.5rem', $radii['btn'], 'the preset resolves to its size');
        $this->assertSame('6px', $radii['input'], 'the custom property resolves to its value');
    }

    /**
     * Spec: Cases/storefront-colours.md [202]
     *   "The site editor's text-input radius is the form-input radius."
     */
    public function test_the_site_editor_text_input_radius_is_the_input_radius(): void
    {
        $this->userGlobalStyles(['elements' => [
            'textInput' => ['border' => ['radius' => [
                'topLeft' => '4px', 'topRight' => '4px', 'bottomLeft' => '4px', 'bottomRight' => '4px',
            ]]],
        ]]);

        $this->assertSame(['input' => '4px'], ThemePalette::radii(), 'four equal corners are one length');
    }

    /**
     * Spec: Cases/storefront-colours.md [202]
     *   "... else `select`."
     */
    public function test_the_site_editor_select_radius_stands_in_for_the_input_radius(): void
    {
        $this->userGlobalStyles(['elements' => ['select' => ['border' => ['radius' => '0']]]]);

        $this->assertSame(['input' => '0'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [203]
     *   "Per-corner radii that differ, or a value outside the grammar, state
     *    nothing."
     */
    public function test_uneven_corners_and_unwritable_radii_state_nothing(): void
    {
        $this->userGlobalStyles(['elements' => [
            'button'    => ['border' => ['radius' => '50%']],
            'textInput' => ['border' => ['radius' => [
                'topLeft' => '4px', 'topRight' => '8px', 'bottomLeft' => '4px', 'bottomRight' => '8px',
            ]]],
        ]]);

        $this->assertSame([], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [205]
     *   "The filter sees the theme, and what it returns is re-normalised:
     *    unknown roles and unwritable values are dropped."
     */
    public function test_the_radius_filter_is_re_normalised(): void
    {
        $seen = null;

        add_filter('fluent_cart/theme/radius_roles', function ($roles, $context) use (&$seen) {
            $seen = $context;

            return [
                'card'   => ' 16PX ',
                'btn'    => 'calc(1px + 2px)',
                'input'  => '-4px',
                'popup'  => '10px',
            ];
        }, 10, 2);

        ThemePalette::clearCache();
        $radii = ThemePalette::radii();

        $this->assertSame(['card' => '16px'], $radii, 'only a known role with one writable length survives');
        $this->assertIsArray($seen);
        $this->assertSame(get_template(), $seen['theme'], 'the filter is told the active theme');
        $this->assertArrayHasKey('reader', $seen);
    }

    /**
     * Spec: Cases/storefront-colours.md [205]
     *   "... a filter returning something that is not a list states nothing."
     */
    public function test_a_radius_filter_returning_garbage_states_nothing(): void
    {
        add_filter('fluent_cart/theme/radius_roles', '__return_false');
        ThemePalette::clearCache();

        $this->assertSame([], ThemePalette::radii());
    }

    /**
     * Fixture theme-origin global styles (and settings), as theme.json ships them.
     */
    protected function themeGlobalStyles(array $styles, array $settings = []): void
    {
        add_filter('wp_theme_json_data_theme', function ($themeJson) use ($styles, $settings) {
            $data = ['version' => 3, 'styles' => $styles];

            if ($settings) {
                $data['settings'] = $settings;
            }

            return $themeJson->update_with($data);
        });

        if (class_exists('WP_Theme_JSON_Resolver')) {
            \WP_Theme_JSON_Resolver::clean_cached_data();
        }

        ThemePalette::clearCache();
    }

    /**
     * Spec: Cases/storefront-colours.md [215]
     *   "A block theme's Button block radius is the button radius when the
     *    button element states none." (online-courses-fse: 50px)
     */
    public function test_the_button_block_radius_is_the_button_fallback(): void
    {
        $this->themeGlobalStyles(['blocks' => ['core/button' => ['border' => ['radius' => '50px']]]]);

        $this->assertSame(['btn' => '50px'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [216]
     *   "Within one origin the button element's radius outranks the Button block's."
     */
    public function test_the_button_element_outranks_the_button_block(): void
    {
        $this->themeGlobalStyles([
            'elements' => ['button' => ['border' => ['radius' => '4px']]],
            'blocks'   => ['core/button' => ['border' => ['radius' => '50px']]],
        ]);

        $this->assertSame(['btn' => '4px'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [217]
     *   "A ref pointer resolves against the merged theme.json data; a ref is
     *    not four corners."
     */
    public function test_a_ref_pointer_resolves_against_the_merged_data(): void
    {
        $this->themeGlobalStyles([
            'elements' => ['button' => ['border' => ['radius' => ['ref' => 'styles.blocks.core/quote.border.radius']]]],
            'blocks'   => [
                'core/quote' => ['border' => ['radius' => ['ref' => 'styles.blocks.core/code.border.radius']]],
                'core/code'  => ['border' => ['radius' => '0.33rem']],
            ],
        ]);

        $this->assertSame(['btn' => '0.33rem'], ThemePalette::radii(), 'two hops are followed');
    }

    /**
     * Spec: Cases/storefront-colours.md [217]
     */
    public function test_a_ref_loop_or_a_dangling_ref_states_nothing(): void
    {
        $this->themeGlobalStyles([
            'elements' => ['button' => ['border' => ['radius' => ['ref' => 'styles.elements.button.border.radius']]]],
            'blocks'   => ['core/search' => ['border' => ['radius' => ['ref' => 'styles.blocks.core/nothing.border.radius']]]],
        ]);

        $this->assertSame([], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [218]
     *   "A spacing preset resolves through settings.spacing.spacingSizes; a
     *    fluid size is dropped."
     */
    public function test_a_spacing_preset_resolves_and_a_fluid_one_is_dropped(): void
    {
        $this->themeGlobalStyles(
            [
                'elements' => [
                    'button'    => ['border' => ['radius' => 'var(--wp--preset--spacing--fct-fixed)']],
                    'textInput' => ['border' => ['radius' => 'var:preset|spacing|fct-fluid']],
                ],
            ],
            ['spacing' => ['spacingSizes' => [
                ['slug' => 'fct-fixed', 'name' => 'Fixed', 'size' => '1rem'],
                ['slug' => 'fct-fluid', 'name' => 'Fluid', 'size' => 'min(1.5rem, 2vw)'],
            ]]]
        );

        $this->assertSame(['btn' => '1rem'], ThemePalette::radii(), 'TT4 states its radii as min() spacing — those are dropped');
    }

    /**
     * Spec: Cases/storefront-colours.md [219]
     *   "The Search block's border radius is the input radius when no
     *    text-input or select element states one."
     */
    public function test_the_search_block_radius_is_the_input_fallback(): void
    {
        $this->themeGlobalStyles(['blocks' => ['core/search' => ['border' => ['radius' => '0px']]]]);
        $this->assertSame(['input' => '0px'], ThemePalette::radii());

        $this->userGlobalStyles(['elements' => ['textInput' => ['border' => ['radius' => '6px']]]]);
        $this->assertSame(['input' => '6px'], ThemePalette::radii(), 'the text input element outranks the Search block');
    }

    /**
     * Spec: Cases/storefront-colours.md [220]
     *   "A style variation the owner picked (saved in the user origin) is
     *    read, over the theme's own values." (TT5 Afternoon / Midnight)
     */
    public function test_a_picked_style_variation_is_read(): void
    {
        $this->themeGlobalStyles(['elements' => ['button' => ['border' => ['radius' => '100px']]]]);
        $this->assertSame(['btn' => '100px'], ThemePalette::radii());

        // Afternoon: only the Button block.
        $this->userGlobalStyles(['blocks' => ['core/button' => ['border' => ['radius' => '0px']]]]);
        $this->assertSame(['btn' => '0px'], ThemePalette::radii(), 'the owner\'s variation outranks the theme');
    }

    /**
     * Spec: Cases/storefront-colours.md [220]
     */
    public function test_the_midnight_variation_states_button_and_input(): void
    {
        $this->userGlobalStyles([
            'elements' => ['button' => ['border' => ['radius' => '0px']]],
            'blocks'   => ['core/search' => ['border' => ['radius' => '0px']]],
        ]);

        $this->assertSame(['btn' => '0px', 'input' => '0px'], ThemePalette::radii());
    }

    /**
     * Spec: Cases/storefront-colours.md [221]
     *   "No theme.json path is read as the product-card radius."
     */
    public function test_no_theme_json_path_is_a_card_radius(): void
    {
        $this->themeGlobalStyles(['blocks' => [
            'core/post-featured-image' => ['border' => ['radius' => '12px']],
            'core/image'               => ['border' => ['radius' => '12px']],
            'core/group'               => ['border' => ['radius' => '12px']],
        ]]);

        $this->assertArrayNotHasKey('card', ThemePalette::radii());
    }
}
