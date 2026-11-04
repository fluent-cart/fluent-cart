<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Money;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Lives in Integration rather than Unit because the rejection message goes
 * through esc_html(), which needs WordPress loaded.
 */
class MoneyWireFormatTest extends WPTestCase
{
    public function test_wire_format_rejects_three_decimal_currencies(): void
    {
        // Storage is *100, so a 3-decimal currency cannot round-trip: refusing
        // loudly beats a silent 10x undercharge.
        $this->expectException(\Exception::class);
        Money::formatWireAmount(740000, 'JOD');
    }
}
