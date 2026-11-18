<?php

namespace Tests\Support\Traits;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\Factory\ProductFactory;

/**
 * One-time bundle fixtures for the order-item tests. Older one-time bundle
 * variations kept bundle_child_ids but lost is_bundle_product on create.
 */
trait BuildsOnetimeBundles
{
    /**
     * @return int[] Two child variation ids.
     */
    protected function createBundleChildVariations(): array
    {
        return [
            (int) (new ProductFactory())->create(['post_title' => 'Child A'])->variants->first()->id,
            (int) (new ProductFactory())->create(['post_title' => 'Child B'])->variants->first()->id,
        ];
    }

    protected function createUnflaggedOnetimeBundle(array $childIds): ProductVariation
    {
        $bundle = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->withVariantAttributes(['other_info' => [
                'payment_type'     => 'onetime',
                'bundle_child_ids' => $childIds,
            ]])
            ->create(['post_title' => 'Bundle']);

        return $bundle->variants->first();
    }

    /**
     * The item shape CheckoutProcessor receives from the cart, with child_variants loaded.
     */
    protected function bundleCheckoutItem(ProductVariation $variation): array
    {
        $loaded = Helper::loadBundleChild([$variation->fresh()->toArray()], ['*'])[0];

        return [
            'object_id'        => $variation->id,
            'post_id'          => $variation->post_id,
            'quantity'         => 1,
            'unit_price'       => 5000,
            'price'            => 5000,
            'subtotal'         => 5000,
            'line_total'       => 5000,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'manual_discount'  => 0,
            'tax_amount'       => 0,
            'shipping_charge'  => 0,
            'cost'             => 0,
            'post_title'       => 'Bundle',
            'title'            => $variation->variation_title,
            'fulfillment_type' => 'digital',
            'other_info'       => $loaded['other_info'],
            'child_variants'   => $loaded['child_variants'],
        ];
    }
}
