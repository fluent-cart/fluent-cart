<?php

namespace Tests\Unit\Services;

use Codeception\Test\Unit;
use FluentCart\App\Services\Money;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalHelper;

/**
 * Money is the single owner of the "how many decimals does this currency use"
 * question. Storage is ALWAYS cents (value * 100) for every currency including
 * zero-decimal ones — a ¥7,400 order is stored 740000. Decimals only decide
 * display and wire formatting, never storage scale.
 */
class MoneyTest extends Unit
{
    public function test_display_decimals_come_from_the_currency_itself(): void
    {
        $this->assertSame(0, Money::getDecimals('JPY'), 'JPY is zero-decimal');
        $this->assertSame(0, Money::getDecimals('jpy'), 'lookup is case-insensitive');
        $this->assertSame(0, Money::getDecimals('KRW'), 'KRW is zero-decimal');
        $this->assertSame(2, Money::getDecimals('USD'), 'USD uses 2 decimals');
        $this->assertSame(2, Money::getDecimals('EUR'), 'EUR uses 2 decimals');
        $this->assertSame(3, Money::getDecimals('BHD'), 'BHD is a 3-decimal currency');
        $this->assertSame(3, Money::getDecimals('JOD'), 'JOD is a 3-decimal currency');
        $this->assertSame(2, Money::getDecimals('XYZ'), 'unknown currencies fall back to 2');
    }

    public function test_wire_format_defaults_to_two_decimals_regardless_of_display_convention(): void
    {
        // TWD displays with 0 decimals locally, but every gateway that accepts
        // TWD (e.g. Mollie) wants "74.00"-style values — wire format only obeys
        // the gateway's own zero-decimal table, never the display table.
        $this->assertSame('39.95', Money::formatWireAmount(3995, 'GBP'));
        $this->assertSame('7400.00', Money::formatWireAmount(740000, 'TWD', ['ISK', 'JPY']));
    }

    public function test_wire_format_zero_decimal_per_gateway_table(): void
    {
        $paypalZeroDecimals = ['HUF', 'JPY', 'TWD'];

        $this->assertSame('7400', Money::formatWireAmount(740000, 'JPY', $paypalZeroDecimals), 'a stored 740000 JPY must go on the wire as 7400');
        $this->assertSame('7400', Money::formatWireAmount(740000, 'HUF', $paypalZeroDecimals));
        $this->assertSame('7400', Money::formatWireAmount(740000, 'jpy', $paypalZeroDecimals), 'currency match is case-insensitive');
    }

    public function test_parse_wire_amount_always_scales_by_100(): void
    {
        // An inbound wire amount is a real-value decimal for EVERY currency
        // ("7400" yen, "39.95" pounds) — storage is *100 across the board, so
        // parsing never branches on currency.
        $this->assertSame(740000, Money::parseWireAmount('7400'));
        $this->assertSame(3995, Money::parseWireAmount('39.95'));
        $this->assertSame(3995, Money::parseWireAmount(39.95));
        $this->assertSame(2000, Money::parseWireAmount('19.999999'), 'float artifacts round, not truncate');
        $this->assertSame(0, Money::parseWireAmount('not-a-number'));
    }

    public function test_paypal_formats_wire_amounts_with_its_own_zero_decimal_table(): void
    {
        // PayPal's wire formatting is owned by PayPalHelper::formatAmount() (#2762)
        $this->assertSame('7400', PayPalHelper::formatAmount(740000, 'JPY'), 'PayPal rejects decimals on JPY — stored 740000 must be sent as "7400"');
        $this->assertSame('7400', PayPalHelper::formatAmount(740000, 'HUF'), 'PayPal treats HUF as zero-decimal');
        $this->assertSame('7400', PayPalHelper::formatAmount(740000, 'TWD'), 'PayPal treats TWD as zero-decimal');
        $this->assertSame('39.95', PayPalHelper::formatAmount(3995, 'GBP'));
        $this->assertSame('19.99', PayPalHelper::formatAmount(1999, 'USD'));
    }
}
