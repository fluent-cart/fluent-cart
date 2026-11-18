<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\RateFixture;

/**
 * The store's margin on automated rates (dev-docs/multi-currency/08-exchange-rates.md,
 * "Safeguards"). Feeds publish mid-market rates; the merchant settles at
 * their gateway's rate, so a markup covers the spread. Manual rates are the
 * merchant's own number and are never marked up.
 */
class RateMarginTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_the_margin_marks_up_a_fed_rate_and_is_frozen_with_it(): void
    {
        // The margin applies only while a feed is the selected provider
        add_filter('fluent_cart/currency/rate_providers', [$this, 'registerFeed']);

        try {
            MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_provider' => 'ecb', 'rate_margin' => 2.5]);
            RateFixture::set('GBP', '0.80', 'ecb');

            $rate = ExchangeRateService::current('GBP');

            $this->assertSame('0.820000000000', $rate['rate']);
            $this->assertSame('2.5', $rate['margin'], 'the margin travels into the order snapshot');
        } finally {
            remove_filter('fluent_cart/currency/rate_providers', [$this, 'registerFeed']);
        }
    }

    public function registerFeed($providers)
    {
        return $providers + ['ecb' => 'Test feed'];
    }

    public function test_a_manual_rate_is_never_marked_up(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_margin' => 2.5]);
        RateFixture::set('GBP', '0.80', CurrencySnapshot::SOURCE_MANUAL);

        $this->assertSame('0.800000000000', ExchangeRateService::current('GBP')['rate']);
    }

    public function test_the_margin_stops_when_the_merchant_switches_to_manual_rates(): void
    {
        add_filter('fluent_cart/currency/rate_providers', [$this, 'registerFeed']);

        try {
            MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_provider' => 'ecb', 'rate_margin' => 2]);
            RateFixture::set('GBP', '0.80', 'ecb');

            $this->assertSame('0.816000000000', ExchangeRateService::current('GBP')['rate'], 'the feed is selected, so its rate is marked up');

            // The newest row is still the feed's, but the margin field is
            // hidden in manual mode, so no margin may apply unseen
            MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_provider' => CurrencySnapshot::SOURCE_MANUAL, 'rate_margin' => 2]);
            $this->assertSame(2.0, MultiCurrencySettings::rateMargin(), 'the saved margin is kept, only hidden');
            ExchangeRateService::flushCache();

            $rate = ExchangeRateService::current('GBP');
            $this->assertSame('0.800000000000', $rate['rate']);
            $this->assertSame('0', $rate['margin']);
        } finally {
            remove_filter('fluent_cart/currency/rate_providers', [$this, 'registerFeed']);
        }
    }

    public function test_the_margin_is_clamped_to_zero_to_ten_percent(): void
    {
        $this->assertSame(10.0, (float) MultiCurrencySettings::save(['rate_margin' => 25])['rate_margin']);
        $this->assertSame(0.0, (float) MultiCurrencySettings::save(['rate_margin' => -3])['rate_margin']);
        $this->assertSame(1.25, (float) MultiCurrencySettings::save(['rate_margin' => '1.2549'])['rate_margin']);
    }
}
