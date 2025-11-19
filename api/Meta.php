<?php

namespace FluentCart\Api;

use FluentCart\App\Models\ProductMeta;

class Meta
{

    /**
     *
     * @param $productId
     * @return array
     */
    public static function getProductGallery($productId)
    {

        $meta = ProductMeta::query()->where('object_id', $productId)->where('meta_key', 'product_gallery')->first();

        return empty($meta) ? [] : $meta->meta_value;
    }

    /**
     * @param $productId
     * @param $data
     * @return mixed
     */
    public static function updateProductGallery($productId, $data)
    {
        $meta = ProductMeta::query()->where('object_id', $productId)->where('meta_key', 'product_gallery')->first();

        if (empty($meta)) {
            ProductMeta::create([
                'object_id'   => $productId,
                'object_type' => 'product_variant_info',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $data,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => 'product_gallery',
            ]);
        } else {
            $meta->update([
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $data,
            ]);
        }

        return $meta;
    }


    /**
     *
     * @param $productId
     * @param $pic
     * @return mixed
     */
    public static function setProductThumbnail($productId, $pic)
    {
        $meta = ProductMeta::query()->where('object_id', $productId)->where('meta_key', 'product_thumbnail')->first();

        if (empty($meta)) {
            ProductMeta::create([
                'object_id'   => $productId,
                'object_type' => 'product_variant_info',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => 'product_thumbnail',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $pic,
            ]);
        } else {
            $meta->update([
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $pic,
            ]);
        }

        return $meta;
    }


    /**
     *
     * @param $productId
     * @return string
     */
    public static function getProductThumbnail($productId)
    {

        $meta = ProductMeta::query()->where('object_id', $productId)->where('meta_key', 'product_thumbnail')->first();

        return empty($meta) ? '' : $meta->meta_value;
    }


    public static function getProductVariationMedia($varId)
    {

        $obType = 'product_variant_info';

        return ProductMeta::where('object_id', $varId)->where('object_type', $obType)->where('meta_key', 'product_thumbnail')->first();
    }

    public static function saveVariationMedia($varId, $val)
    {

        $obType = 'product_variant_info';

        return ProductMeta::create([
            'object_id'   => $varId,
            'object_type' => $obType,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'    => 'product_thumbnail',
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'  => $val,
        ]);
    }

    public static function deleteVariationMedia($varId) {

        $obType = 'product_variant_info';

        return ProductMeta::query()->where('object_id', $varId)->where('object_type', $obType)->delete();
    }
}
