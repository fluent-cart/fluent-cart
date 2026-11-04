<?php

namespace Tests\Unit\Services\Theme;

use Codeception\Test\Unit;
use FluentCart\App\Services\Theme\ColorMath;

/**
 * The colour maths behind theme inheritance.
 *
 * A theme palette supplies a surface, a body text tone and an accent. The
 * storefront also needs hairline borders, dividers, muted captions, hover tints
 * and placeholder text, which no theme declares. Those are mixed out of the
 * colours the theme did give us, and button text is chosen by contrast rather
 * than assumed to be white — so these boundaries decide whether an inherited
 * palette is readable or not.
 */
class ColorMathTest extends Unit
{
    /**
     * Spec: Cases/storefront-colours.md [1]
     *   "A six-digit hex colour is read as its red, green and blue channels."
     */
    public function test_a_six_digit_hex_is_read_as_rgb_channels(): void
    {
        $this->assertSame([170, 187, 204], ColorMath::parse('#aabbcc'), 'each pair is one channel');
    }

    /**
     * Spec: Cases/storefront-colours.md [2]
     *   "A three-digit hex shorthand means the same colour as its long form."
     */
    public function test_a_three_digit_hex_expands_to_its_long_form(): void
    {
        $this->assertSame(
            ColorMath::parse('#aabbcc'),
            ColorMath::parse('#abc'),
            'shorthand doubles each digit, so #abc and #aabbcc are one colour'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [3]
     *   "An eight-digit hex drops the alpha channel rather than being refused."
     */
    public function test_an_eight_digit_hex_drops_the_alpha_channel(): void
    {
        // A plain custom property cannot carry alpha, and refusing the colour
        // would silently drop a palette entry the theme really does publish.
        $this->assertSame([17, 34, 51], ColorMath::parse('#11223344'), 'the trailing alpha pair is ignored, not rejected');
    }

    /**
     * Spec: Cases/storefront-colours.md [4]
     *   "An rgb() colour is read, so a palette written that way is not discarded."
     */
    public function test_an_rgb_colour_is_read(): void
    {
        $this->assertSame([1, 2, 3], ColorMath::parse('rgb(1, 2, 3)'), 'theme.json is not restricted to hex');
        $this->assertSame([1, 2, 3], ColorMath::parse('rgba(1 2 3 / 0.5)'), 'the space-separated rgba form is the same colour');
    }

    /**
     * Spec: Cases/storefront-colours.md [5]
     *   "A colour that cannot be read returns nothing, so callers fall back
     *    instead of guessing."
     */
    public function test_an_unreadable_colour_is_refused_rather_than_guessed(): void
    {
        $this->assertNull(ColorMath::parse('var(--ast-global-color-0)'), 'a variable reference is not a colour we can resolve');
        $this->assertNull(ColorMath::parse('linear-gradient(#fff, #000)'), 'a gradient is not a colour');
        $this->assertNull(ColorMath::parse(''), 'an empty value is not a colour');
        $this->assertSame('', ColorMath::hex('nonsense'), 'hex() reports the failure as an empty string by default');
        $this->assertSame('#123456', ColorMath::hex('nonsense', '#123456'), 'the caller chooses what to fall back to');
    }

    /**
     * Spec: Cases/storefront-colours.md [6]
     *   "Mixing a colour with itself returns that colour."
     */
    public function test_mixing_a_colour_with_itself_is_that_colour(): void
    {
        $this->assertSame('#3a7bd5', ColorMath::mix('#3a7bd5', '#3a7bd5', 37), 'no ratio of a colour with itself changes it');
    }

    /**
     * Spec: Cases/storefront-colours.md [7]
     *   "Mixing keeps all of the first colour at 100% and none of it at 0%."
     */
    public function test_mixing_at_the_extremes_returns_each_end(): void
    {
        $this->assertSame('#ffffff', ColorMath::mix('#ffffff', '#000000', 100), '100% keeps all of the first colour');
        $this->assertSame('#000000', ColorMath::mix('#ffffff', '#000000', 0), '0% keeps none of the first colour');
        $this->assertSame('#808080', ColorMath::mix('#ffffff', '#000000', 50), 'an even mix of black and white is mid grey');
    }

    /**
     * Spec: Cases/storefront-colours.md [8]
     *   "A mix percentage outside 0-100 is clamped instead of producing an
     *    impossible channel."
     */
    public function test_an_out_of_range_mix_percentage_is_clamped(): void
    {
        $this->assertSame(
            ColorMath::mix('#ffffff', '#000000', 100),
            ColorMath::mix('#ffffff', '#000000', 400),
            'over 100% cannot mean more than all of the first colour'
        );
        $this->assertSame(
            ColorMath::mix('#ffffff', '#000000', 0),
            ColorMath::mix('#ffffff', '#000000', -50),
            'under 0% cannot mean less than none of the first colour'
        );
    }

    /**
     * Spec: Cases/storefront-colours.md [9]
     *   "Mixing with an unreadable colour falls back to whichever colour could
     *    be read."
     */
    public function test_mixing_with_an_unreadable_colour_falls_back(): void
    {
        $this->assertSame('#3a7bd5', ColorMath::mix('#3a7bd5', 'not-a-colour', 50), 'the readable colour is kept');
        $this->assertSame('#3a7bd5', ColorMath::mix('not-a-colour', '#3a7bd5', 50), 'whichever side it was given on');
    }

    /**
     * Spec: Cases/storefront-colours.md [10]
     *   "The contrast between a colour and itself is the lowest ratio there is."
     */
    public function test_a_colour_has_the_minimum_contrast_with_itself(): void
    {
        $this->assertEqualsWithDelta(1.0, ColorMath::contrast('#3a7bd5', '#3a7bd5'), 0.0001, 'the WCAG scale bottoms out at 1');
    }

    /**
     * Spec: Cases/storefront-colours.md [11]
     *   "Black on white is the highest contrast there is."
     */
    public function test_black_on_white_is_the_maximum_contrast(): void
    {
        $this->assertEqualsWithDelta(21.0, ColorMath::contrast('#000000', '#ffffff'), 0.0001, 'the WCAG scale tops out at 21');
    }

    /**
     * Spec: Cases/storefront-colours.md [12]
     *   "Contrast is the same whichever order the two colours are given in."
     */
    public function test_contrast_does_not_depend_on_argument_order(): void
    {
        $this->assertEqualsWithDelta(
            ColorMath::contrast('#112233', '#ffe2c7'),
            ColorMath::contrast('#ffe2c7', '#112233'),
            0.0001,
            'contrast is a property of the pair, not of which is called the background'
        );
        $this->assertSame(0.0, ColorMath::contrast('#112233', 'not-a-colour'), 'an unreadable colour has no contrast to report');
    }

    /**
     * Spec: Cases/storefront-colours.md [13]
     *   "Button text is light on a dark button and dark on a light one."
     */
    public function test_button_text_is_chosen_for_contrast(): void
    {
        $this->assertSame('#ffffff', ColorMath::readableOn('#111111'), 'light text on a near-black button');
        $this->assertSame('#1f2937', ColorMath::readableOn('#f9f9f9'), 'dark text on a near-white button');
        // The case that makes this worth computing: a pale brand colour is
        // unreadable under a white-by-default assumption.
        $this->assertSame('#1f2937', ColorMath::readableOn('#ffe2c7'), 'a pale accent needs dark text, not white');
    }

    /**
     * Spec: Cases/storefront-colours.md [14]
     *   "A colour that cannot be read is not treated as dark."
     */
    public function test_an_unreadable_colour_is_not_reported_as_dark(): void
    {
        $this->assertTrue(ColorMath::isDark('#000000'), 'black is dark');
        $this->assertFalse(ColorMath::isDark('#ffffff'), 'white is not dark');
        // luminance() reports -1 for an unreadable colour; that must not slip
        // past the lower bound and flip a palette to its dark-surface branch.
        $this->assertFalse(ColorMath::isDark('not-a-colour'), 'unknown is not the same as dark');
    }

    /**
     * Spec: Cases/storefront-colours.md [15]
     *   "Moving a colour away from a light surface darkens it, and away from a
     *    dark surface lightens it."
     */
    public function test_moving_away_from_a_surface_follows_that_surface(): void
    {
        $onLight = ColorMath::awayFrom('#808080', '#ffffff', 20);
        $onDark = ColorMath::awayFrom('#808080', '#000000', 20);

        $this->assertLessThan(
            ColorMath::luminance('#808080'),
            ColorMath::luminance($onLight),
            'against a light surface the colour must get darker to stay visible'
        );
        $this->assertGreaterThan(
            ColorMath::luminance('#808080'),
            ColorMath::luminance($onDark),
            'against a dark surface it must get lighter instead'
        );
    }
}
