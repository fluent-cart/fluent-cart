<?php

namespace FluentCart\App\Listeners;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Collection;

class UpdateDefaultVariation
{
    /**
     * @param $event \FluentCart\App\Events\ProductVariationsChanged
     */
    public static function handle($event)
    {
        self::updateForProducts((array) $event->postIds);
    }

    /**
     * Set each product's default_variation_id to the FIRST variant by
     * serial_index — the first combination in the merchant's order. Always
     * recomputed on a variant-set change (generate / reorder / add / update /
     * remove), overriding any prior value including a manual pick. Intentionally
     * ignores stock_status and item_status: the default is purely the first
     * combination by order, in stock or not, active or not. null only when the
     * product has no variants.
     */
    public static function updateForProducts(array $postIds)
    {
        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        if (empty($postIds)) {
            return;
        }

        $details = ProductDetail::query()->whereIn('post_id', $postIds)->get()->keyBy('post_id');
        // serial_index ASC so first() is the first combination in the merchant's
        // order — the canonical variant order used across the product editor.
        $grouped = ProductVariation::query()
            ->whereIn('post_id', $postIds)
            ->orderBy('serial_index', 'asc')
            ->get()
            ->groupBy('post_id');

        foreach ($details as $postId => $detail) {
            $variants = $grouped->get($postId) ?: new Collection();
            $first = $variants->first();
            $newDefaultId = $first ? (int) $first->id : null;

            if ((int) $detail->default_variation_id !== (int) $newDefaultId) {
                $detail->default_variation_id = $newDefaultId;
                $detail->save();
            }
        }
    }
}
