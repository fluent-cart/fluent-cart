<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\ProductDownloadResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Helpers;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductAdminHelper
{

    /**
     *
     * @param $details
     * @param $variants
     */
    public static function syncProduct($details, $variants)
    {
        $variationIds = [];
        $variationType = Arr::get($details, 'variation_type', '');
        $postId = Arr::get($details, 'post_id');
        $variants = Arr::except($variants, ['*']);



        foreach ($variants as $index => $variant) {
            $variant['serial_index'] = $index + 1;
            $variant['fulfillment_type'] = Arr::get(
                $variant, 'fulfillment_type', Arr::get($details, 'fulfillment_type')
            );
            $variantId = Arr::get($variant, 'id');
            if (empty($variantId)) {
                $result = ProductVariationResource::create($variant);
            } else {
                $result = ProductVariationResource::update($variant, $variantId);
            }

            $variationIds[] = Arr::get($result, 'data.id');
            $variants[$index]['id'] = Arr::get($result, 'data.id');
            if ($variationType === \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE) {
                break;
            }
        }

        self::deleteOrphanVariant($postId, $variationIds);

        ProductDownloadResource::delete(null, ['type' => 'byProduct', 'post_id' => $postId]);
        return ProductVariation::query()->where('post_id', $postId)->get();
    }

    /**
     * Syncing advance variations
     *
     * @param $srcDetails
     * @param $variations
     * @param array $variantProductDetails
     * @return mixed
     */
    public static function syncAdvanceVariations($srcDetails, $variations, array $variantProductDetails = [])
    {
        $formattedVariations = [];

        foreach ($variations as $variation) {
            if (!empty($variation['variants'])) {
                $formattedVariations[] = $variation['variants'];
            }
        }

        $variants = self::generateVariationSets($formattedVariations);

        $variantIds = [];

        $srcDetails->load('product');

        $variationTitle = $srcDetails->product->post_title;

        foreach ($variants as $index => $variant) {

            asort($variant, SORT_NUMERIC);

            $variationIdentifier = implode('_', $variant);

            $variantData = [
                'post_id'              => $srcDetails->post_id,
                'serial_index'         => $index + 1,
                'stock'                => 100,
                'item_price'           => 0,
                'fulfillment_type'     => 'physical',
                'variation_title'      => $variationTitle,
                'variation_identifier' => $variationIdentifier,
                'other_info'           => [
                    'variant' => array_values($variant),
                ],
            ];

            $exist = ProductVariation::query()->where('post_id', $srcDetails->post_id)
                ->where('variation_identifier', $variationIdentifier)
                ->first();

            if ($exist) {

                $exist->serial_index = $index + 1;
                $exist->save();

            } else {

                $exist = ProductVariation::create($variantData);
            }


            foreach ($variant as $termId) {
                $term = AttributeTerm::find($termId);

                $relation = [
                    'term_id'   => $term->id,
                    'object_id' => $exist->id,
                    'group_id'  => $term->group_id,
                ];

                AttributeRelation::firstOrCreate($relation);
            }

            $variantIds[] = $exist->id;
        }

        /*
         * Remove orphan variants
         */
        self::deleteOrphanVariant($srcDetails->post_id, $variantIds);

        return ProductVariation::query()->whereIn('id', $variantIds)->get();
    }


    /**
     *
     * @param $productId
     * @param array $childrenIdsWeWantToKeepSafe
     * @return mixed
     */
    public static function deleteOrphanVariant($productId, array $childrenIdsWeWantToKeepSafe = [])
    {
        $variations = ProductVariation::query()
            ->select('variation_title')
            ->where('post_id', $productId)
            ->whereNotIn('id', $childrenIdsWeWantToKeepSafe)
            ->get();

        $variationTitles = $variations->pluck('variation_title')
            ->join(',', __(' and ', 'fluent-cart'));

        if ($variations->count()) {
            fluent_cart_success_log(
                sprintf(
                    /* translators: %s is the number of variations */
                    __('%s Pricing deleted', 'fluent-cart'),
                    $variations->count()
                ),
                sprintf(
                /* translators: %s is the variation titles */
                    _n(
                        "%s Pricing is deleted, while product variation is changed to 'Simple'",
                        "%s Pricing's are deleted, while product variation is changed to 'Simple'",
                        $variations->count(),
                        'fluent-cart'
                    ),
                    $variationTitles
                ),
                [
                    'module_name' => 'Product',
                    'module_id'   => 0,
                    'module_type' => ProductVariation::class,
                ]
            );
        }


        return ProductVariation::query()
            ->select('id')
            ->where('post_id', $productId)
            ->whereNotIn('id', $childrenIdsWeWantToKeepSafe)
            ->delete();
    }

    public static function generateVariationSets($formattedVariations, $i = 0)
    {
        if (!isset($formattedVariations[$i])) {
            return [];
        }

        $result = [];

        /**
         * Fix: With only one variation group it does not give proper result.
         *
         */
        if ($i === 0 && count($formattedVariations) === 1 && is_array($formattedVariations[0])) {

            foreach ($formattedVariations[0] as $item) {
                $result[] = [$item];
            }

            return $result;
        }


        if ($i == count($formattedVariations) - 1) {
            return $formattedVariations[$i];
        }

        // get combinations from subsequent arrays
        $tmp = self::generateVariationSets($formattedVariations, $i + 1);


        // concat each array from tmp with each element from $arrays[$i]
        foreach ($formattedVariations[$i] as $v) {
            foreach ($tmp as $t) {
                $result[] = is_array($t) ?
                    array_merge([$v], $t) :
                    [$v, $t];
            }
        }

        return $result;
    }

    public static function getFeaturedMedia($featuredMedia): string
    {
        return !empty($featuredMedia) ? Arr::get($featuredMedia, 'url') : Vite::getAssetUrl('images/placeholder.svg');
    }
}
