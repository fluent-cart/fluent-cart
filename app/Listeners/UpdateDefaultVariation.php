<?php

namespace FluentCart\App\Listeners;

use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Arr;

class UpdateDefaultVariation
{
    /**
     * @param $event StockChanged
     */

    public static function handle($event)
    {
        $postIds = $event->postIds;

        $productsDetails = ProductDetail::query()->whereIn('post_id', $postIds)->get()->keyBy('post_id');
        $variations = ProductVariation::query()->whereIn('post_id', $postIds)->get()->keyBy('id');
        $groupedVariation = $variations->groupBy('post_id');


        foreach ($postIds as $id => $postId) {

            if (!isset($productsDetails[$postId])) {
                continue;
            }
            $getProductDetail = $productsDetails[$postId];

            $productDetail = [];

            if (!empty($getProductDetail) && $getProductDetail->manage_stock == 1) {
                $productDetail['stock_availability'] = ($getProductDetail->variants()->where('stock_status', Helper::IN_STOCK)->count() >= 1) ? Helper::IN_STOCK : Helper::OUT_OF_STOCK;
            }

            if (!empty($getProductDetail['default_variation_id'])) {
                $defaultVariationId = self::checkDefaultVariationStockStatus(
                    [
                        'default_variation_id' => $getProductDetail->default_variation_id,
                        'post_id'              => $postId,
                        'grouped_variation'    => $groupedVariation[$postId] ?? []
                    ]
                );

                if ($defaultVariationId) {
                    $productDetail['default_variation_id'] = $defaultVariationId;
                }
            }


            if (!empty($productDetail)) {
                $getProductDetail->fill($productDetail)->save();
            }
        }
    }

    private static function checkDefaultVariationStockStatus($params = [])
    {

        $grouped_variation = Arr::get($params, 'grouped_variation');
        $countStock = $grouped_variation->where('stock_status', Helper::IN_STOCK)->where('id', Arr::get($params, 'default_variation_id'))->count();

        if ($countStock < 1) {
            $productVariation = $grouped_variation->where('stock_status', Helper::IN_STOCK)->first();
            if (isset($productVariation->id)) {
                return $productVariation->id;
            }
        }
        return false;
    }

}