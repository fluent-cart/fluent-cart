<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Models\Order;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RateFixture;

/**
 * An upgrade path is priced in the order's currency (plan decision #10).
 * When that currency cannot be quoted — no current rate and no trusted
 * snapshot — the path is offered as unavailable, never priced in the base
 * currency and labelled with the order's. Base USD; the target plan costs
 * $49.80, GBP 0.79 makes it £39.34.
 */
class PlanUpgradeCurrencyTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        $this->reset();
    }

    public function tearDown(): void
    {
        $this->reset();
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        parent::tearDown();
    }

    public function test_a_legacy_foreign_order_without_a_rate_gets_an_unavailable_upgrade_path(): void
    {
        // Pre-snapshot GBP order, no GBP rate recorded: nothing can price GBP
        $paths = $this->upgradePaths('GBP', CurrencySnapshot::legacy('GBP'));

        $this->assertCount(1, $paths);
        $path = $paths[0];
        $this->assertSame('GBP', $path['currency']);
        $this->assertFalse($path['is_available'] ?? true, 'priced ' . var_export($path['price'], true) . ' in USD base while labelled GBP');
        $this->assertNotEmpty($path['unavailable_reason']);
        $this->assertNull($path['price']);
        $this->assertNull($path['cost']);
        $this->assertNull($path['prorate_credit']);
        $this->assertNull($path['discount_amount']);
        $this->assertSame('', $path['upgrade_url'], 'no checkout link for an unpriceable upgrade');
        $this->assertStringNotContainsString('$', $path['payment_summary']);
    }

    public function test_a_foreign_order_with_a_trusted_snapshot_is_priced_in_its_currency(): void
    {
        // No current GBP rate: the order's own trusted rate prices the upgrade
        $paths = $this->upgradePaths('GBP', CurrencySnapshot::make('GBP', '0.79', ['source' => CurrencySnapshot::SOURCE_MANUAL]));

        $path = $paths[0];
        $this->assertTrue($path['is_available']);
        $this->assertSame('', $path['unavailable_reason']);
        $this->assertSame('GBP', $path['currency']);
        $this->assertSame(3934.0, $path['price']);
        $this->assertSame(395.0, $path['discount_amount'], '$5.00 path discount converted to GBP');
        $this->assertSame(1000.0, $path['prorate_credit'], 'one-time credit is what was paid, in GBP');
        $this->assertSame(3934.0 - 1000 - 395, (float) $path['cost']);
        $this->assertNotSame('', $path['upgrade_url']);
    }

    public function test_a_legacy_foreign_order_is_priced_at_the_current_rate_when_one_exists(): void
    {
        RateFixture::set('GBP', '0.79');

        $path = $this->upgradePaths('GBP', CurrencySnapshot::legacy('GBP'))[0];

        $this->assertTrue($path['is_available']);
        $this->assertSame(3934.0, $path['price']);
    }

    public function test_a_base_currency_order_is_always_priced(): void
    {
        $path = $this->upgradePaths('USD', null)[0];

        $this->assertTrue($path['is_available']);
        $this->assertSame('USD', $path['currency']);
        $this->assertSame(4980.0, $path['price']);
        $this->assertSame(500.0, $path['discount_amount']);
    }

    private function upgradePaths(string $currency, $snapshot): array
    {
        $from = (new ProductFactory())->withVariantAttributes(['item_price' => 2000])->create();
        $to = (new ProductFactory())->withVariantAttributes(['item_price' => 4980])->create();
        $fromVariantId = (int) $from->variants->first()->id;
        $toVariantId = (int) $to->variants->first()->id;

        (new MetaFactory())->createVariantUpgradePath($fromVariantId, [$toVariantId], true, 5);

        $attributes = [
            'customer_id'  => (new CustomerFactory())->create()->id,
            'currency'     => $currency,
            'subtotal'     => 1000,
            'total_amount' => 1000,
        ];
        if ($snapshot) {
            $attributes['config'] = ['fx' => $snapshot];
        }
        $order = (new OrderFactory())->create($attributes);
        (new OrderItemFactory())->create($order->id, [
            'post_id'    => $from->ID,
            'object_id'  => $fromVariantId,
            'unit_price' => 1000,
            'subtotal'   => 1000,
            'line_total' => 1000,
        ]);

        $order = Order::query()->find($order->id);
        if ($snapshot) {
            $this->assertSame($snapshot['source'], CurrencySnapshot::fromOrder($order)['source'], 'fixture keeps its snapshot');
        }

        return PlanUpgradeService::getUpgardePathsFromVariation($fromVariantId, $order->uuid);
    }

    private function reset(): void
    {
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
