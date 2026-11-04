<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySwitcher;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\StorefrontPrice;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Storefront pieces that show or change the visitor's currency: the switcher
 * keeps the page it was used on (an instant-checkout or re-pay hash, plain
 * permalinks, shop filters), and a pricing table's setup fee is shown at the
 * price the cart charges. Base USD, GBP at 0.79.
 */
class StorefrontDisplayCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    /** @var array */
    private $get;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->get = $_GET;
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']]]);
        if (!ExchangeRateService::current('GBP')) {
            RateFixture::set('GBP', '0.79');
        }
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();
    }

    public function tearDown(): void
    {
        $_GET = $this->get;
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_the_switcher_keeps_the_pages_query_string(): void
    {
        $_GET = [
            'fct_cart_hash' => md5('buy-now'),
            'page_id'       => '42',
            'currency'      => 'GBP',
        ];

        $html = CurrencySwitcher::render();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="fct_cart_hash" value="' . md5('buy-now') . '"/', $html, 'a buy-now or re-pay cart survives the switch');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="page_id" value="42"/', $html, 'plain permalinks survive');
        $this->assertDoesNotMatchRegularExpression('/type="hidden" name="currency"/', $html, 'the select is the only currency field');
    }

    public function test_two_switchers_on_a_page_have_their_own_ids(): void
    {
        preg_match('/<select id="([^"]+)"/', CurrencySwitcher::render(), $first);
        preg_match('/<select id="([^"]+)"/', CurrencySwitcher::render(), $second);

        $this->assertNotSame($first[1], $second[1]);
    }

    public function test_a_pricing_tables_setup_fee_is_the_fee_the_cart_charges(): void
    {
        $info = \FluentCart\App\Services\Renderer\PricingTableRenderer::setupFeeInfo([
            'manage_setup_fee' => 'yes',
            'signup_fee'       => 2000,
            'signup_fee_name'  => 'Setup Fee',
        ]);

        $this->assertStringContainsString(html_entity_decode(Helper::toDecimal(StorefrontPrice::amount(2000))), html_entity_decode($info), '$20.00 is £15.80');
        $this->assertStringNotContainsString('20.00', html_entity_decode($info));
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
