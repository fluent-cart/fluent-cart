<?php

namespace Tests\Unit\Modules\MultiCurrency;

use Codeception\Test\Unit;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;

/**
 * Rates are stored as 12-decimal strings so a value read back from
 * DECIMAL(24,12) compares equal to the one written
 * (dev-docs/multi-currency/08-exchange-rates.md, "Meaning and storage").
 */
class CurrencySnapshotRateTest extends Unit
{
    public function test_numeric_strings_are_rescaled_without_a_float_round_trip(): void
    {
        $this->assertSame('0.790000000000', CurrencySnapshot::normalizeRate('0.79'));
        $this->assertSame('149.500000000000', CurrencySnapshot::normalizeRate('149.5'));
        $this->assertSame('1.000000000000', CurrencySnapshot::normalizeRate('1'));
        $this->assertSame('0.000061234567', CurrencySnapshot::normalizeRate('0.000061234567'));
        $this->assertSame('16391.803278688525', CurrencySnapshot::normalizeRate('16391.803278688525'), 'IDR-sized rates keep all 12 decimals');
        $this->assertSame('0.790000000000', CurrencySnapshot::normalizeRate('000.79'));
    }

    public function test_floats_and_ints_are_formatted_to_twelve_decimals(): void
    {
        $this->assertSame('1.000000000000', CurrencySnapshot::normalizeRate(1));
        $this->assertSame('0.790000000000', CurrencySnapshot::normalizeRate(0.79));
    }

    public function test_rate_validity(): void
    {
        $this->assertTrue(CurrencySnapshot::isValidRate('0.79'));
        $this->assertTrue(CurrencySnapshot::isValidRate(149.5));
        $this->assertFalse(CurrencySnapshot::isValidRate(0));
        $this->assertFalse(CurrencySnapshot::isValidRate('-1'));
        $this->assertFalse(CurrencySnapshot::isValidRate(''));
        $this->assertFalse(CurrencySnapshot::isValidRate(null));
        $this->assertFalse(CurrencySnapshot::isValidRate('abc'));
    }

    public function test_only_unknown_sources_lack_a_trusted_rate(): void
    {
        $this->assertTrue(CurrencySnapshot::hasTrustedRate(['source' => CurrencySnapshot::SOURCE_BASE]));
        $this->assertTrue(CurrencySnapshot::hasTrustedRate(['source' => CurrencySnapshot::SOURCE_MANUAL]));
        $this->assertTrue(CurrencySnapshot::hasTrustedRate(['source' => CurrencySnapshot::SOURCE_LEGACY_BASE]));
        $this->assertFalse(CurrencySnapshot::hasTrustedRate(['source' => CurrencySnapshot::SOURCE_UNKNOWN]));
        $this->assertFalse(CurrencySnapshot::hasTrustedRate(['source' => CurrencySnapshot::SOURCE_LEGACY_UNKNOWN]));
    }
}
