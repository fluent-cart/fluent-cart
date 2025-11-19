<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\OrderMeta;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class OrderMetaResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return OrderMeta::query();
    }

    /**
     * Retrieve order meta based on the provided parameters.
     *
     * @param array $params Optional. Additional parameters for data retrieval.
     *        [
     *          'order_id' => (int) Required. The ID of the order to filter the data.
     *        ]
     *
     */
    public static function get(array $params = [])
    {
        return static::getQuery()->where('order_id', Arr::get($params, 'order_id'))->get();
    }

    /**
     * Find order meta data based on the provided order ID and params.
     * 
     * @param int   $id     Required. The ID of the order to find the meta data.
     * @param array $params Optional. Additional parameters for finding order meta data.
     *        [
     *          'meta_key' => (string) Required. The key of the order meta data to retrieve.
     *          'def' => (mixed) Optional.  Default value to return if order meta is not found.
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        $orderMeta = static::getQuery()->where('order_id', $id)->where('meta_key', Arr::get($params, 'meta_key'))->first();

        if (empty($orderMeta)) {

            return Arr::get($params, 'def', '');
        }

        return $orderMeta->meta_value;
    }

    /**
     * Create order meta data with the provided information.
     *
     * @param array $data   Required. Array containing the necessary parameters for data creation.
     *        [
     *              // Include required parameters for creating the data.
     *        ]
     * @param array $params Optional. Additional parameters for data creation.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     * 
     */
    public static function create($data, $params = [])
    {
//        if(is_array($data['meta_value']) || is_object($data['meta_value'])){
//            $data['meta_value'] = $data['meta_value'];
//        }

        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to create.', 'fluent-cart')]
        ]);
    }

    /**
     * Update order meta data with the provided information.
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        [
     *              // Include required parameters for updating the data.
     *        ]
     * @param int   $id     Required. The ID of the order associated with the data to update.
     * @param array $params Optional. Additional parameters for order meta update.
     *        [
     *            'meta_key' => (string) Required. The key of the meta data to update.
     *        ]
     * 
     */
    public static function update($data, $id, $params = [])
    {
        $existingMeta = static::getQuery()->where('order_id', $id)->where('meta_key', Arr::get($params, 'meta_key'))->first();

        if ($existingMeta) {
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            $existingMeta->update(['meta_value' => $data]);

            return static::makeSuccessResponse(
                $existingMeta,
                __('Updated successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update', 'fluent-cart')]
        ]);
    }

    /**
     * Delete an order meta based on the given ID and parameters.
     *
     * @param int $id Required. The ID of the order meta.
     * @param array $params Optional. Additional parameters for order meta deletion.
     *
     */
    public static function delete($id, $params = [])
    {
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please use a valid ID!', 'fluent-cart')]
            ]);
        }

        $orderMeta = static::getQuery()->where('order_id', $id)->where('meta_key', Arr::get($params, 'meta_key'))->first();

        $metaKey = str_replace('_', ' ', Arr::get($params, 'meta_key'));

        if ($orderMeta) {
            if ($orderMeta->delete()) {
                static::makeSuccessResponse('', sprintf(
                    /* translators: %s is the meta key */
                    __("%s successfully deleted.", 'fluent-cart'), $metaKey));
            }

            return static::makeErrorResponse([
                ['code' => 400, 'message' => sprintf(
                    /* translators: %s is the meta key */
                    __('%s deletion failed!', 'fluent-cart'), $metaKey)]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => sprintf(
                /* translators: %s is the meta key */
                __('%s not found in database, failed to remove.', 'fluent-cart'), $metaKey)]
        ]);
    }

    public static function bulkDeleteByOrderIds($ids, $params=[])
    {
        return static::getQuery()->whereIn('order_id', $ids)->delete();
    }
}
