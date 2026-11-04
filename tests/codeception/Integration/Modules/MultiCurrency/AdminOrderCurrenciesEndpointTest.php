<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\RateFixture;
use Tests\Support\RestTestCase;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

/**
 * The Create Order screen loads its currency list from GET orders/currencies,
 * so a settings change made after the admin app booted is reflected.
 */
class AdminOrderCurrenciesEndpointTest extends RestTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [
            ['code' => 'GBP', 'rounding' => 'up_0_50', 'charm' => 'minus_0_01', 'adjustment' => 2],
            ['code' => 'JPY'],
        ]]);
        RateFixture::set('GBP', '0.79');
        RateFixture::set('JPY', '150');
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_enabled_lists_the_base_and_rated_currencies(): void
    {
        $this->assertSame(['USD', 'GBP', 'JPY'], $this->currencyCodes());
    }

    public function test_a_currency_carries_the_rules_the_item_picker_prices_with(): void
    {
        $gbp = $this->currencies()[1];

        $this->assertSame('up_0_50', $gbp['rounding']);
        $this->assertSame('minus_0_01', $gbp['charm']);
        $this->assertEquals(2, $gbp['adjustment']);
        $this->assertSame(2, $gbp['decimals']);
        $this->assertSame(0, $this->currencies()[2]['decimals'], 'JPY prices in whole yen');
    }

    public function test_each_currency_carries_its_name_for_the_picker(): void
    {
        $this->assertSame(
            ['United States Dollar', 'British Pound', 'Japanese Yen'],
            array_column($this->currencies(), 'name')
        );
    }

    public function test_disabling_multi_currency_empties_the_list(): void
    {
        $this->assertNotEmpty($this->currencyCodes());

        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => [['code' => 'GBP'], ['code' => 'JPY']]]);
        MultiCurrencySettings::clearCache();

        $this->assertSame([], $this->currencyCodes());
    }

    private function currencyCodes(): array
    {
        return array_column($this->currencies(), 'code');
    }

    private function currencies(): array
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/orders/currencies'));
        $this->assertSame(200, $response->get_status());

        return $response->get_data()['currencies'];
    }
}
