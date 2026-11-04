<?php

namespace Tests\Unit\Helpers;

use Codeception\Test\Unit;
use FluentCart\App\Helpers\Helper;

class MoneyHelperTest extends Unit
{
    public function test_to_cent_converts_decimal_amount(): void
    {
        $this->assertSame(1234, Helper::toCent(12.34), '12.34 should convert to 1234 cents');
        $this->assertSame(1234, Helper::toCent('12.34'), 'numeric string should convert the same way');
    }

    public function test_to_cent_rounds_to_nearest_cent(): void
    {
        $this->assertSame(1235, Helper::toCent(12.345), '12.345 should round up to 1235 cents');
        $this->assertSame(1000, Helper::toCent(9.999), '9.999 should round up to 1000 cents');
    }

    public function test_to_cent_handles_float_precision_boundary(): void
    {
        $this->assertSame(1999, Helper::toCent(19.99), '19.99 must not lose a cent to float representation');
        $this->assertSame(2999, Helper::toCent(29.99), '29.99 must not lose a cent to float representation');
    }

    // x.xx5 * 100 lands just below .5 in binary; PHP 8.4's round() no longer pre-rounds that away
    public function test_to_cent_rounds_decimal_half_cents_away_from_zero_on_every_php_version(): void
    {
        $this->assertSame(101, Helper::toCent('1.005'), '1.005 is a half cent and rounds up');
        $this->assertSame(-101, Helper::toCent('-1.005'), '-1.005 rounds away from zero');
        $this->assertSame(15, Helper::toCent(0.145), '0.145 must not round down to 14');
        $this->assertSame(29, Helper::toCent('0.285'), '0.285 must not round down to 28');
        $this->assertSame(268, Helper::toCent(2.675), '2.675 must not round down to 267');
        $this->assertSame(100, Helper::toCent('1.004'), 'below half a cent still rounds down');
    }

    public function test_to_cent_returns_zero_for_non_numeric_input(): void
    {
        $this->assertSame(0, Helper::toCent('abc'), 'non-numeric input should yield 0 cents');
        $this->assertSame(0, Helper::toCent(''), 'empty string should yield 0 cents');
        $this->assertSame(0, Helper::toCent(null), 'null should yield 0 cents');
    }

    public function test_round_cent_does_not_scale_an_already_cents_value(): void
    {
        $this->assertSame(1999, Helper::roundCent(1999), 'a whole-cent int must pass through unchanged — roundCent normalizes, it does not scale like toCent');
        $this->assertSame(1999, Helper::roundCent('1999'), 'a numeric string of cents must normalize to the same int');
        $this->assertSame(0, Helper::roundCent(0), 'zero cents must stay zero');
    }

    public function test_round_cent_rounds_float_artifacts_instead_of_truncating(): void
    {
        // What JavaScript produces for 19.99 * 100 and 8.20 * 100. A bare (int)
        // cast truncates these and silently undercharges by a cent.
        $this->assertSame(1999, Helper::roundCent(1998.9999999999998), '1998.9999999999998 must round to 1999, not truncate to 1998');
        $this->assertSame(820, Helper::roundCent(819.9999999999999), '819.9999999999999 must round to 820, not truncate to 819');
        $this->assertSame(115, Helper::roundCent(114.99999999999999), '114.99999999999999 must round to 115, not truncate to 114');
    }

    public function test_round_cent_rounds_half_up_and_below_half_down(): void
    {
        $this->assertSame(1999, Helper::roundCent(1999.4), 'below half must round down');
        $this->assertSame(2000, Helper::roundCent(1999.5), 'half must round up');
        // 2000.5 discriminates half-up from banker's rounding: the base is EVEN, so
        // PHP_ROUND_HALF_EVEN would keep 2000 and silently lose the cent. 1999.5
        // cannot tell them apart (2000 is the half-up AND half-even answer).
        $this->assertSame(2001, Helper::roundCent(2000.5), 'an even-base half must still round UP — banker\'s rounding would keep 2000 and lose a cent');
    }

    public function test_round_cent_returns_zero_for_non_numeric_input(): void
    {
        // null is the case the request pipeline creates: pickKeys() injects it for
        // every omitted sanitize-map key, after the sanitizers have already run.
        $this->assertSame(0, Helper::roundCent(null), 'null must yield 0 — this is the omitted-key case for a NOT NULL cents column');
        $this->assertSame(0, Helper::roundCent(''), 'empty string must yield 0 cents');
        $this->assertSame(0, Helper::roundCent('abc'), 'non-numeric input must yield 0 cents');
        $this->assertSame(0, Helper::roundCent([]), 'an array must yield 0 rather than raising a conversion notice');
    }
}
