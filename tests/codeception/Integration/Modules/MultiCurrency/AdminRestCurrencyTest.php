<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * The admin app reads and writes over REST, where is_admin() is false. A
 * shopper's currency cookie (set while the admin browsed the storefront)
 * must not reach admin REST responses; the storefront's REST routes still
 * follow it. Base USD, GBP at 0.79, cookie GBP.
 */
class AdminRestCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    /** @var string|null */
    private $requestUri;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;

        MultiCurrencySettings::clearCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        ExchangeRateService::flushCache();
        if (!ExchangeRateService::current('GBP')) {
            RateFixture::set('GBP', '0.79');
        }
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
    }

    public function tearDown(): void
    {
        if ($this->requestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }
        unset($_COOKIE[CurrencyContext::COOKIE], $_GET['rest_route']);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_admin_rest_routes_stay_in_the_base_currency(): void
    {
        foreach (['/wp-json/fluent-cart/v2/products', '/wp-json/fluent-cart/v2/orders/12', '/wp-json/fluent-cart/v2/reports/fetch-report-meta', '/shop/wp-json/fluent-cart/v2/customers'] as $uri) {
            $this->requestTo($uri);

            $this->assertSame('USD', CurrencyContext::current(), $uri);
            $this->assertSame('USD', CurrencySettings::get('currency'), $uri);
        }
    }

    public function test_admin_rest_routes_stay_in_the_base_currency_on_plain_permalinks(): void
    {
        $this->requestTo('/index.php?rest_route=/fluent-cart/v2/orders', '/fluent-cart/v2/orders');

        $this->assertSame('USD', CurrencyContext::current());
    }

    public function test_storefront_rest_routes_follow_the_shoppers_currency(): void
    {
        foreach (['/wp-json/fluent-cart/v2/public/products', '/wp-json/fluent-cart/v2/checkout/place-order', '/wp-json/fluent-cart/v2/cart/add_item', '/wp-json/fluent-cart/v2/customer-profile/orders'] as $uri) {
            $this->requestTo($uri);

            $this->assertSame('GBP', CurrencyContext::current(), $uri);
        }

        $this->requestTo('/index.php?rest_route=/fluent-cart/v2/public/products', '/fluent-cart/v2/public/products');
        $this->assertSame('GBP', CurrencyContext::current(), 'plain permalinks');
    }

    public function test_other_plugins_rest_routes_are_unchanged(): void
    {
        $this->requestTo('/wp-json/wp/v2/posts');

        $this->assertSame('GBP', CurrencyContext::current());
    }

    private function requestTo(string $uri, string $restRoute = ''): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        if ($restRoute === '') {
            unset($_GET['rest_route']);
        } else {
            $_GET['rest_route'] = $restRoute;
        }
        $this->reset();
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
