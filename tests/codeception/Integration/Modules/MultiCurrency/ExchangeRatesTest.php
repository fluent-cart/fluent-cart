<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\RoundingRules;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\RateFixture;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

/**
 * Rate resolution and multi-currency settings in core. Storing rates is
 * Pro's (IntegrationPro\MultiCurrency\ExchangeRatesTest). Base USD.
 */
class ExchangeRatesTest extends RestTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
    }

    public function tearDown(): void
    {
        // Neither the guest dispatch nor the bound framework request may leak
        // into the next test class
        wp_set_current_user(1);
        $this->refreshFluentCartCurrentUserCache();
        $this->resetFluentRequest();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_no_rate_means_null_never_one(): void
    {
        $this->assertNull(ExchangeRateService::current('JPY'), 'a missing rate must refuse the currency, not price it at 1');
        $this->assertSame('1.000000000000', ExchangeRateService::current('USD')['rate'], 'the base currency is always 1');
    }

    public function test_margin_is_applied_to_the_rate_the_customer_pays(): void
    {
        RateFixture::set('GBP', '0.80');
        $margin = function () {
            return 2.5;
        };
        add_filter('fluent_cart/currency/fx_margin', $margin);

        $rate = ExchangeRateService::current('GBP');
        remove_filter('fluent_cart/currency/fx_margin', $margin);

        $this->assertSame('0.820000000000', $rate['rate']);
        $this->assertSame('2.5', $rate['margin']);
    }

    public function test_settings_refuse_the_base_currency_and_three_decimal_currencies(): void
    {
        $saved = MultiCurrencySettings::save([
            'enabled'    => 'yes',
            'currencies' => [['code' => 'GBP'], ['code' => 'KWD'], ['code' => 'USD']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $saved);
        $this->assertStringContainsString('KWD', $saved->get_error_message());
        $this->assertStringContainsString('USD', $saved->get_error_message());

        $saved = MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'gbp', 'rounding' => 'bogus']]]);
        $this->assertSame([['code' => 'GBP', 'rounding' => RoundingRules::NONE, 'charm' => RoundingRules::NONE, 'adjustment' => '0']], $saved['currencies']);
        $this->assertSame(MultiCurrencySettings::DEFAULT_RATE_LOCK_MINUTES, $saved['rate_lock_minutes']);
        $this->assertSame(['GBP'], MultiCurrencySettings::currencyCodes());
    }

    public function test_a_rate_provider_must_be_registered_to_be_chosen(): void
    {
        $saved = MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_provider' => 'ecb']);
        $this->assertSame(CurrencySnapshot::SOURCE_MANUAL, $saved['rate_provider'], 'an unregistered provider falls back to manual');

        $register = function ($providers) {
            $providers['ecb'] = 'ECB';
            return $providers;
        };
        add_filter('fluent_cart/currency/rate_providers', $register);
        $fired = [];
        $listener = function ($payload) use (&$fired) {
            $fired[] = $payload['settings']['rate_provider'];
        };
        add_action('fluent_cart/currency/settings_saved', $listener);

        $saved = MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']], 'rate_provider' => 'ecb']);

        remove_filter('fluent_cart/currency/rate_providers', $register);
        remove_action('fluent_cart/currency/settings_saved', $listener);

        $this->assertSame('ecb', $saved['rate_provider']);
        $this->assertSame(['ecb'], $fired, 'providers learn about a save so they can refresh at once');
    }

    public function test_base_currency_is_locked_once_orders_exist_while_enabled(): void
    {
        $this->assertTrue(MultiCurrencySettings::guardBaseCurrencyChange('USD', 'EUR'), 'free to change while disabled');

        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);

        $this->assertInstanceOf(\WP_Error::class, MultiCurrencySettings::guardBaseCurrencyChange('USD', 'EUR'));
        $this->assertTrue(MultiCurrencySettings::guardBaseCurrencyChange('USD', 'usd'), 'same currency is not a change');
    }

    public function test_zero_decimal_prices_round_to_whole_units(): void
    {
        // ×100 storage: ¥7,445.10 is 744510 and rounds to ¥7,445
        $this->assertSame(744500, RoundingRules::apply(744510, 'JPY'));
        $this->assertSame(3934, RoundingRules::apply(3934.2, 'GBP'));
    }

    public function test_core_alone_does_not_register_the_settings_endpoints(): void
    {
        // The admin API lives in Pro; this suite runs without Pro loaded
        $GLOBALS['wp_rest_server'] = null;
        $routes = array_keys(rest_get_server()->get_routes('fluent-cart/v2'));

        $this->assertNotEmpty($routes, 'core still registers its own routes');
        $this->assertSame([], preg_grep('#^/fluent-cart/v2/settings/multi-currency#', $routes));

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/settings/multi-currency');
        $this->assertSame(404, $this->dispatchRequest($request)->get_status());
    }
}
