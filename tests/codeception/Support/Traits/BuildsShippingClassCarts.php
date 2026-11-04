<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\ShippingMethod;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

/**
 * Shipping classes, zone methods and class-tagged cart lines for the class surcharge tests.
 */
trait BuildsShippingClassCarts
{
    protected function shippingClass(string $type, float $cost, bool $perItem): int
    {
        return (int) (new ShippingClassFactory())->create([
            'type'     => $type,
            'cost'     => $cost,
            'per_item' => $perItem ? 1 : 0,
        ])->id;
    }

    protected function perOrderMethod(float $amount, string $classAggregation): int
    {
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);

        return (int) (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'amount'   => $amount,
            'settings' => ['configure_rate' => 'per_order', 'class_aggregation' => $classAggregation],
        ])->id;
    }

    protected function classLine(int $classId, int $quantity, int $unitPrice = 1000): array
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['shipping_class' => $classId]])
            ->create();

        return [
            'post_id'          => (int) $product->ID,
            'object_id'        => (int) $product->variants->first()->id,
            'quantity'         => $quantity,
            'unit_price'       => $unitPrice,
            'line_total'       => $unitPrice * $quantity,
            'discount_total'   => 0,
            'fulfillment_type' => 'physical',
        ];
    }

    /**
     * Itemized result from the zone path ('zone') or the shipping-profile path ('profile').
     */
    protected function classShipping(string $path, int $methodId, array $items): array
    {
        if ($path === 'profile') {
            return CartHelper::calculateShippingByProfile($methodId, $items, 'US', null, 'items');
        }

        return CartHelper::calculateShippingMethodCharge(ShippingMethod::query()->find($methodId), $items, 'items');
    }

    protected function itemizedShippingSum(array $items): int
    {
        $sum = 0;
        foreach ($items as $item) {
            $sum += (int) $item['shipping_charge'] + (int) $item['itemwise_shipping_charge'];
        }

        return $sum;
    }
}
