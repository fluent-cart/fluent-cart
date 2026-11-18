<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPal;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * PayPal rejects a decimal amount for HUF, JPY and TWD ("This currency does not
 * support decimals. If you pass a decimal amount, an error occurs." —
 * developer.paypal.com/api/rest/reference/currency-codes). Every PayPal payload
 * amount used to be hardcoded to two decimals, so a JPY checkout failed at
 * order create.
 */
class PayPalZeroDecimalCurrencyTest extends WPTestCase
{
    public function test_zero_decimal_set_matches_paypal_documentation(): void
    {
        // Exact set assertion — PayPal's own list, not ISO 4217 and not the
        // store-wide CurrenciesHelper::zeroDecimalCurrencies() set.
        $this->assertSame(['HUF', 'JPY', 'TWD'], PayPalHelper::ZERO_DECIMAL_CURRENCIES);
    }

    public function test_supported_set_matches_paypal_documentation(): void
    {
        $documented = [
            'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN',
            'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
        ];
        $supported = PayPal::getPaypalSupportedCurrency();
        sort($documented);
        sort($supported);

        $this->assertSame($documented, $supported);
        $this->assertNotContains('COP', $supported);
    }

    public function test_every_zero_decimal_currency_is_a_supported_currency(): void
    {
        foreach (PayPalHelper::ZERO_DECIMAL_CURRENCIES as $currency) {
            $this->assertContains($currency, PayPal::getPaypalSupportedCurrency());
        }
    }

    /**
     * @dataProvider decimalsProvider
     */
    public function test_currency_decimals_per_currency(string $currency, int $expected): void
    {
        $this->assertSame($expected, PayPalHelper::currencyDecimals($currency));
    }

    public function decimalsProvider(): array
    {
        return [
            'JPY'            => ['JPY', 0],
            'HUF'            => ['HUF', 0],
            'TWD'            => ['TWD', 0],
            'lowercase jpy'  => ['jpy', 0],
            'USD'            => ['USD', 2],
            'EUR'            => ['EUR', 2],
            // ISO-zero-decimal but NOT zero-decimal at PayPal
            'KRW'            => ['KRW', 2],
            'CLP'            => ['CLP', 2],
        ];
    }

    /**
     * @dataProvider amountProvider
     */
    public function test_format_amount(int $cents, string $currency, string $expected): void
    {
        $this->assertSame($expected, PayPalHelper::formatAmount($cents, $currency));
    }

    public function amountProvider(): array
    {
        return [
            // Internal storage is x100 for every currency, zero-decimal included:
            // 100000 internal is 1000 yen, not 100000 yen.
            'JPY 1000 yen'   => [100000, 'JPY', '1000'],
            'JPY zero'       => [0, 'JPY', '0'],
            'HUF 2500'       => [250000, 'HUF', '2500'],
            'TWD 990'        => [99000, 'TWD', '990'],
            'USD 10.00'      => [1000, 'USD', '10.00'],
            'USD 6.36'       => [636, 'USD', '6.36'],
            'EUR 1234.56'    => [123456, 'EUR', '1234.56'],
        ];
    }

    public function test_zero_decimal_amount_carries_no_decimal_separator(): void
    {
        $value = PayPalHelper::formatAmount(100000, 'JPY');

        $this->assertStringNotContainsString('.', $value);
        $this->assertSame('{"value":"1000"}', json_encode(['value' => $value]));
    }

    public function test_amount_rounds_before_breakdown_arithmetic(): void
    {
        // A taxed JPY line can leave a sub-yen internal remainder. Components
        // must be rounded to the currency's precision BEFORE they are summed,
        // otherwise the formatted breakdown no longer adds up to the formatted
        // total and PayPal rejects the order.
        $itemTotal = PayPalHelper::toDecimalAmount(100050, 'JPY'); // 1000.50 -> 1001
        $tax       = PayPalHelper::toDecimalAmount(10050, 'JPY');  //  100.50 ->  101

        $this->assertSame(1001.0, $itemTotal);
        $this->assertSame(101.0, $tax);
        $this->assertSame('1102', PayPalHelper::formatDecimalAmount($itemTotal + $tax, 'JPY'));
    }

    public function test_plan_pricing_uses_zero_decimal_value_for_jpy(): void
    {
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 500000, // 5000 yen
        ]);

        $this->assertSame('5000', $this->regularCyclePrice($plan));
    }

    public function test_plan_pricing_keeps_two_decimals_for_usd(): void
    {
        $plan = $this->buildPlanArgs([
            'currency'         => 'USD',
            'recurring_amount' => 500000,
        ]);

        $this->assertSame('5000.00', $this->regularCyclePrice($plan));
    }

    public function test_jpy_trial_cycle_price_has_no_decimals(): void
    {
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 500000,
            'trial_days'       => 14,
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('0', $trial['pricing_scheme']['fixed_price']['value']);
    }

    public function test_jpy_signup_fee_cycle_sums_in_decimal_amounts(): void
    {
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 500000, // 5000 yen
            'signup_fee'       => 100000, //  1000 yen
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('6000', $trial['pricing_scheme']['fixed_price']['value']);
    }

    /**
     * @dataProvider wireCentsProvider
     */
    public function test_wire_cents_is_the_amount_paypal_will_actually_move(int $cents, string $currency, int $expected): void
    {
        $this->assertSame($expected, PayPalHelper::wireCents($cents, $currency));
    }

    public function wireCentsProvider(): array
    {
        return [
            // 2-decimal currencies: identity, the guards are untouched.
            'USD 10.00'            => [1000, 'USD', 1000],
            'USD 6.36'             => [636, 'USD', 636],
            'EUR 1234.56'          => [123456, 'EUR', 123456],
            // Zero-decimal: storage keeps the x100 residue, the wire cannot.
            'JPY whole yen'        => [100000, 'JPY', 100000],
            'JPY 1000.50 rounds up'=> [100050, 'JPY', 100100],
            'JPY 1000.40 rounds down' => [100040, 'JPY', 100000],
            'JPY zero'             => [0, 'JPY', 0],
            'HUF 2500.75'          => [250075, 'HUF', 250100],
            'TWD 990.20'           => [99020, 'TWD', 99000],
        ];
    }

    /**
     * The invariant every amount-verification guard depends on: what we format
     * onto the wire, read back the way PayPal reports it, must equal wireCents().
     *
     * @dataProvider wireCentsProvider
     */
    public function test_wire_cents_matches_the_amount_paypal_reports_back(int $cents, string $currency, int $expected): void
    {
        $reportedByPayPal = Helper::toCent(PayPalHelper::formatAmount($cents, $currency));

        $this->assertSame($expected, $reportedByPayPal);
        $this->assertSame(PayPalHelper::wireCents($cents, $currency), $reportedByPayPal);
    }

    public function test_comparing_a_paypal_amount_against_the_raw_stored_total_rejects_a_correct_jpy_payment(): void
    {
        // Regression pin. A JPY order storing 100050 (1000.50 x100) goes on the
        // wire as "1001" and PayPal reports 100100 back. PayPal.php and IPN.php
        // used to compare that against $transaction->total and answered
        // "Paid amount does not match" AFTER the customer had already paid.
        $storedTotal = 100050;
        $reportedByPayPal = Helper::toCent(PayPalHelper::formatAmount($storedTotal, 'JPY'));

        $this->assertNotSame($storedTotal, $reportedByPayPal, 'the residue is what made the old guard fire');
        $this->assertSame(PayPalHelper::wireCents($storedTotal, 'JPY'), $reportedByPayPal);
    }

    public function test_jpy_signup_fee_cycle_sums_cents_before_rounding(): void
    {
        // 1000.50 recurring + 1000.50 fee is exactly 2001 yen. Rounding each
        // half first sends "2002" and overcharges by a yen.
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 100050,
            'signup_fee'       => 100050,
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('2001', $trial['pricing_scheme']['fixed_price']['value']);
    }

    public function test_jpy_signup_fee_below_one_yen_is_not_dropped(): void
    {
        // The fee rounds to 0 on its own, but 1000.40 + 0.40 is 1000.80, which
        // is 1001 yen. Gating the combined cycle on the ROUNDED fee skipped the
        // cycle entirely and charged "1000".
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 100040,
            'signup_fee'       => 40,
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('1001', $trial['pricing_scheme']['fixed_price']['value']);
    }

    public function test_usd_signup_fee_cycle_is_unchanged_by_the_sum_order(): void
    {
        $plan = $this->buildPlanArgs([
            'currency'         => 'USD',
            'recurring_amount' => 100050,
            'signup_fee'       => 100050,
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('2001.00', $trial['pricing_scheme']['fixed_price']['value']);
    }

    public function test_jpy_paid_trial_cycle_prices_the_fee_alone(): void
    {
        // With trial days the trial cycle charges only the signup fee — the
        // recurring price is a separate cycle, so this one rounds on its own.
        $plan = $this->buildPlanArgs([
            'currency'         => 'JPY',
            'recurring_amount' => 500000,
            'signup_fee'       => 100050,
            'trial_days'       => 14,
        ]);

        $trial = $this->cycleByTenure($plan, 'TRIAL');
        $this->assertSame('1001', $trial['pricing_scheme']['fixed_price']['value']);
        $this->assertSame('5000', $this->regularCyclePrice($plan));
    }

    private function buildPlanArgs(array $data): array
    {
        $method = new \ReflectionMethod(PayPalHelper::class, 'getPayPalPlanArgs');
        $method->setAccessible(true);

        return $method->invoke(null, array_merge([
            'paypal_product_id' => 'PROD-TEST',
            'billing_interval'  => Status::BILLING_MONTHLY,
            'trial_days'        => 0,
            'signup_fee'        => 0,
            'bill_times'        => 0,
        ], $data));
    }

    private function cycleByTenure(array $plan, string $tenure): array
    {
        foreach ($plan['billing_cycles'] as $cycle) {
            if ($cycle['tenure_type'] === $tenure) {
                return $cycle;
            }
        }

        $this->fail('No ' . $tenure . ' billing cycle in the plan payload');
    }

    private function regularCyclePrice(array $plan): string
    {
        return $this->cycleByTenure($plan, 'REGULAR')['pricing_scheme']['fixed_price']['value'];
    }
}
