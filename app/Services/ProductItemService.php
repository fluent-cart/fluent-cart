<?php

namespace FluentCart\App\Services;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class ProductItemService
{
    /**
     * Return product and variation item (custom or normal)
     *
     * @param array $data
     * @return object|null
     */
    public static function getItem(array $data)
    {
        $orderId     = Arr::get($data, 'order_id');
        $variationId = Arr::get($data, 'variation_id');
        $productId   = Arr::get($data, 'product_id');

        if (!$orderId || !$productId || !$variationId) {
            return null;
        }

        $orderItem = OrderItem::query()
            ->where('order_id', $orderId)
            ->where('post_id', $productId)
            ->where('object_id', $variationId)
            ->first();

        $isCustom = $orderItem
            ? in_array(
                strtolower((string) $orderItem->is_custom),
                ['1', 'true'],
                true
            )
            : false;

        // Custom item from external source
        if ($orderItem && $isCustom) {
            $product = (object) [
                'ID' => $productId,
                'post_title' => Arr::get($orderItem, 'post_title', ''),
            ];

            $variation = $orderItem;
            $variation->id = $orderItem->object_id;

            [$product, $variation] = apply_filters(
                'fluent_cart/payment/validate_custom_item',
                [$product, $variation],
                $data
            );

            if (!is_object($product) || !is_object($variation)) {
                return null;
            }

            $variation = CartHelper::normalizeCustomFields($variation);

        }
        else {
            $variation = ProductVariation::query()->find($variationId);
            $variation = apply_filters('fluent_cart/cart/item_modify', $variation, [
                'item_id'     => $variationId,
                'quantity'    => $orderItem ? $orderItem->quantity : 0,
            ]);
            $product = Product::query()->find($productId);
        }
        
        if (!$product || !$variation) {
            return null;
        }

        return (object) [
            'product'   => $product,
            'variation' => $variation,
            'is_custom' => $isCustom,
        ];
    }
}
