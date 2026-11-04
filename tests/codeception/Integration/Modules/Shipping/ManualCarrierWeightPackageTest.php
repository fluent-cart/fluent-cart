<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\ShippingMethod;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsWeightRateCarts;

/**
 * A per-weight rate counts a package's weight once per box, the way BoxPacker packs for carriers.
 */
class ManualCarrierWeightPackageTest extends DatabaseTestCase
{
    use BuildsWeightRateCarts;

    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        fluent_cart_update_option('shipping_packages', [
            $this->weightPackage('tshirt-box', 0.25, 0),
            $this->weightPackage('small-box', 0.25, 2),
            $this->weightPackage('heavy-box', 0.5, 0),
        ]);
    }

    public function tearDown(): void
    {
        fluent_cart_update_option('shipping_packages', []);
        AddressHelper::resetRateCache();
        parent::tearDown();
    }

    public function test_one_box_adds_its_weight_once(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 3, 'cost' => 8],
            ['min' => 3, 'max' => 0, 'cost' => 12],
        ]);

        // 3 x 0.9 kg + one 0.25 kg box = 2.95 kg, not 3 x 1.15 = 3.45 kg.
        $rate = $this->weightRateFor($methodId, [$this->weightLine(0.9, 3, 'tshirt-box')]);

        $this->assertNotNull($rate);
        $this->assertSame(800, $rate['cost']);
    }

    public function test_profile_path_adds_box_weight_once(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 3, 'cost' => 8],
            ['min' => 3, 'max' => 0, 'cost' => 12],
        ]);

        $amount = CartHelper::calculateShippingByProfile($methodId, [$this->weightLine(0.9, 3, 'tshirt-box')], 'US');

        $this->assertSame(800, (int) $amount);
    }

    public function test_contents_over_box_max_weight_count_each_box(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 2.8, 'cost' => 5],
            ['min' => 2.8, 'max' => 3, 'cost' => 7],
            ['min' => 3, 'max' => 0, 'cost' => 9],
        ]);

        // 2.4 kg in 2 kg boxes = 2 boxes: 2.4 + 2 x 0.25 = 2.9 kg. One tare is 2.65, three 3.15.
        $rate = $this->weightRateFor($methodId, [$this->weightLine(0.8, 3, 'small-box')]);

        $this->assertNotNull($rate);
        $this->assertSame(700, $rate['cost']);
    }

    public function test_different_packages_add_each_box_once(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 4.4, 'cost' => 3],
            ['min' => 4.4, 'max' => 4.6, 'cost' => 5],
            ['min' => 4.6, 'max' => 0, 'cost' => 9],
        ]);

        // 2 x 0.9 + 0.25 and 2 x 1 + 0.5 = 4.55 kg; per unit it was 5.3 kg.
        $rate = $this->weightRateFor($methodId, [$this->weightLine(0.9, 2, 'tshirt-box'), $this->weightLine(1, 2, 'heavy-box')]);

        $this->assertNotNull($rate);
        $this->assertSame(500, $rate['cost']);
    }

    public function test_items_without_a_package_add_no_box_weight(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 2.7, 'cost' => 5],
            ['min' => 2.7, 'max' => 0, 'cost' => 9],
        ]);

        $rate = $this->weightRateFor($methodId, [$this->weightLine(0.9, 3, '')]);

        $this->assertNotNull($rate);
        $this->assertSame(500, $rate['cost']);
    }

    public function test_box_weight_pushing_past_every_tier_hides_the_method(): void
    {
        $methodId = $this->perWeightMethod([['min' => 0, 'max' => 2.8, 'cost' => 5]]);
        $items = [$this->weightLine(0.9, 3, 'tshirt-box')];

        $this->assertNull($this->weightRateFor($methodId, $items), '2.95 kg is above the 2.8 kg tier');
        $this->assertFalse(CartHelper::shippingMethodCoversItems(ShippingMethod::find($methodId), $items));
    }

    public function test_an_item_without_a_package_shares_the_default_box(): void
    {
        $methodId = $this->perWeightMethod([
            ['min' => 0, 'max' => 2.5, 'cost' => 4],
            ['min' => 2.5, 'max' => 0, 'cost' => 9],
        ]);

        fluent_cart_update_option('shipping_packages', [$this->weightPackage('default-box', 0.4, 0, true)]);

        // Both lines resolve to the default 0.4 kg box: 2 kg + one tare = 2.4 kg, not 2.8 kg.
        $rate = $this->weightRateFor($methodId, [$this->weightLine(1, 1, ''), $this->weightLine(1, 1, 'default-box')]);

        $this->assertNotNull($rate, 'the method must be offered');
        $this->assertSame(400, $rate['cost'], 'the default box weight must count once');
    }
}
