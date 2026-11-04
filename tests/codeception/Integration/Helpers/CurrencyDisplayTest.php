<?php

namespace Tests\Integration\Helpers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The display chokepoints must take decimals from the currency being rendered,
 * not from the store's configured currency. Regression case: a USD order on a
 * JPY-configured store rendered "$20" instead of "$19.99" because the decimal
 * count came from shopConfig('is_zero_decimal') — the store currency.
 */
class CurrencyDisplayTest extends WPTestCase
{
    private $originalCurrency;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalCurrency = (new StoreSettings())->getCurrency();
    }

    public function tearDown(): void
    {
        (new StoreSettings())->save(['currency' => $this->originalCurrency]);
        parent::tearDown();
    }

    public function test_usd_amount_keeps_its_decimals_on_a_jpy_store(): void
    {
        (new StoreSettings())->save(['currency' => 'JPY']);

        $this->assertStringContainsString('19.99', Helper::toDecimal(1999, true, 'USD'), 'a USD amount must keep 2 decimals even when the store currency is zero-decimal');
        $this->assertStringContainsString('19.99', CurrencySettings::getFormattedPrice(1999, 'USD'));
    }

    public function test_jpy_amount_drops_decimals_on_a_usd_store(): void
    {
        (new StoreSettings())->save(['currency' => 'USD']);

        $rendered = Helper::toDecimal(740000, false, 'JPY');
        $this->assertSame('7,400', $rendered, 'a JPY amount renders with no decimals regardless of store currency');

        $formatted = CurrencySettings::getFormattedPrice(740000, 'JPY', false, true);
        $this->assertStringContainsString('7,400', $formatted);
        $this->assertStringNotContainsString('7,400.00', $formatted);
    }

    public function test_store_currency_rendering_is_unchanged(): void
    {
        (new StoreSettings())->save(['currency' => 'USD']);
        $this->assertStringContainsString('19.99', Helper::toDecimal(1999, true, 'USD'));

        (new StoreSettings())->save(['currency' => 'JPY']);
        $this->assertStringContainsString('7,400', Helper::toDecimal(740000, true, 'JPY'));
    }
}
