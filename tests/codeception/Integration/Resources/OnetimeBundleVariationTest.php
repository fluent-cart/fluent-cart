<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

class OnetimeBundleVariationTest extends WPTestCase
{
    public function test_onetime_variation_keeps_bundle_keys(): void
    {
        $product = (new ProductFactory())->create();

        $created = ProductVariationResource::create([
            'post_id'         => $product->ID,
            'variation_title' => 'Bundle',
            'item_price'      => 1000,
            'other_info'      => [
                'payment_type'      => 'onetime',
                'is_bundle_product' => 'yes',
                'bundle_child_ids'  => [11, 12],
            ],
        ]);

        $this->assertNotWPError($created);
        $otherInfo = ProductVariation::query()->find($created['data']->id)->other_info;
        $this->assertSame('yes', $otherInfo['is_bundle_product']);
        $this->assertSame([11, 12], $otherInfo['bundle_child_ids']);
    }

    public function test_is_bundle_item_accepts_legacy_variations_without_the_flag(): void
    {
        $this->assertTrue(Helper::isBundleItem(['bundle_child_ids' => [5]]));
        $this->assertTrue(Helper::isBundleItem(['is_bundle_product' => 'yes']));
        $this->assertFalse(Helper::isBundleItem(['is_bundle_product' => 'no', 'bundle_child_ids' => []]));
    }
}
