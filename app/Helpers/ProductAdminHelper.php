<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\ProductDownloadResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Helpers;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

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

        // Guard: no terms selected → nothing to generate. Calling deleteOrphanVariant
        // with an empty keep-list would match every row and wipe all variants for
        // the product. Return empty rather than destroying existing data.
        if (empty($variants)) {
            return new Collection();
        }

        $srcDetails->load('product');

        // Preload every AttributeTerm referenced in this sync — one SELECT instead
        // of one per term per variant (avoids N+1 on the term lookup).
        $allTermIds = array_unique(array_merge(...array_map('array_values', $variants)));
        $termsList  = AttributeTerm::query()->whereIn('id', $allTermIds)->get();
        $termsMap   = [];
        foreach ($termsList as $term) {
            $termsMap[$term->id] = $term;
        }

        $variantIds   = [];
        $newRelations = [];

        $db = ProductVariation::query()->getConnection();
        try {
            $db->beginTransaction();

            foreach ($variants as $index => $variant) {
                asort($variant, SORT_NUMERIC);

                $variationIdentifier = implode('_', $variant);

                $variantData = [
                    'post_id'              => $srcDetails->post_id,
                    'serial_index'         => $index + 1,
                    'stock'                => 100,
                    'item_price'           => 0,
                    'fulfillment_type'     => 'physical',
                    'variation_title'      => $srcDetails->product->post_title,
                    'variation_identifier' => $variationIdentifier,
                    'other_info'           => [
                        'variant' => array_values($variant),
                    ],
                ];

                $exist = ProductVariation::query()
                    ->where('post_id', $srcDetails->post_id)
                    ->where('variation_identifier', $variationIdentifier)
                    ->first();

                if ($exist) {
                    $exist->serial_index = $index + 1;
                    $exist->save();
                } else {
                    $exist = ProductVariation::create($variantData);
                }

                $variantIds[] = $exist->id;

                foreach ($variant as $termId) {
                    if (!isset($termsMap[$termId])) {
                        continue;
                    }
                    $term           = $termsMap[$termId];
                    $newRelations[] = [
                        'term_id'   => $term->id,
                        'object_id' => $exist->id,
                        'group_id'  => $term->group_id,
                    ];
                }
            }

            // Bulk-insert relations — fetch existing keys first (one query) then
            // insert only the missing ones in 100-row chunks instead of one
            // firstOrCreate per term per variant.
            if (!empty($newRelations)) {
                $existingKeys = [];
                $existing     = AttributeRelation::query()->whereIn('object_id', $variantIds)->get();
                foreach ($existing as $rel) {
                    $existingKeys[$rel->object_id . ':' . $rel->term_id] = true;
                }
                $toInsert = array_values(array_filter($newRelations, function ($r) use ($existingKeys) {
                    return !isset($existingKeys[$r['object_id'] . ':' . $r['term_id']]);
                }));
                foreach (array_chunk($toInsert, 100) as $chunk) {
                    AttributeRelation::query()->insert($chunk);
                }
            }

            self::deleteOrphanVariant($srcDetails->post_id, $variantIds);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return ProductVariation::query()->whereIn('id', $variantIds)->get();
    }


    /**
     * Build a bounded, human-readable summary of variation titles for activity
     * logs. When the count is within $limit the full list is returned; only when
     * there are MORE than $limit titles do we sample the first $limit and append
     * "and N more", so large combination sets don't bloat the log content.
     *
     * @param array $titles
     * @param int $limit
     * @return string
     */
    public static function summarizeVariationTitles(array $titles, $limit = 5)
    {
        $titles = array_values(array_filter($titles, function ($title) {
            return $title !== null && $title !== '';
        }));

        $total = count($titles);

        if ($total <= $limit) {
            return implode(', ', $titles);
        }

        return sprintf(
            /* translators: %1$s: sample of variation titles, %2$d: number of remaining variations not listed */
            __('%1$s and %2$d more', 'fluent-cart'),
            implode(', ', array_slice($titles, 0, $limit)),
            $total - $limit
        );
    }

    /**
     *
     * @param $productId
     * @param array $childrenIdsWeWantToKeepSafe
     * @param string $reason Human-readable cause for the deletion, shown in the
     *                       log content (e.g. "the variation type was changed to
     *                       'Simple'"). Defaults to a generic, always-true phrase
     *                       so the message never claims the wrong cause — this is
     *                       called from several flows (Simple switch, option/group
     *                       changes, regeneration), not only the Simple switch.
     * @return mixed
     */
    public static function deleteOrphanVariant($productId, array $childrenIdsWeWantToKeepSafe = [], $reason = '')
    {
        $orphans = ProductVariation::query()
            ->select(['id', 'variation_title'])
            ->where('post_id', $productId)
            ->whereNotIn('id', $childrenIdsWeWantToKeepSafe)
            ->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        $orphanIds = $orphans->pluck('id')->toArray();

        // Summarize rather than join every title — a product can have hundreds
        // of combinations and the full list bloats the activity-log content.
        // Below the cap the full list is kept; above it we sample + "and N more".
        $variationTitles = static::summarizeVariationTitles(
            $orphans->pluck('variation_title')->all()
        );

        if ($reason === '') {
            $reason = __('the product variations were updated', 'fluent-cart');
        }

        fluent_cart_success_log(
            sprintf(
                /* translators: %1$s: number of variations deleted */
                __('%1$s Pricing deleted', 'fluent-cart'),
                $orphans->count()
            ),
            sprintf(
                /* translators: %1$s: variation titles, %2$s: reason the pricings were deleted */
                _n(
                    '%1$s Pricing is deleted, while %2$s',
                    "%1\$s Pricing's are deleted, while %2\$s",
                    $orphans->count(),
                    'fluent-cart'
                ),
                $variationTitles,
                $reason
            ),
            [
                'module_name' => 'Product',
                'module_id'   => 0,
                'module_type' => ProductVariation::class,
            ]
        );

        // Bulk query-builder deletes bypass the ProductVariation::boot() deleting
        // event, so neither $model->attrMap()->delete() nor deleteVariationMedia()
        // fires. Explicitly purge both tables for every orphaned variation before
        // deleting the variations themselves to prevent orphaned rows.
        AttributeRelation::query()->whereIn('object_id', $orphanIds)->delete();
        ProductMeta::query()->where('object_type', 'product_variant_info')->whereIn('object_id', $orphanIds)->delete();

        $orphanIds = ProductVariation::query()
            ->select('id')
            ->where('post_id', $productId)
            ->whereNotIn('id', $childrenIdsWeWantToKeepSafe)
            ->pluck('id')
            ->toArray();

        // Attribute relations table only exists when pro is active — guard before querying.
        if ($orphanIds && \FluentCart\App\App::isProActive()) {
            AttributeRelation::query()->whereIn('object_id', $orphanIds)->delete();
        }

        return ProductVariation::query()
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
