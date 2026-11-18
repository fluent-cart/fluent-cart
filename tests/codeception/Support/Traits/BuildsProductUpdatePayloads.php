<?php

namespace Tests\Support\Traits;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;

/**
 * Multi-step setup helpers for the products update REST surface.
 *
 * The product update payload (POST /fluent-cart/v2/products/{postId}/pricing) is verbose:
 * post_title + post_status + detail (id/post_id/variation_type/fulfillment_type/min_price/
 * max_price/manage_stock/other_info) + variants[] (each with id/post_id/variation_title/
 * item_price/compare_price/manage_stock/total_stock/available/stock_status/payment_type/
 * fulfillment_type/other_info.payment_type ...). Inlining that in 20+ Cest methods is
 * unreadable. Build it once here, then let each method override only the fields the
 * spec sentence cares about.
 */
trait BuildsProductUpdatePayloads
{
    /**
     * Build a minimal valid update payload for the given product. Variants are
     * derived from the persisted ProductVariation rows so the update preserves
     * the caller's prior fixture state.
     *
     * @param  array $overrides Top-level overrides (post_title, post_status, detail.*).
     * @param  array $variantOverrides Per-variant overrides (item_price, compare_price, ...).
     */
    protected function buildUpdatePayload(Product $product, array $overrides = [], array $variantOverrides = []): array
    {
        $detail = $product->detail;
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();

        $variantPayload = [];
        foreach ($variants as $i => $variant) {
            $variantPayload[] = array_merge([
                'id'               => (int) $variant->id,
                'rowId'            => (int) $variant->id,
                'post_id'          => (int) $product->ID,
                'variation_title'  => (string) $variant->variation_title,
                'serial_index'     => (int) $variant->serial_index,
                'item_price'       => $this->centsToDecimal((int) $variant->item_price),
                'compare_price'    => $this->centsToDecimal((int) $variant->compare_price),
                'item_cost'        => $this->centsToDecimal((int) $variant->item_cost),
                'manage_cost'      => 'no',
                'manage_stock'     => (int) $variant->manage_stock,
                'total_stock'     => (int) $variant->total_stock,
                'available'       => (int) $variant->available,
                'stock_status'    => (string) $variant->stock_status,
                'fulfillment_type' => (string) $variant->fulfillment_type,
                'other_info'      => [
                    'payment_type'    => (string) ($variant->other_info['payment_type'] ?? 'onetime'),
                    'description'     => '',
                    'times'           => '',
                    'trial_days'      => '',
                    'repeat_interval' => '',
                    'billing_summary' => '',
                    'manage_setup_fee' => 'no',
                    'signup_fee_name' => '',
                    'signup_fee'      => 0,
                ],
            ], $variantOverrides[$i] ?? $variantOverrides);
        }

        return array_replace_recursive([
            'ID'          => (int) $product->ID,
            'post_title'  => (string) $product->post_title,
            'post_status' => (string) ($product->post_status ?: 'publish'),
            'detail'      => [
                'id'                 => (int) $detail->id,
                'post_id'            => (int) $product->ID,
                'fulfillment_type'   => (string) $detail->fulfillment_type,
                'variation_type'     => (string) $detail->variation_type,
                'min_price'          => (int) $detail->min_price,
                'max_price'          => (int) $detail->max_price,
                'stock_availability' => (string) ($detail->stock_availability ?: 'in-stock'),
                'manage_stock'       => (int) $detail->manage_stock,
                'manage_downloadable' => (int) $detail->manage_downloadable,
                'other_info'         => is_array($detail->other_info ?? null) ? $detail->other_info : [],
            ],
            'variants'    => $variantPayload,
        ], $overrides);
    }

    protected function buildBulkSkuTransferPayload(Product $product, string $sku): array
    {
        $payload = $this->buildUpdatePayload($product, [], [['sku' => '', 'item_price' => 1500]]);
        $copy = $payload['variants'][0];
        unset($copy['id'], $copy['rowId']);
        $copy['sku'] = $sku;
        $copy['variation_title'] = 'New recipient';
        $payload['variants'][] = $copy;

        return $payload;
    }

    private function centsToDecimal(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
