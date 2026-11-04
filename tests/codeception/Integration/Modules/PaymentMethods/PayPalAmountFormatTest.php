<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Regression tests for PayPal amount formatting.
 *
 * Root cause: Helper::toDecimalWithoutComma() returns a PHP float. When
 * json_encode() serializes a float with a non-default serialize_precision
 * ini setting it can produce a long decimal string like
 * "6.36000000000000031974..." which PayPal rejects with INVALID_STRING_LENGTH.
 *
 * Fix: wrap float values in number_format(..., 2, '.', '') at every PayPal
 * API array assignment so json_encode serializes a string, not a float.
 */
class PayPalAmountFormatTest extends WPTestCase
{
    // =========================================================================
    // Helper::toDecimalWithoutComma — verifies the base converter
    // =========================================================================

    public function test_to_decimal_without_comma_returns_float(): void
    {
        $result = Helper::toDecimalWithoutComma(636);
        $this->assertIsFloat($result);
    }

    public function test_to_decimal_without_comma_converts_cents_correctly(): void
    {
        $this->assertEqualsWithDelta(6.36, Helper::toDecimalWithoutComma(636), 0.001);
        $this->assertEqualsWithDelta(0.0, Helper::toDecimalWithoutComma(0), 0.001);
        $this->assertEqualsWithDelta(1.00, Helper::toDecimalWithoutComma(100), 0.001);
        $this->assertEqualsWithDelta(1234.56, Helper::toDecimalWithoutComma(123456), 0.001);
    }

    public function test_to_decimal_without_comma_returns_zero_for_non_numeric(): void
    {
        $this->assertSame(0, Helper::toDecimalWithoutComma('invalid'));
        $this->assertSame(0, Helper::toDecimalWithoutComma(''));
    }

    // =========================================================================
    // PayPal amount format — the fix: number_format wrapping
    // =========================================================================

    /**
     * @dataProvider centAmountProvider
     */
    public function test_paypal_amount_format_is_valid_two_decimal_string(int $cents, string $expected): void
    {
        $formatted = number_format(Helper::toDecimalWithoutComma($cents), 2, '.', '');

        $this->assertIsString($formatted);
        $this->assertSame($expected, $formatted);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $formatted);
    }

    public function centAmountProvider(): array
    {
        return [
            'zero'                    => [0,      '0.00'],
            'one dollar'              => [100,    '1.00'],
            '6.36 — original bug'     => [636,    '6.36'],
            '23.35'                   => [2335,   '23.35'],
            'large amount'            => [123456, '1234.56'],
            'round dollar'            => [5000,   '50.00'],
            'odd cents'               => [999,    '9.99'],
            'max typical order'       => [99999,  '999.99'],
        ];
    }

    /**
     * Verify json_encode of the formatted string produces a compact JSON string,
     * not a long float representation — regardless of serialize_precision setting.
     *
     * @dataProvider centAmountProvider
     */
    public function test_paypal_amount_json_encodes_to_compact_string(int $cents, string $expected): void
    {
        $value = number_format(Helper::toDecimalWithoutComma($cents), 2, '.', '');
        $json  = json_encode(['value' => $value]);

        $this->assertSame('{"value":"' . $expected . '"}', $json);
    }

    // =========================================================================
    // Arithmetic accumulation still works with float intermediate values
    // =========================================================================

    public function test_accumulation_then_format_produces_correct_total(): void
    {
        // Simulate Processor.php: accumulate floats, then format at API boundary.
        $perQuantity   = Helper::toDecimalWithoutComma(318); // 3.18
        $quantity      = 2;
        $itemsSubTotal = $perQuantity * $quantity; // 6.36 as float

        $formatted = number_format($itemsSubTotal, 2, '.', '');

        $this->assertSame('6.36', $formatted);
        $this->assertSame('{"value":"6.36"}', json_encode(['value' => $formatted]));
    }

    public function test_signup_fee_plus_recurring_formats_correctly(): void
    {
        // Simulate PayPalHelper.php line 313: string + string → float → format.
        $recurringAmount = number_format(2000 / 100, 2, '.', ''); // "20.00"
        $initialAmount   = number_format(636 / 100, 2, '.', '');  // "6.36"

        $combined  = number_format($initialAmount + $recurringAmount, 2, '.', '');

        $this->assertSame('26.36', $combined);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $combined);
    }
}
