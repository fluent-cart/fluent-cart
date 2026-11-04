<?php

namespace Tests\Integration\Services\Theme;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Http\Requests\FluentMetaRequest;
use FluentCart\App\Services\Theme\ColorPalette;
use FluentCart\App\Services\Theme\FrontendTheme;
use FluentCart\App\Services\Theme\RadiusPalette;
use FluentCart\App\Services\Theme\ThemePalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The border-radius tokens the storefront prints.
 *
 * The stylesheets already read `var(--fct-card-radius, …)`, `--fct-btn-radius`
 * and `--fct-input-radius` with their own fallbacks; this is the part that
 * decides when those tokens are declared. Every value lands inside a style
 * element on every storefront page, so each layer re-checks the grammar.
 */
class StorefrontRadiusTest extends WPTestCase
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
        remove_all_filters('fluent_cart/theme/storefront_radii');
        remove_all_filters('fluent_cart/theme/radius_roles');
        remove_all_filters('fluent_cart/theme/palette');
        $this->reset();
        parent::tearDown();
    }

    protected function reset(): void
    {
        ColorPalette::clearCache();
        ThemePalette::clearCache();
        StoreSettings::clearCache();
    }

    protected function store(array $settings): void
    {
        update_option(self::OPTION, $settings);
        $this->reset();
    }

    protected function radiusSanitizer(): callable
    {
        $sanitize = (new FluentMetaRequest(App::getInstance()))->sanitize();

        $this->assertArrayHasKey('appearance_radius', $sanitize, 'a store-settings key missing from the sanitiser is silently dropped on save');

        return $sanitize['appearance_radius'];
    }

    /**
     * Spec: Cases/storefront-colours.md [250]
     *   "A radius value is one length — 0, or a non-negative number in px, rem
     *    or em — and anything else is refused."
     */
    public function test_the_length_grammar_accepts_one_plain_length_only(): void
    {
        $accepted = [
            '0'        => '0',
            '8px'      => '8px',
            ' 8PX '    => '8px',
            '0px'      => '0px',
            '0.5rem'   => '0.5rem',
            '.5rem'    => '0.5rem',
            '1.250em'  => '1.25em',
            '12.0px'   => '12px',
            '9999px'   => '9999px',
        ];

        foreach ($accepted as $input => $expected) {
            $this->assertSame($expected, RadiusPalette::sanitizeLength($input), 'accepted and normalised: ' . var_export($input, true));
        }

        $refused = [
            '', '8', 8, 0.5, '-4px', '50%', 'var(--x)', 'calc(4px + 2px)', '4px 8px',
            '8px;}body{display:none', '8 px', 'px', '1e3px', '8vh', 'inherit', null, [], true,
        ];

        foreach ($refused as $input) {
            $this->assertSame('', RadiusPalette::sanitizeLength($input), 'refused: ' . var_export($input, true));
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [251]
     *   "The radius registry names the three tokens the stylesheets read."
     */
    public function test_the_registry_names_the_three_tokens_the_stylesheets_read(): void
    {
        $roles = RadiusPalette::roles();

        $this->assertSame(['card', 'btn', 'input'], array_keys($roles));
        $this->assertSame('--fct-card-radius', $roles['card']['var']);
        $this->assertSame('--fct-btn-radius', $roles['btn']['var']);
        $this->assertSame('--fct-input-radius', $roles['input']['var']);

        foreach ($roles as $role => $definition) {
            $this->assertNotSame('', (string)$definition['label'], "'{$role}' has a label for the settings screen");
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [252]
     *   "A radius is typed as a length: a bare number means pixels, a number
     *    with px, rem or em is kept with its unit, and any other unit or text is
     *    dropped rather than stored."
     */
    public function test_a_bare_number_is_px_and_a_typed_unit_is_kept(): void
    {
        $stored = $this->radiusSanitizer()([
            'card'  => '12',
            'btn'   => ' 1.5EM ',
            'input' => '.5rem',
        ]);

        $this->assertSame(['card' => '12px', 'btn' => '1.5em', 'input' => '0.5rem'], $stored);

        $this->assertSame(['card' => '8px', 'btn' => '12px'], $this->radiusSanitizer()([
            'card' => 8,
            'btn'  => '12px',
        ]), 'a whole number saved by an earlier build still reads as pixels');

        $stored = $this->radiusSanitizer()([
            'card'  => '12pt',
            'btn'   => 'abc',
            'input' => '50%',
        ]);

        $this->assertSame([], $stored, 'units outside px, rem and em are refused');

        $stored = $this->radiusSanitizer()([
            'card'  => ['8'],
            'btn'   => '8; } body { display:none',
            'input' => true,
        ]);

        $this->assertSame([], $stored);
    }

    /**
     * Spec: Cases/storefront-colours.md [253]
     *   "A negative radius is dropped; a large one, like a 9999px pill, is kept."
     */
    public function test_a_negative_radius_is_dropped_and_a_pill_is_kept(): void
    {
        $stored = $this->radiusSanitizer()([
            'card'  => -1,
            'btn'   => '9999px',
            'input' => '-2em',
        ]);

        $this->assertSame(['btn' => '9999px'], $stored);
    }

    /**
     * Spec: Cases/storefront-colours.md [254]
     *   "An unknown radius key is dropped, an empty field means unset, and 0 is
     *    a real value."
     */
    public function test_unknown_keys_are_dropped_empty_is_unset_and_zero_is_kept(): void
    {
        $stored = $this->radiusSanitizer()([
            'card'    => '',
            'btn'     => '0',
            'input'   => 6,
            'popup'   => 12,
            '<b>'     => 4,
        ]);

        $this->assertSame(['btn' => '0px', 'input' => '6px'], $stored);
        $this->assertSame([], $this->radiusSanitizer()('not-an-array'));
        $this->assertSame([], $this->radiusSanitizer()(null));
    }

    /**
     * Spec: Cases/storefront-colours.md [255]
     *   "A stored radius is re-checked on read, so a stale or hand-edited option
     *    never reaches the page."
     */
    public function test_stored_radii_are_rechecked_on_read(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_radius' => [
                'card'  => '12',
                'btn'   => '4px;}body{x:y',
                'input' => '12pt',
                'popup' => 10,
            ],
        ]);

        $this->assertSame(['card' => '12px'], FrontendTheme::getCustomRadii());

        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_radius' => 'garbage',
        ]);

        $this->assertSame([], FrontendTheme::getCustomRadii());
    }

    /**
     * Spec: Cases/storefront-colours.md [256]
     *   "With FluentCart's own look selected, no radius is written, even when
     *    radii are stored."
     */
    public function test_the_default_source_writes_no_radius(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_DEFAULT,
            'appearance_radius' => ['card' => 20, 'btn' => 999, 'input' => 4],
        ]);

        $this->assertSame([], FrontendTheme::getEffectiveRadii());
        $this->assertSame('', FrontendTheme::buildCss(), 'the default look must not move');

        // Nothing stored at all: the default install prints nothing either.
        delete_option(self::OPTION);
        $this->reset();
        $this->assertSame('', FrontendTheme::buildCss());
    }

    /**
     * Spec: Cases/storefront-colours.md [257]
     *   "Customised radii are written in px on their tokens, and an unset role
     *    is not written."
     */
    public function test_customising_writes_only_the_radii_that_were_set(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_radius' => ['card' => 16, 'btn' => 0],
        ]);

        $this->assertSame(['card' => '16px', 'btn' => '0px'], FrontendTheme::getEffectiveRadii());

        $css = FrontendTheme::buildCss();

        $this->assertSame(':root{--fct-card-radius:16px;--fct-btn-radius:0px;}', $css, 'radii print with no colours set');
        $this->assertStringNotContainsString('--fct-input-radius', $css, 'an unset role keeps the stylesheet fallback');
    }

    /**
     * Spec: Cases/storefront-colours.md [258]
     *   "Colours and radii share one :root block, and either prints without
     *    the other."
     */
    public function test_colours_and_radii_share_one_block_and_print_independently(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => ['primary_text_color' => '#111827'],
        ]);

        $this->assertSame(':root{--fct-primary-text-color:#111827;}', FrontendTheme::buildCss(), 'colours print with no radii set');

        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_colors' => ['primary_text_color' => '#111827'],
            'appearance_radius' => ['input' => 6],
        ]);

        $css = FrontendTheme::buildCss();

        $this->assertSame(1, substr_count($css, ':root{'), 'one declaration block, not two');
        $this->assertSame(':root{--fct-primary-text-color:#111827;--fct-input-radius:6px;}', $css);
    }

    /**
     * Spec: Cases/storefront-colours.md [260]
     *   "A filter's radii are re-checked before they are written: unknown roles
     *    and values outside the grammar are dropped."
     */
    public function test_filtered_radii_are_resanitised(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_CUSTOM,
            'appearance_radius' => ['card' => 10],
        ]);

        $seen = null;

        add_filter('fluent_cart/theme/storefront_radii', function ($radii, $context) use (&$seen) {
            $seen = $context;
            $radii['btn'] = '1em';
            $radii['input'] = '4px;}body{display:none';
            $radii['popup'] = '12px';

            return $radii;
        }, 10, 2);

        $this->assertSame(['card' => '10px', 'btn' => '1em'], FrontendTheme::getEffectiveRadii());
        $this->assertSame(['source' => ColorPalette::SOURCE_CUSTOM], $seen, 'the filter is told which source is in force');
        $this->assertSame(':root{--fct-card-radius:10px;--fct-btn-radius:1em;}', FrontendTheme::buildCss());

        remove_all_filters('fluent_cart/theme/storefront_radii');

        add_filter('fluent_cart/theme/storefront_radii', function () {
            return 'not-an-array';
        });

        $this->assertSame(['card' => '10px'], FrontendTheme::getEffectiveRadii(), 'a filter returning garbage falls back to the unfiltered radii');
    }

    /**
     * Spec: Cases/storefront-colours.md [262]
     *   "The radius setting is part of the store settings, empty by default."
     */
    public function test_the_setting_defaults_to_empty(): void
    {
        delete_option(self::OPTION);
        $this->reset();

        $all = (new StoreSettings())->get();

        $this->assertArrayHasKey('appearance_radius', $all, 'the setting is one of the store defaults');
        $this->assertSame([], $all['appearance_radius']);
    }

    /**
     * Spec: Cases/storefront-colours.md [263]
     *   "Saving the appearance settings with a radius the grammar refuses is
     *    rejected with a validation error, instead of saving without it."
     */
    public function test_an_invalid_radius_rejects_the_appearance_save(): void
    {
        // Resolved the way the container resolves it for the controller, so
        // rules() reads the submitted settings_name.
        App::getInstance('request')->merge(['settings_name' => 'appearance', 'appearance_source' => ColorPalette::SOURCE_CUSTOM]);
        $request = new FluentMetaRequest(App::getInstance());
        $request->setRequestInstance(App::getInstance('request'));
        $rules = $request->rules();

        foreach (array_keys(RadiusPalette::roles()) as $role) {
            $this->assertArrayHasKey("appearance_radius.$role", $rules, "the $role radius is validated on save");
        }

        $validate = function (array $radius) use ($rules) {
            return App::make('validator')->make(['appearance_radius' => $radius], $rules);
        };

        $this->assertFalse($validate(['card' => '12', 'btn' => '1.5em', 'input' => '.5rem'])->fails());
        $this->assertFalse($validate(['card' => '', 'btn' => '0', 'input' => '9999px'])->fails(), 'empty is unset, 0 and pills are valid');

        foreach (['12pt', '50%', '-2px', '12pxem', '12 px', '8; } body {x:y}'] as $typed) {
            $this->assertTrue($validate(['btn' => $typed])->fails(), "\"$typed\" is refused on save");
        }

        $failed = $validate(['btn' => '12pt']);
        $failed->fails();

        $this->assertStringContainsString(
            'Button radius: use a number, optionally with px, rem or em.',
            wp_json_encode($failed->errors()),
            'the 422 names the field in words the owner reads'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [264]
     *   "The settings preview shows the theme radii the storefront would print
     *    when inheriting, after the storefront filter."
     */
    public function test_the_preview_shows_the_radii_inheriting_would_print(): void
    {
        $this->store(['appearance_source' => ColorPalette::SOURCE_DEFAULT]);

        add_filter('fluent_cart/theme/radius_roles', function () {
            return ['btn' => '6px'];
        });
        add_filter('fluent_cart/theme/storefront_radii', function ($radii, $context) {
            if ($context['source'] === ColorPalette::SOURCE_THEME) {
                $radii['card'] = '20px';
            }
            return $radii;
        }, 10, 2);

        $this->assertSame(['card' => '20px', 'btn' => '6px'], FrontendTheme::radiiFor(ColorPalette::SOURCE_THEME));

        $preview = new \ReflectionMethod(StoreSettings::class, 'getAppearanceThemeRadii');
        $preview->setAccessible(true);

        $this->assertSame(
            FrontendTheme::radiiFor(ColorPalette::SOURCE_THEME),
            $preview->invoke(new StoreSettings()),
            'whatever source is saved, the inherit preview matches what inheriting prints'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [266]
     *   "The settings form never receives a stored radius the grammar refuses:
     *    a stale or hand-edited value is dropped before the form loads."
     */
    public function test_the_settings_form_loads_only_valid_radii(): void
    {
        $this->store([
            'appearance_source' => ColorPalette::SOURCE_DEFAULT,
            'appearance_radius' => ['card' => '12pt', 'btn' => '6', 'input' => '1em'],
        ]);

        $values = (new StoreSettings())->getForEditing();

        $this->assertSame(['btn' => '6px', 'input' => '1em'], $values['appearance_radius']);
        $this->assertSame(ColorPalette::SOURCE_DEFAULT, $values['appearance_source'], 'only the radii are touched');
    }

    /**
     * Spec: Cases/storefront-colours.md [267]
     *   "The radius fields are only validated on save while Customize is
     *    selected; under another source they are hidden, so a hidden value
     *    never blocks the save."
     */
    public function test_hidden_radii_are_not_validated_outside_customize(): void
    {
        foreach ([ColorPalette::SOURCE_DEFAULT, ColorPalette::SOURCE_THEME] as $source) {
            App::getInstance('request')->merge(['settings_name' => 'appearance', 'appearance_source' => $source]);
            $request = new FluentMetaRequest(App::getInstance());
            $request->setRequestInstance(App::getInstance('request'));

            $this->assertArrayNotHasKey('appearance_radius.btn', $request->rules(), "no radius rule under $source");
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [259]
     *   "Inheriting writes the radii the active theme states and nothing it
     *    does not."
     */
    public function test_inheriting_writes_the_radii_the_theme_states(): void
    {
        add_filter('fluent_cart/theme/radius_roles', function () {
            return ['btn' => '0.5rem'];
        });

        $this->store([
            'appearance_source' => ColorPalette::SOURCE_THEME,
            'appearance_radius' => ['card' => 20],
        ]);

        $radii = FrontendTheme::getEffectiveRadii();

        $this->assertSame('0.5rem', $radii['btn'] ?? '', 'the theme\'s button radius is inherited');
        $this->assertArrayNotHasKey('card', $radii, 'the owner\'s customised radius is not used while inheriting');
        $this->assertStringContainsString('--fct-btn-radius:0.5rem;', FrontendTheme::buildCss());
    }
}
