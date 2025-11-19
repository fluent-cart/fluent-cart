<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\ProductMeta;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductMetaResource extends BaseResourceApi
{
    private static $metaKey = 'product_thumbnail';

    private static $objectType = 'product_variant_info';

    public static function getQuery(): Builder
    {
        return ProductMeta::query();
    }

    public static function get(array $params = [])
    {

    }

    /**
     * Find metadata by specified product ID and given parameters.
     *
     * @param int   $productId Required. The ID of the product to find metadata.
     * @param array $params    Optional. Additional parameters for the metadata retrieval.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function find($productId, $params = [])
    {
        return static::getQuery()->where('object_id', $productId)->where('object_type', static::$objectType)->where('meta_key', static::$metaKey)->first();
    }

    /**
     * Create a new product meta with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters for file creation.
     *        [
     *              'object_id'    => (int)    Required. The id of the product,
     *              'object_type'  => (string) Required. The type of the product meta,
     *              'meta_value'   => (string) Required. The value of the product meta
     *                  [
     *                      'id'        => (int) Required. The id of the thumbnail,
     *                      'url'       => (string) Required. The url of the thumbnail,
     *                      'title'     => (string) Required. The title of the thumbnail,
     *                  ]
     *              'meta_key'     => (string) Required. The key of the product meta,
     *        ]
     * @param array $params Optional. Additional parameters for meta creation.
     *        [
     *              'product_id' => (int) Required. The id of the product,
     *        ]
     * 
     */
    public static function create($data, $params = [])
    {
        $productId = Arr::get($params, 'product_id');
        
        $isCreated = static::getQuery()->create([
            'object_id'   => $productId,
            'object_type' => 'product_variant_info',
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'  => $data,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'    => 'product_thumbnail',
        ]);

        if($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Thumbnail set successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Failed to set thumbnail!', 'fluent-cart') ]
        ]);
    }

    /**
     * Update product meta with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters for file creation.
     *        [
     *              'meta_value'   => (string) Required. The value of the product meta
     *                  [
     *                      'id'        => (int) Required. The id of the thumbnail,
     *                      'url'       => (string) Required. The url of the thumbnail,
     *                      'title'     => (string) Required. The title of the thumbnail,
     *                  ]
     *        ]
     * @param int   $productId     Required. The ID of the product meta.
     * @param array $params        Optional. Additional parameters for meta creation.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     * 
     */
    public static function update($data, $productId, $params = [])
    {
        //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $isUpdated = ProductMetaResource::find($productId)->update(['meta_value' => $data]);

        if($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Thumbnail set successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Failed to set thumbnail!', 'fluent-cart') ]
        ]);
    }

    /**
     * Delete product meta with the given ID and parameters.
     *
     * @param int   $id     Required. The ID of the product meta.
     * @param array $params Optional. Additional parameters.
     *        [
     *          // Include optional parameters, if any.
     *        ]
     * 
     */
    public static function delete($id, $params = [])
    {
        $productMeta = ProductMetaResource::find($id);

        if (!empty($productMeta)) {
            $productMeta->delete();
            return static::makeSuccessResponse(
                '',
                __('Thumbnail has been deleted successfully!', 'fluent-cart')
            );
        }
        
        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Failed to delete!', 'fluent-cart') ]
        ]);
    }

    /**
     * Find metadata by specified ids and given parameters.
     *
     * @param array $ids     Required. The id of the product variants to find metadata.
     * @param array $params  Optional. Additional parameters for the metadata retrieval.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     */
    public static function findByIds($ids, $params = [])
    {
        return static::getQuery()
            ->select('object_id', 'meta_value')
            ->where('object_type', static::$objectType)
            ->where('meta_key', static::$metaKey)
            ->whereIn('object_id', $ids)
            ->get();
    }
}