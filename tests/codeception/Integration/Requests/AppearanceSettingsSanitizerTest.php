<?php

namespace Tests\Integration\Requests;

use FluentCart\App\App;
use FluentCart\App\Http\Requests\FluentMetaRequest;
use FluentCart\App\Services\Theme\ColorPalette;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The save boundary for the Appearance settings.
 *
 * Whatever survives here is written into a style element on every storefront
 * page, so the sanitizer is the place a bad value has to stop. It works off the
 * registry rather than off the submitted keys, which is what stops an
 * unrecognised property being stored at all.
 */
class AppearanceSettingsSanitizerTest extends WPTestCase
{
    /**
     * Spec: Cases/storefront-colours.md [31]
     *   "An unknown colour source falls back to FluentCart's own colours."
     */
    public function test_an_unknown_source_falls_back_to_fluentcart_colours(): void
    {
        $sanitize = (new FluentMetaRequest(App::getInstance()))->sanitize();
        $source = $sanitize['appearance_source'];

        foreach (ColorPalette::sources() as $valid) {
            $this->assertSame($valid, $source($valid), "'{$valid}' is a source the feature offers");
        }

        foreach (['bogus', '<script>', '', 'DEFAULT'] as $invalid) {
            $this->assertSame(
                ColorPalette::SOURCE_DEFAULT,
                $source($invalid),
                'an unrecognised source must not leave the store in an undefined state, got ' . var_export($invalid, true)
            );
        }
    }

    /**
     * Spec: Cases/storefront-colours.md [32]
     *   "A submitted colour that is not a hex value is dropped rather than
     *    stored."
     */
    public function test_a_submitted_value_that_is_not_a_hex_colour_is_dropped(): void
    {
        $sanitize = (new FluentMetaRequest(App::getInstance()))->sanitize();
        $colors = $sanitize['appearance_colors'];

        $stored = $colors([
            'btn_bg_color'       => '#AABBCC',
            'primary_text_color' => '#abc',
            'border_color'       => 'red; } body { display:none',
            'divider_color'      => 'javascript:alert(1)',
            'input_bg_color'     => 'var(--evil)',
            'secondary_bg_color' => 'rgb(1,2,3)',
        ]);

        $this->assertSame('#AABBCC', $stored['btn_bg_color'], 'a six-digit hex is stored');
        $this->assertSame('#abc', $stored['primary_text_color'], 'a shorthand hex is stored');
        $this->assertArrayNotHasKey('border_color', $stored, 'a declaration breakout is dropped');
        $this->assertArrayNotHasKey('divider_color', $stored, 'a javascript url is dropped');
        $this->assertArrayNotHasKey('input_bg_color', $stored, 'a variable reference is dropped');
        // sanitize_hex_color() is hex-only by design; rgb() is legal CSS but is
        // not what the picker submits, so it is refused rather than converted.
        $this->assertArrayNotHasKey('secondary_bg_color', $stored, 'only hex reaches the option');
    }

    /**
     * Spec: Cases/storefront-colours.md [33]
     *   "A submitted key that is not in the registry is dropped rather than
     *    stored."
     */
    public function test_a_key_outside_the_registry_is_dropped(): void
    {
        $sanitize = (new FluentMetaRequest(App::getInstance()))->sanitize();
        $colors = $sanitize['appearance_colors'];

        $stored = $colors([
            'btn_bg_color'         => '#AABBCC',
            'not_a_registry_key'   => '#123456',
            '--fct-injected-thing' => '#123456',
        ]);

        $this->assertSame(['btn_bg_color' => '#AABBCC'], $stored, 'the registry decides which keys exist, not the request');
        $this->assertSame([], $colors('not-an-array'), 'a non-array submission stores nothing');
    }

    /**
     * Spec: Cases/storefront-colours.md [34]
     *   "Clearing a colour picker removes the colour instead of storing an
     *    empty one."
     */
    public function test_clearing_a_picker_removes_the_colour(): void
    {
        // An empty string stored as a value would be written as
        // `--fct-btn-bg-color:;` — an invalid declaration. Absence is how a
        // colour falls back to FluentCart's own default.
        $sanitize = (new FluentMetaRequest(App::getInstance()))->sanitize();
        $colors = $sanitize['appearance_colors'];

        $stored = $colors([
            'btn_bg_color'       => '',
            'primary_text_color' => '#111827',
        ]);

        $this->assertArrayNotHasKey('btn_bg_color', $stored, 'a cleared picker is an absent colour');
        $this->assertSame('#111827', $stored['primary_text_color'], 'the colour still set is unaffected');
    }
}
