<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\PlanUpgradeService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\MetaFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * The customer-dashboard upgrade summary is one translatable sentence,
 * so a translated storefront can reorder it and keeps no English from code.
 */
class PlanUpgradeSummaryI18nTest extends DatabaseTestCase
{
    private $gettextFilter;

    public function tearDown(): void
    {
        if ($this->gettextFilter) {
            remove_filter('gettext_fluent-cart', $this->gettextFilter, 10);
        }
        parent::tearDown();
    }

    public function test_subscription_upgrade_summary_english_is_unchanged(): void
    {
        $path = $this->upgradePath(['payment_type' => 'subscription', 'repeat_interval' => 'monthly']);

        $this->assertSame('<strong>$49.80</strong> first month, then $49.80/month thereafter.', html_entity_decode($path['payment_summary']));
    }

    public function test_subscription_upgrade_summary_is_one_translatable_sentence(): void
    {
        $this->gettextFilter = static function ($translation, $text) {
            $map = [
                '%1$s first %2$s, then %3$s/%2$s thereafter.' => '[%3$s/%2$s after %1$s]',
                'month'                                       => 'MONTH',
            ];
            return $map[$text] ?? $translation;
        };
        add_filter('gettext_fluent-cart', $this->gettextFilter, 10, 2);

        $path = $this->upgradePath(['payment_type' => 'subscription', 'repeat_interval' => 'monthly']);

        $this->assertSame('[$49.80/MONTH after <strong>$49.80</strong>]', html_entity_decode($path['payment_summary']));
    }

    public function test_onetime_upgrade_summary_is_translatable(): void
    {
        $this->gettextFilter = static function ($translation, $text) {
            return $text === '%1$s one-time' ? 'once: %1$s' : $translation;
        };
        add_filter('gettext_fluent-cart', $this->gettextFilter, 10, 2);

        $path = $this->upgradePath(['payment_type' => 'onetime']);

        $this->assertSame('once: $49.80', html_entity_decode($path['payment_summary']));
    }

    private function upgradePath(array $toOtherInfo): array
    {
        $from = (new ProductFactory())->withVariantAttributes(['item_price' => 2000])->create();
        $to = (new ProductFactory())->withVariantAttributes([
            'item_price'   => 4980,
            'payment_type' => $toOtherInfo['payment_type'],
            'other_info'   => $toOtherInfo,
        ])->create();
        $fromVariantId = (int) $from->variants->first()->id;
        $toVariantId = (int) $to->variants->first()->id;

        (new MetaFactory())->createVariantUpgradePath($fromVariantId, [$toVariantId]);

        $order = (new OrderFactory())->create([
            'customer_id'  => (new CustomerFactory())->create()->id,
            'currency'     => 'USD',
            'subtotal'     => 2000,
            'total_amount' => 2000,
        ]);
        (new OrderItemFactory())->create($order->id, [
            'post_id'    => $from->ID,
            'object_id'  => $fromVariantId,
            'unit_price' => 2000,
            'subtotal'   => 2000,
            'line_total' => 2000,
        ]);

        $order = Order::query()->find($order->id);
        $paths = PlanUpgradeService::getUpgardePathsFromVariation($fromVariantId, $order->uuid);

        $this->assertCount(1, $paths);
        $this->assertTrue($paths[0]['is_available']);

        return $paths[0];
    }
}
