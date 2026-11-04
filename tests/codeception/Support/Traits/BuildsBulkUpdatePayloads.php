<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Product;

/**
 * Payload builder for POST /fluent-cart/v2/products/bulk-update.
 *
 * Every conditional-validation case on that endpoint sends the same product
 * envelope — ID, title, status, detail, one variant row — and varies only the
 * variant's other_info. Building it once keeps each test to the one thing its
 * name claims to check.
 */
trait BuildsBulkUpdatePayloads
{
    /**
     * One product, one variant, with the given other_info.
     *
     * @param string $title Used for both the post and the variation title.
     * @param array $otherInfo The variant's other_info — the subject of these tests.
     * @return array
     */
    protected function bulkProductPayload(Product $product, string $title, array $otherInfo): array
    {
        return [
            'ID'          => (int) $product->ID,
            'post_title'  => $title,
            'post_status' => 'publish',
            'detail'      => ['variation_type' => 'simple', 'fulfillment_type' => 'digital'],
            'variants'    => [[
                'id'              => (int) $product->variants->first()->id,
                'variation_title' => $title,
                'item_price'      => 1000,
                'other_info'      => $otherInfo,
            ]],
        ];
    }
}
