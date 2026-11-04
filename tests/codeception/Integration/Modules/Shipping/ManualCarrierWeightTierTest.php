<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

/**
 * A per-weight method is not offered, and cannot be charged, when no tier covers the cart weight.
 */
class ManualCarrierWeightTierTest extends DatabaseTestCase
{
    private const STANDARD_TIERS = [
        ['min' => 0, 'max' => 5, 'cost' => 5],
        ['min' => 5, 'max' => 10, 'cost' => 10],
    ];

    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
    }

    public function tearDown(): void
    {
        AddressHelper::resetRateCache();
        parent::tearDown();
    }

    public function test_weight_over_the_last_tier_is_not_offered(): void
    {
        $methodId = $this->perWeightMethod(self::STANDARD_TIERS);

        $this->assertNull($this->rateFor($methodId, $this->items(6, 2)), '12 kg is above every tier');
    }

    public function test_weight_in_a_gap_between_tiers_is_not_offered(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 0.5, 'cost' => 3],
            ['min' => 2, 'max' => 0, 'cost' => 8],
        ]);

        $this->assertNull($this->rateFor($methodId, $this->items(1.15, 1)), '1.15 kg falls between 0.5 and 2');
    }

    public function test_a_method_without_tiers_is_not_offered(): void
    {
        $methodId = $this->perWeightMethod([]);

        $this->assertNull($this->rateFor($methodId, $this->items(1, 1)));
    }

    public function test_weight_inside_a_tier_is_offered_at_that_tier(): void
    {
        $methodId = $this->perWeightMethod(self::STANDARD_TIERS);

        $rate = $this->rateFor($methodId, $this->items(6.25, 1));

        $this->assertNotNull($rate);
        $this->assertSame(1000, $rate['cost']);
    }

    public function test_place_order_cannot_resolve_a_selected_method_the_weight_outgrew(): void
    {
        $methodId = $this->perWeightMethod(self::STANDARD_TIERS);

        // Picked at 9 kg, then the quantity went up to 18 kg.
        $this->assertNotNull(AddressHelper::resolveSelectedRate('US', null, $this->items(9, 1), $methodId));
        AddressHelper::resetRateCache();

        $items = $this->items(9, 2);
        // validateData() rejects a selection not found in this list; placeOrder() prices from resolveSelectedRate().
        $this->assertNull(AddressHelper::findRate(AddressHelper::getShippingMethods('US', null, null, $items), $methodId));
        $this->assertNull(AddressHelper::resolveSelectedRate('US', null, $items, $methodId));
    }

    private function perWeightMethod(array $tiers): int
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);

        return (int) (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'amount'   => 0,
            'settings' => ['configure_rate' => 'per_weight', 'weight_tiers' => $tiers],
        ])->id;
    }

    private function items(float $weightKg, int $quantity): array
    {
        $product = (new ProductFactory())
            ->withVariantAttributes(['other_info' => ['weight' => $weightKg, 'weight_unit' => 'kg']])
            ->create();

        return [[
            'post_id'          => (int) $product->ID,
            'object_id'        => (int) $product->variants->first()->id,
            'quantity'         => $quantity,
            'unit_price'       => 1000,
            'line_total'       => 1000 * $quantity,
            'discount_total'   => 0,
            'fulfillment_type' => 'physical',
        ]];
    }

    private function rateFor(int $methodId, array $items): ?array
    {
        $rates = (new ManualCarrier(new ManualCarrierSettings()))->getRates(['country' => 'US', 'items' => $items]);

        return AddressHelper::findRate($rates, $methodId);
    }
}
