<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\StorefrontPrice;
use FluentCart\App\Services\Renderer\ProductFilterRender;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * The shop app's price range filter in the visitor's currency: the slider
 * shows converted bounds with the visitor's sign, and a chosen range finds
 * exactly the products shown inside it — rounding gives several base prices
 * one shown price ($49.78 and $49.79 are both £39.33). Base USD, GBP at 0.79.
 */
class ShopPriceFilterCurrencyTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::clearCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']]]);
        ExchangeRateService::flushCache();
        if (!ExchangeRateService::current('GBP')) {
            RateFixture::set('GBP', '0.79');
        }
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_the_slider_shows_converted_bounds_and_the_visitors_sign(): void
    {
        (new ProductFactory())->withVariantAttributes(['item_price' => 4979])->create();

        $html = $this->renderPriceRange();

        $min = Helper::toDecimalWithoutComma(StorefrontPrice::amount(ProductDetail::query()->min('min_price')));
        $max = Helper::toDecimalWithoutComma(StorefrontPrice::amount(ProductDetail::query()->max('max_price')));

        // Currency signs are stored as HTML entities
        $this->assertStringContainsString('&pound;', $html);
        $this->assertStringNotContainsString('&#36;', $html);
        $this->assertMatchesRegularExpression('/name="price_range_from"[^>]*data-value="' . preg_quote((string) $min, '/') . '"/', $html);
        $this->assertMatchesRegularExpression('/name="price_range_to"[^>]*data-value="' . preg_quote((string) $max, '/') . '"/', $html);
    }

    public function test_a_range_finds_every_product_shown_inside_it(): void
    {
        // $49.77 -> £39.32 · $49.78 -> £39.33 · $49.79 -> £39.33 · $49.80 -> £39.34
        $below = $this->product(4977);
        $lowest = $this->product(4978);
        $highest = $this->product(4979);
        $above = $this->product(4980);

        $this->assertSame([3932, 3933, 3933, 3934], array_map([StorefrontPrice::class, 'amount'], [4977, 4978, 4979, 4980]));

        $found = $this->shopIds('39.33', '39.33', [$below, $lowest, $highest, $above]);

        $this->assertSame([$lowest, $highest], $found);
    }

    public function test_a_range_matches_the_prices_shown_under_pricing_rules(): void
    {
        // Rounded up to 1, ending .99: $49.00 -> £38.99 · $50.00 -> £39.99 ·
        // $50.70 -> £40.99. A £39.99–£39.99 range is the $50.00 product: the
        // bound follows the shown (rounded) price, not the plain conversion
        // (£39.50), which would find nothing.
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'up_1', 'charm' => 'minus_0_01']]]);
        MultiCurrencySettings::clearCache();
        $this->reset();

        $below = $this->product(4900);
        $shown = $this->product(5000);
        $above = $this->product(5070);

        $this->assertSame([3899, 3999, 4099], array_map([StorefrontPrice::class, 'amount'], [4900, 5000, 5070]));

        $this->assertSame([$shown], $this->shopIds('39.99', '39.99', [$below, $shown, $above]));
        $this->assertSame([$below, $shown], $this->shopIds('38.99', '39.99', [$below, $shown, $above]));
    }

    public function test_a_range_under_pricing_rules_finds_exactly_its_edge_prices(): void
    {
        // Rounded up to 1, ending .99, the shown price steps at exact base
        // cents: $49.36 -> £38.99 · $49.37 -> £39.99 · $50.63 -> £39.99 ·
        // $50.64 -> £40.99. A £39.99 range finds both edges, never the
        // products a cent outside them.
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'up_1', 'charm' => 'minus_0_01']]]);
        MultiCurrencySettings::clearCache();
        $this->reset();

        $below = $this->product(4936);
        $lowest = $this->product(4937);
        $highest = $this->product(5063);
        $above = $this->product(5064);

        $this->assertSame([3899, 3999, 3999, 4099], array_map([StorefrontPrice::class, 'amount'], [4936, 4937, 5063, 5064]));

        $this->assertSame([$lowest, $highest], $this->shopIds('39.99', '39.99', [$below, $lowest, $highest, $above]));
    }

    public function test_the_base_currency_range_is_unchanged(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();

        $inside = $this->product(5000);
        $outside = $this->product(5001);

        $this->assertSame([$inside], $this->shopIds('40', '50', [$inside, $outside]));
    }

    private function product(int $basePrice): int
    {
        return (int) (new ProductFactory())->withVariantAttributes(['item_price' => $basePrice])->create()->ID;
    }

    private function shopIds(string $from, string $to, array $ids): array
    {
        $result = ShopResource::get([
            'filters'     => ['price_range_from' => $from, 'price_range_to' => $to],
            'include_ids' => $ids,
            'per_page'    => 20,
        ]);

        $found = array_map('intval', array_column($result['products']->toArray()['data'], 'ID'));
        sort($found);

        return $found;
    }

    private function renderPriceRange(): string
    {
        $renderer = new ProductFilterRender([
            'price_range' => ['filter_type' => 'range', 'is_meta' => false, 'label' => 'Price', 'enabled' => true],
        ]);

        ob_start();
        $renderer->render();

        return (string) ob_get_clean();
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
