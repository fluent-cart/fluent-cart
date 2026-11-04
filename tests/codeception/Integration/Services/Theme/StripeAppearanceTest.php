<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * What the storefront palette hands to Stripe's payment element.
 *
 * Stripe renders inside its own iframe, where FluentCart's custom properties
 * do not exist — a var() reference handed to Stripe resolves to nothing. So
 * the palette only speaks to Stripe in measurable hexes, only as a pair
 * (background and text from the same source), and stays silent otherwise:
 * Stripe's own default theme is a coherent light design, and half a dark
 * palette printed onto it would be worse than none of it.
 */
class StripeAppearanceTest extends WPTestCase
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
     * Spec: Cases/storefront-colours.md [74]
     *   "Stripe's payment element follows the palette: a measurable input
     *    pair seeds its appearance, and a dark pair turns on the night theme."
     */
    public function test_a_dark_palette_turns_on_the_night_theme(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#fbfaf3'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => '#7c3aed'],
            ];
        });

        $this->inherit();

        $appearance = FrontendTheme::stripeAppearance();

        $this->assertSame('night', $appearance['theme'], 'a dark input surface is Stripe\'s night, not its light default');
        $this->assertSame('#111111', $appearance['variables']['colorBackground'], 'the input surface rides along');
        $this->assertSame('#fbfaf3', $appearance['variables']['colorText'], 'with its text partner');
        $this->assertSame('#7c3aed', $appearance['variables']['colorPrimary'], 'and the accent for focus and selection');
    }

    public function test_a_light_palette_keeps_the_stripe_theme_with_its_own_colours(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });

        $this->inherit();

        $appearance = FrontendTheme::stripeAppearance();

        $this->assertSame('stripe', $appearance['theme'], 'a light surface keeps Stripe\'s light theme');
        $this->assertSame('#ffffff', $appearance['variables']['colorBackground'], 'still carrying the palette');
    }

    public function test_an_unmeasurable_palette_stays_silent(): void
    {
        // Astra's shape: bare references. Stripe cannot resolve a var() from
        // our page, so nothing is handed over and Stripe's default applies.
        // Neutral slugs on purpose: a named vendor's bare references can be
        // made measurable by the vendor-values reader, which would quietly
        // turn this into a different test.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Bg', 'color' => 'var(--theme-base)'],
                ['slug' => 'contrast', 'name' => 'Text', 'color' => 'var(--theme-contrast)'],
            ];
        });

        $this->inherit();

        $this->assertSame(['theme' => 'stripe'], FrontendTheme::stripeAppearance(), 'no measurable pair, no opinion');
    }

    public function test_the_owners_custom_colours_reach_stripe_too(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'input_bg_color'   => '#1b1b1b',
                'input_text_color' => '#eeeeee',
            ],
        ]);
        $this->reset();

        $appearance = FrontendTheme::stripeAppearance();

        $this->assertSame('night', $appearance['theme'], 'the customize source speaks to Stripe the same way');
        $this->assertSame('#1b1b1b', $appearance['variables']['colorBackground'], 'with the owner\'s own input surface');
        $this->assertSame('#eeeeee', $appearance['variables']['colorText'], 'and its text');
        $this->assertArrayNotHasKey('colorPrimary', $appearance['variables'], 'an unset accent is not invented');
    }

    public function test_the_palette_reaches_stripe_through_the_public_filter(): void
    {
        // FluentCart's own contribution rides the same filter developers use,
        // registered early so a listener at the default priority overrides
        // it — and it is one remove_filter() away entirely.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#fbfaf3'],
            ];
        });

        $this->inherit();

        $filtered = apply_filters('fluent_cart/stripe_appearance', ['theme' => 'stripe']);

        $this->assertSame('night', $filtered['theme'], 'the gateway default is reshaped by the palette listener');
        $this->assertSame('#111111', $filtered['variables']['colorBackground'], 'carrying the input pair');

        remove_filter('fluent_cart/stripe_appearance', [FrontendTheme::class, 'filterStripeAppearance'], 5);

        try {
            $this->assertSame(
                ['theme' => 'stripe'],
                apply_filters('fluent_cart/stripe_appearance', ['theme' => 'stripe']),
                'removing the named listener removes FluentCart\'s contribution'
            );
        } finally {
            add_filter('fluent_cart/stripe_appearance', [FrontendTheme::class, 'filterStripeAppearance'], 5);
        }
    }

    public function test_the_palette_fills_its_keys_without_deleting_anyone_elses(): void
    {
        // A listener BEFORE the palette's priority (or a customised seed)
        // may already carry labels, rules, or extra variables. The palette
        // is a default-filler, not an owner: it supplies its theme and its
        // three colour variables, and everything else survives.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#111111'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#fbfaf3'],
            ];
        });

        $this->inherit();

        $early = function ($appearance) {
            $appearance['labels'] = 'floating';
            $appearance['variables']['fontFamily'] = 'Manrope, sans-serif';

            return $appearance;
        };
        add_filter('fluent_cart/stripe_appearance', $early, 1);

        try {
            $filtered = apply_filters('fluent_cart/stripe_appearance', ['theme' => 'stripe']);
        } finally {
            remove_filter('fluent_cart/stripe_appearance', $early, 1);
        }

        $this->assertSame('night', $filtered['theme'], 'the plain default theme is still taken over');
        $this->assertSame('#111111', $filtered['variables']['colorBackground'], 'the palette supplies its keys');
        $this->assertSame('floating', $filtered['labels'], 'and deletes nobody else\'s');
        $this->assertSame('Manrope, sans-serif', $filtered['variables']['fontFamily'], 'including their variables');
    }

    /**
     * Spec: Cases/storefront-colours.md [76]
     *   "Stripe's payment-method tabs follow the button: the selected tab
     *    wears the button pair, and the accent is the button background —
     *    never the pale active-state surface."
     */
    public function test_the_selected_payment_tab_wears_the_button_pair(): void
    {
        // The reported store: primary_bg_color is a pale tint behind active
        // states. Handed to Stripe as its accent, the selected Card tab
        // painted pale label on pale fill and all but vanished.
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'input_bg_color'   => '#ffffff',
                'input_text_color' => '#27312a',
                'primary_bg_color' => '#f4f7ee',
                'btn_bg_color'     => '#809400',
                'btn_text_color'   => '#ffffff',
            ],
        ]);
        $this->reset();

        $appearance = FrontendTheme::stripeAppearance();

        $this->assertSame('#809400', $appearance['variables']['colorPrimary'], 'the accent is the button, not the pale surface');

        $selected = $appearance['rules']['.Tab--selected'];
        $this->assertSame('#809400', $selected['backgroundColor'], 'the selected tab is filled with the button background');
        $this->assertSame('#809400', $selected['borderColor'], 'and outlined in it');
        $this->assertSame('#ffffff', $selected['color'], 'with the button text');
        $this->assertSame($selected, $appearance['rules']['.Tab--selected:hover'], 'hovering the selected tab keeps it');
        $this->assertSame('#ffffff', $appearance['rules']['.TabIcon--selected']['fill'], 'its icon wears the button text');
        $this->assertSame('#ffffff', $appearance['rules']['.TabLabel--selected']['color'], 'and so does its label');
        $this->assertSame('#809400', $appearance['rules']['.Tab:hover']['borderColor'], 'an unselected tab only hints at it on hover');
    }

    public function test_the_button_pair_reaches_the_tabs_without_an_input_pair(): void
    {
        // The input pair gates the surface variables, not the button: a
        // button-coloured tab on Stripe's own light surface is coherent. A
        // button background without its text partner gets a measured one.
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color' => '#fde047',
            ],
        ]);
        $this->reset();

        $appearance = FrontendTheme::stripeAppearance();

        $this->assertSame('stripe', $appearance['theme'], 'no input pair keeps Stripe\'s light theme');
        $this->assertArrayNotHasKey('colorBackground', $appearance['variables'], 'and invents no surface');
        $this->assertSame('#fde047', $appearance['variables']['colorPrimary'], 'the accent still follows the button');
        $this->assertSame('#fde047', $appearance['rules']['.Tab--selected']['backgroundColor'], 'so does the selected tab');
        $this->assertSame('#1f2937', $appearance['rules']['.Tab--selected']['color'], 'with dark text measured against a light button');
    }

    public function test_the_tab_rules_fill_selectors_without_deleting_anyone_elses(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => [
                'btn_bg_color'   => '#809400',
                'btn_text_color' => '#ffffff',
            ],
        ]);
        $this->reset();

        $filtered = apply_filters('fluent_cart/stripe_appearance', [
            'theme' => 'flat',
            'rules' => [
                '.Input'         => ['borderRadius' => '0'],
                '.Tab--selected' => ['boxShadow' => 'none'],
            ],
        ]);

        $this->assertSame('flat', $filtered['theme'], 'an explicit theme choice stands');
        $this->assertSame(['borderRadius' => '0'], $filtered['rules']['.Input'], 'a selector the palette never touches survives');
        $this->assertSame('none', $filtered['rules']['.Tab--selected']['boxShadow'], 'a property the palette never sets survives');
        $this->assertSame('#809400', $filtered['rules']['.Tab--selected']['backgroundColor'], 'beside the palette\'s own');
        $this->assertSame('#809400', $filtered['variables']['colorPrimary'], 'and the accent arrives with the button alone');
    }

    public function test_the_default_source_hands_stripe_nothing(): void
    {
        update_option(self::OPTION, [
            'appearance_source' => ColorPalette::SOURCE_DEFAULT,
            'appearance_colors' => [],
        ]);
        $this->reset();

        $this->assertSame(['theme' => 'stripe'], FrontendTheme::stripeAppearance(), 'FluentCart\'s own colours mean Stripe\'s own theme');
    }

    /**
     * Spec: Cases/storefront-colours.md [75]
     *   "Every measurable effective colour carries a hex twin; the printed
     *    value never changes shape."
     */
    public function test_measured_colours_come_from_their_own_reader(): void
    {
        // Customify's shape for the surface (a reference with a hex
        // fallback), a bare reference for the accent. The effective colours
        // keep exactly what the page prints — no extra keys, no changed
        // shapes — while the measurable reader hands out plain hexes, and an
        // unmeasurable value is simply absent from it.
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => 'var(--customify-base, #ffffff)'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
                ['slug' => 'primary', 'name' => 'Primary', 'color' => 'var(--accent)'],
            ];
        });
        // A listener returning junk must not reach the measured reader.
        add_filter('fluent_cart/theme/storefront_colors', function ($colors) {
            $colors['rogue_key'] = 'var(--evil)';
            $colors['broken_key'] = ['not', 'a', 'colour'];

            return $colors;
        });

        $this->inherit();

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame('var(--customify-base, #ffffff)', $colors['input_bg_color'], 'the printed value keeps its shape');
        $this->assertArrayNotHasKey('__hex', $colors, 'and the effective colours carry no extra keys at all');

        $measured = FrontendTheme::getMeasurableColors();

        $this->assertSame('#ffffff', $measured['input_bg_color'], 'the reader measures a reference as its fallback');
        $this->assertSame('#111111', $measured['input_text_color'], 'a plain hex measures as itself');
        $this->assertArrayNotHasKey('primary_bg_color', $measured, 'a bare reference is absent, not guessed');
        $this->assertArrayNotHasKey('rogue_key', $measured, 'unmeasurable filter output is absent too');
        $this->assertArrayNotHasKey('broken_key', $measured, 'and a non-string value never reaches measurement');
    }

    public function test_a_listener_returning_a_non_array_is_discarded(): void
    {
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });
        add_filter('fluent_cart/theme/storefront_colors', function () {
            return 'not an array';
        });

        $this->inherit();

        $colors = FrontendTheme::getEffectiveColors();

        $this->assertSame('#ffffff', $colors['input_bg_color'], 'the source\'s own colours stand, not a mangled cast');
    }

    public function test_no_registry_key_is_reserved(): void
    {
        // Found in review, three times over: a flat hex_ prefix, then an
        // undocumented __hex map, then even a documented one — every shape
        // that claims a name inside a PUBLICLY extensible registry is a
        // landmine. Nothing is reserved now: an extension may register any
        // key it likes, __hex included, and it flows to the page like every
        // other registered colour.
        add_filter('fluent_cart/theme/color_globals', function ($globals) {
            $globals['__hex'] = [
                'var'   => '--fct-extension-hex',
                'label' => 'Extension colour',
                'note'  => '',
                'group' => 'surface',
                'role'  => 'text',
            ];

            return $globals;
        });
        add_filter('fluent_cart/theme/palette', function () {
            return [
                ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
            ];
        });

        $this->inherit();

        $this->assertSame(
            '#111111',
            FrontendTheme::getEffectiveColors()['__hex'],
            'the extension\'s key resolves like any registered colour'
        );
        $this->assertStringContainsString(
            '--fct-extension-hex:#111111;',
            FrontendTheme::buildCss(),
            'and reaches the page CSS untouched'
        );
    }
}
