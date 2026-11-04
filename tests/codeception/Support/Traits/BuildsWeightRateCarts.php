<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrier;
use FluentCart\App\Modules\Shipping\Carriers\Manual\ManualCarrierSettings;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

/**
 * Packages, per-weight methods and physical cart lines for the weight-rate tests.
 */
trait BuildsWeightRateCarts
{
    protected function weightPackage(string $slug, float $weight, float $maxWeight, bool $isDefault = false): array
    {
        return [
            'slug'           => $slug,
            'name'           => $slug,
            'type'           => 'box',
            'length'         => 20,
            'width'          => 15,
            'height'         => 10,
            'dimension_unit' => 'cm',
            'weight'         => $weight,
            'weight_unit'    => 'kg',
            'max_weight'     => $maxWeight,
            'is_default'     => $isDefault,
        ];
    }

    protected function perWeightMethod(array $tiers): int
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);

        return (int) (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'amount'   => 0,
            'settings' => ['configure_rate' => 'per_weight', 'weight_tiers' => $tiers],
        ])->id;
    }

    protected function weightLine(float $weightKg, int $quantity, string $packageSlug): array
    {
        $product = (new ProductFactory())
            ->withVariantAttributes(['other_info' => [
                'weight'       => $weightKg,
                'weight_unit'  => 'kg',
                'package_slug' => $packageSlug,
            ]])
            ->create();

        return [
            'post_id'          => (int) $product->ID,
            'object_id'        => (int) $product->variants->first()->id,
            'quantity'         => $quantity,
            'unit_price'       => 1000,
            'line_total'       => 1000 * $quantity,
            'discount_total'   => 0,
            'fulfillment_type' => 'physical',
        ];
    }

    protected function weightRateFor(int $methodId, array $items): ?array
    {
        $rates = (new ManualCarrier(new ManualCarrierSettings()))->getRates(['country' => 'US', 'items' => $items]);

        return AddressHelper::findRate($rates, $methodId);
    }
}
