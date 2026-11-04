<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Services\Coupon\DiscountService;
use FluentCart\App\Services\Money;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * Calculated amounts (tax, percentage discounts) in a zero-decimal currency
 * land on whole units — a multiple of 100 stored — so the order total is
 * payable. Stripe rejects UGX 1,098.90.
 */
class ZeroDecimalRoundingTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    use BuildsTaxFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->setTaxSettings([]);
        $this->resetTaxRuntimeState();
    }

    public function tearDown(): void
    {
        $this->restoreStoreCurrency();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        delete_option('fluent_cart_tax_configuration_settings');
        parent::tearDown();
    }

    public function test_only_zero_decimal_currencies_are_rounded(): void
    {
        $this->assertSame(109900, Money::roundIfZeroDecimal(109890, 'UGX'));
        $this->assertSame(109800, Money::roundIfZeroDecimal(109849, 'jpy'));
        $this->assertSame(109890, Money::roundIfZeroDecimal(109890, 'USD'));
        $this->assertSame(1234.5, Money::roundIfZeroDecimal(1234.5, 'EUR'));
    }

    private function taxCalculator(string $currency, string $inclusion, string $rounding, int $lines = 1, array $line = []): TaxCalculator
    {
        $this->setStoreCurrency($currency);
        $this->enableTax($inclusion, 'billing', $rounding);
        $class = (new TaxClassFactory())->create();
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $items = [];
        for ($i = 0; $i < $lines; $i++) {
            $items[] = $this->buildLineItem($product->ID, array_merge(['subtotal' => 99900], $line));
        }

        return new TaxCalculator($items, [
            'inclusive'    => $inclusion === 'included',
            'country'      => 'US',
            'tax_rounding' => $rounding,
        ]);
    }

    public function test_ugx_item_tax_rounds_to_a_whole_shilling(): void
    {
        $calc = $this->taxCalculator('UGX', 'excluded', 'item');

        $this->assertSame(10000, (int) $calc->getTaxedLines()[0]['tax_amount']);
        $this->assertSame(10000, $calc->getTotalTax());
    }

    public function test_ugx_subtotal_rounding_rounds_the_total_to_a_whole_shilling(): void
    {
        $calc = $this->taxCalculator('UGX', 'excluded', 'subtotal', 3);

        // each line 9990 → 10000
        $this->assertSame(30000, $calc->getTotalTax());
    }

    public function test_ugx_inclusive_tax_rounds_to_a_whole_shilling(): void
    {
        $calc = $this->taxCalculator('UGX', 'included', 'item');

        // 99900 × 10 / 110 = 9081.8
        $this->assertSame(9100, $calc->getTotalTax());
    }

    public function test_ugx_subscription_line_tax_rounds_in_subtotal_mode(): void
    {
        $calc = $this->taxCalculator('UGX', 'excluded', 'subtotal', 1, ['other_info' => ['payment_type' => 'subscription']]);
        $line = $calc->getTaxedLines()[0];

        // recurring_total = subtotal + recurring_tax, so 9990 would charge 109890
        $this->assertSame(10000, (int) $line['other_info']['recurring_tax']);
        $this->assertSame(10000, (int) $line['tax_amount']);
    }

    public function test_usd_subscription_line_tax_stays_unrounded_in_subtotal_mode(): void
    {
        $calc = $this->taxCalculator('USD', 'excluded', 'subtotal', 1, ['other_info' => ['payment_type' => 'subscription']]);

        $this->assertEquals(9990, $calc->getTaxedLines()[0]['other_info']['recurring_tax']);
    }

    public function test_usd_tax_keeps_cent_precision(): void
    {
        $calc = $this->taxCalculator('USD', 'excluded', 'item');

        $this->assertSame(9990, $calc->getTotalTax());
    }

    private function percentageDiscount(string $currency): int
    {
        $this->setStoreCurrency($currency);
        $item = CartFactory::makeCartItem(['unit_price' => 99900, 'subtotal' => 99900, 'line_total' => 99900]);
        $cart = (new CartFactory())->withCartItems([$item])->create();
        (new CouponFactory())->create(['code' => 'TEN' . $currency, 'type' => 'percentage', 'amount' => 10]);

        $result = (new DiscountService($cart))->applyCouponCodes(['TEN' . $currency]);

        return (int) $result['cart_items'][0]['coupon_discount'];
    }

    public function test_ugx_percentage_coupon_rounds_to_a_whole_shilling(): void
    {
        $this->assertSame(10000, $this->percentageDiscount('UGX'));
    }

    public function test_usd_percentage_coupon_keeps_cent_precision(): void
    {
        $this->assertSame(9990, $this->percentageDiscount('USD'));
    }

    private function percentageShipping(string $currency, bool $byProfile): int
    {
        $this->setStoreCurrency($currency);
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 3,
            'settings' => ['configure_rate' => 'per_price'],
        ]);
        $items = [[
            'post_id'                  => 0,
            'quantity'                 => 1,
            'unit_price'               => 99900,
            'line_total'               => 99900,
            'discount_total'           => 0,
            'fulfillment_type'         => 'physical',
            'shipping_charge'          => 0,
            'itemwise_shipping_charge' => 0,
        ]];

        if ($byProfile) {
            return (int) CartHelper::calculateShippingByProfile($method->id, $items, 'US');
        }

        return (int) CartHelper::calculateShippingMethodCharge($method, $items);
    }

    public function test_ugx_percentage_shipping_rate_rounds_to_a_whole_shilling(): void
    {
        // 3% of 99900 = 2997
        $this->assertSame(3000, $this->percentageShipping('UGX', false));
    }

    public function test_ugx_percentage_shipping_rate_by_profile_rounds_to_a_whole_shilling(): void
    {
        $this->assertSame(3000, $this->percentageShipping('UGX', true));
    }

    public function test_usd_percentage_shipping_rate_keeps_cent_precision(): void
    {
        $this->assertSame(2997, $this->percentageShipping('USD', false));
        $this->assertSame(2997, $this->percentageShipping('USD', true));
    }

    private function adminOrderTax(string $storeCurrency, string $orderCurrency): int
    {
        $this->setStoreCurrency($storeCurrency);
        $this->enableTax('excluded', 'billing', 'item');
        $class = (new TaxClassFactory())->create();
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id,
            'currency'    => $orderCurrency,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'    => $product->ID,
            'object_id'  => (int) ProductVariation::query()->where('post_id', $product->ID)->value('id'),
            'unit_price' => 99900,
            'subtotal'   => 99900,
            'line_total' => 99900,
            'other_info' => [],
        ]);
        (new OrderAddressFactory())->create($order->id);

        return (int) OrderResource::syncItemDerivedTotals($order)->tax_total;
    }

    public function test_admin_order_tax_rounds_in_the_order_currency_not_the_base(): void
    {
        $this->assertSame(10000, $this->adminOrderTax('USD', 'JPY'));
    }

    public function test_admin_order_tax_keeps_cent_precision_on_a_zero_decimal_base(): void
    {
        $this->assertSame(9990, $this->adminOrderTax('JPY', 'USD'));
    }

    public function test_admin_order_created_in_a_zero_decimal_currency_rounds_its_tax(): void
    {
        $this->setStoreCurrency('USD');
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'JPY']]]);
        RateFixture::set('JPY', '1');
        $this->enableTax('excluded', 'billing', 'item');
        $class = (new TaxClassFactory())->create();
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);
        $variantId = (int) ProductVariation::query()->where('post_id', $product->ID)->value('id');
        // The order prices catalog lines from the product, not the posted unit_price
        ProductVariation::query()->where('id', $variantId)->update(['item_price' => 99900]);
        $customer = (new CustomerFactory())->create();
        $address = (new CustomerAddressFactory())->create($customer->id, ['is_primary' => 1]);

        $order = OrderResource::updatedPlaceOrder([
            'customer_id'        => $customer->id,
            'billing_address_id' => $address->id,
            'currency'           => 'JPY',
            'order_items'        => [[
                'id'           => $variantId,
                'post_id'      => $product->ID,
                'object_id'    => $variantId,
                'title'        => 'Default',
                'post_title'   => 'Product',
                'unit_price'   => 99900,
                'quantity'     => 1,
                'payment_type' => 'onetime',
                'other_info'   => ['payment_type' => 'onetime'],
            ]],
            'discount'           => [],
            'shipping'           => [],
            'labels'             => [],
            'user_tz'            => 'UTC',
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $order = Order::query()->find($order->id);
        $this->assertSame('JPY', $order->currency);
        $this->assertSame(10000, (int) $order->tax_total);
    }
}
