<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\RateFixture;

/**
 * A rate from a provider filter, or one a margin filter moved, must still
 * fit DECIMAL(24,12). One that rounds to zero would price the cart at zero;
 * one with 13+ integer digits overflows the order and transaction columns.
 * ExchangeRateService::current() refuses both by returning null.
 */
class ProviderRateStorableTest extends DatabaseTestCase
{
    private $providerRate;
    private $margin;

    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.80');
    }

    public function tearDown(): void
    {
        remove_filter('fluent_cart/currency/exchange_rate', [$this, 'provideRate']);
        remove_filter('fluent_cart/currency/fx_margin', [$this, 'provideMargin']);
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function provideRate($rate)
    {
        $rate['rate'] = $this->providerRate;

        return $rate;
    }

    public function provideMargin()
    {
        return $this->margin;
    }

    private function withProviderRate($value): ?array
    {
        $this->providerRate = $value;
        add_filter('fluent_cart/currency/exchange_rate', [$this, 'provideRate']);

        return ExchangeRateService::current('GBP');
    }

    private function withMargin($value): ?array
    {
        $this->margin = $value;
        add_filter('fluent_cart/currency/fx_margin', [$this, 'provideMargin']);

        return ExchangeRateService::current('GBP');
    }

    public function test_a_provider_rate_below_twelve_decimals_is_refused(): void
    {
        $this->assertNull($this->withProviderRate('0.0000000000001'));
    }

    public function test_a_provider_rate_in_scientific_notation_below_twelve_decimals_is_refused(): void
    {
        $this->assertNull($this->withProviderRate('1e-13'));
    }

    public function test_a_provider_rate_with_thirteen_integer_digits_is_refused(): void
    {
        $this->assertNull($this->withProviderRate('1000000000000'));
    }

    public function test_a_margin_of_minus_one_hundred_percent_is_refused(): void
    {
        $this->assertNull($this->withMargin(-100));
    }

    public function test_a_margin_below_minus_one_hundred_percent_is_refused(): void
    {
        $this->assertNull($this->withMargin(-150));
    }

    public function test_a_valid_provider_rate_with_a_margin_is_still_priced(): void
    {
        $this->providerRate = '0.80';
        add_filter('fluent_cart/currency/exchange_rate', [$this, 'provideRate']);

        $rate = $this->withMargin(2.5);

        $this->assertSame('0.820000000000', $rate['rate']);
        $this->assertSame('2.5', $rate['margin']);
    }
}
